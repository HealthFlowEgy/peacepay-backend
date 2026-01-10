<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\OpenDisputeRequest;
use App\Http\Requests\DisputeResponseRequest;
use App\Http\Resources\DisputeResource;
use App\Http\Resources\DisputeCollection;
use App\Services\DisputeService;
use App\Services\PeaceLinkService;
use App\Services\WalletService;
use App\Services\NotificationService;
use App\Enums\DisputeStatus;
use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DisputeController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputeService,
        private readonly PeaceLinkService $peaceLinkService,
        private readonly WalletService $walletService,
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Get user's disputes
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:open,under_review,resolved,escalated,closed',
            'per_page' => 'sometimes|integer|min:10|max:50',
        ]);

        $user = $request->user();
        $disputes = $this->disputeService->getUserDisputes(
            $user,
            $validated['status'] ?? null,
            $validated['per_page'] ?? 20
        );

        return response()->json([
            'success' => true,
            'data' => new DisputeCollection($disputes),
        ]);
    }

    /**
     * Get single dispute details
     * 
     * @param Request $request
     * @param string $disputeId
     * @return JsonResponse
     */
    public function show(Request $request, string $disputeId): JsonResponse
    {
        $user = $request->user();
        $dispute = $this->disputeService->getDispute($user, $disputeId);

        if (!$dispute) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع غير موجود',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'dispute' => new DisputeResource($dispute),
            ]
        ]);
    }

    /**
     * Open new dispute on a PeaceLink
     * 
     * @param OpenDisputeRequest $request
     * @return JsonResponse
     */
    public function store(OpenDisputeRequest $request): JsonResponse
    {
        $user = $request->user();

        // Get the PeaceLink
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $request->peacelink_id);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        // Verify user is party to the PeaceLink
        $isBuyer = $peaceLink->buyer_id === $user->id;
        $isMerchant = $peaceLink->merchant_id === $user->id;

        if (!$isBuyer && !$isMerchant) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بفتح نزاع على هذه المعاملة',
            ], 403);
        }

        // Check if dispute already exists
        if ($this->disputeService->hasActiveDispute($peaceLink)) {
            return response()->json([
                'success' => false,
                'message' => 'يوجد بالفعل نزاع مفتوح على هذه المعاملة',
            ], 422);
        }

        // Validate PeaceLink status allows dispute
        $disputeAllowedStatuses = ['in_transit', 'delivered', 'released'];
        if (!in_array($peaceLink->status->value, $disputeAllowedStatuses)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن فتح نزاع على هذه المعاملة في حالتها الحالية',
            ], 422);
        }

        try {
            // Handle evidence uploads
            $evidenceFiles = [];
            if ($request->hasFile('evidence')) {
                foreach ($request->file('evidence') as $file) {
                    $path = $file->store('disputes/evidence', 'private');
                    $evidenceFiles[] = [
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ];
                }
            }

            $dispute = DB::transaction(function () use ($user, $peaceLink, $request, $evidenceFiles, $isBuyer) {
                // Create dispute
                $dispute = $this->disputeService->create([
                    'peacelink_id' => $peaceLink->id,
                    'initiated_by' => $user->id,
                    'initiator_role' => $isBuyer ? 'buyer' : 'merchant',
                    'reason' => $request->reason,
                    'description' => $request->description,
                    'evidence' => $evidenceFiles,
                    'requested_resolution' => $request->requested_resolution,
                    'requested_amount' => $request->requested_amount,
                ]);

                // Update PeaceLink status to disputed
                $this->peaceLinkService->markDisputed($peaceLink);

                // If funds not yet released, hold them pending dispute resolution
                if ($peaceLink->status->value !== 'released') {
                    $this->walletService->holdDisputedFunds($peaceLink);
                }

                return $dispute;
            });

            // Notify other party
            $this->notificationService->notifyDisputeOpened($dispute);

            return response()->json([
                'success' => true,
                'message' => 'تم فتح النزاع بنجاح',
                'data' => [
                    'dispute' => new DisputeResource($dispute),
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Dispute creation failed', [
                'user_id' => $user->id,
                'peacelink_id' => $request->peacelink_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في فتح النزاع',
            ], 500);
        }
    }

    /**
     * Respond to a dispute (other party)
     * 
     * @param DisputeResponseRequest $request
     * @param string $disputeId
     * @return JsonResponse
     */
    public function respond(DisputeResponseRequest $request, string $disputeId): JsonResponse
    {
        $user = $request->user();
        $dispute = $this->disputeService->getDispute($user, $disputeId);

        if (!$dispute) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع غير موجود',
            ], 404);
        }

        // Verify user is the other party (not the initiator)
        $peaceLink = $dispute->peaceLink;
        $isInitiator = $dispute->initiated_by === $user->id;
        $isOtherParty = ($peaceLink->buyer_id === $user->id || $peaceLink->merchant_id === $user->id) && !$isInitiator;

        if (!$isOtherParty) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالرد على هذا النزاع',
            ], 403);
        }

        if ($dispute->status !== DisputeStatus::OPEN) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن الرد على هذا النزاع في حالته الحالية',
            ], 422);
        }

        try {
            // Handle evidence uploads
            $evidenceFiles = [];
            if ($request->hasFile('evidence')) {
                foreach ($request->file('evidence') as $file) {
                    $path = $file->store('disputes/evidence', 'private');
                    $evidenceFiles[] = [
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ];
                }
            }

            $dispute = $this->disputeService->addResponse($dispute, [
                'responder_id' => $user->id,
                'response' => $request->response,
                'counter_proposal' => $request->counter_proposal,
                'counter_amount' => $request->counter_amount,
                'evidence' => $evidenceFiles,
                'accepts_proposal' => $request->accepts_proposal,
            ]);

            // If other party accepts, resolve dispute
            if ($request->accepts_proposal) {
                $dispute = $this->resolveByAgreement($dispute);
            }

            // Notify initiator
            $this->notificationService->notifyDisputeResponse($dispute);

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال الرد بنجاح',
                'data' => [
                    'dispute' => new DisputeResource($dispute),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في إرسال الرد',
            ], 500);
        }
    }

    /**
     * Accept counter-proposal (initiator)
     * 
     * @param Request $request
     * @param string $disputeId
     * @return JsonResponse
     */
    public function acceptCounterProposal(Request $request, string $disputeId): JsonResponse
    {
        $user = $request->user();
        $dispute = $this->disputeService->getDispute($user, $disputeId);

        if (!$dispute) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع غير موجود',
            ], 404);
        }

        // Verify user is initiator
        if ($dispute->initiated_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        if (!$dispute->counter_proposal) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد اقتراح مضاد للقبول',
            ], 422);
        }

        try {
            $dispute = $this->resolveByAgreement($dispute, 'counter');

            return response()->json([
                'success' => true,
                'message' => 'تم قبول الاقتراح المضاد وحل النزاع',
                'data' => [
                    'dispute' => new DisputeResource($dispute),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في قبول الاقتراح',
            ], 500);
        }
    }

    /**
     * Escalate dispute to admin
     * 
     * @param Request $request
     * @param string $disputeId
     * @return JsonResponse
     */
    public function escalate(Request $request, string $disputeId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:20|max:1000',
        ]);

        $user = $request->user();
        $dispute = $this->disputeService->getDispute($user, $disputeId);

        if (!$dispute) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع غير موجود',
            ], 404);
        }

        // Verify user is party to dispute
        $peaceLink = $dispute->peaceLink;
        if ($peaceLink->buyer_id !== $user->id && $peaceLink->merchant_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        if (!in_array($dispute->status, [DisputeStatus::OPEN, DisputeStatus::UNDER_REVIEW])) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تصعيد هذا النزاع',
            ], 422);
        }

        try {
            $dispute = $this->disputeService->escalate($dispute, $user->id, $validated['reason']);

            // Notify admin and parties
            $this->notificationService->notifyDisputeEscalated($dispute);

            return response()->json([
                'success' => true,
                'message' => 'تم تصعيد النزاع للإدارة',
                'data' => [
                    'dispute' => new DisputeResource($dispute),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تصعيد النزاع',
            ], 500);
        }
    }

    /**
     * Add message to dispute conversation
     * 
     * @param Request $request
     * @param string $disputeId
     * @return JsonResponse
     */
    public function addMessage(Request $request, string $disputeId): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|min:1|max:2000',
            'attachments' => 'sometimes|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $user = $request->user();
        $dispute = $this->disputeService->getDispute($user, $disputeId);

        if (!$dispute) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع غير موجود',
            ], 404);
        }

        // Verify user is party to dispute or admin
        $peaceLink = $dispute->peaceLink;
        $isParty = $peaceLink->buyer_id === $user->id || $peaceLink->merchant_id === $user->id;
        
        if (!$isParty && !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بإضافة رسالة',
            ], 403);
        }

        if ($dispute->status === DisputeStatus::CLOSED) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع مغلق ولا يمكن إضافة رسائل',
            ], 422);
        }

        try {
            // Handle attachments
            $attachments = [];
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('disputes/messages', 'private');
                    $attachments[] = [
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                    ];
                }
            }

            $message = $this->disputeService->addMessage($dispute, [
                'user_id' => $user->id,
                'message' => $validated['message'],
                'attachments' => $attachments,
            ]);

            // Notify other parties
            $this->notificationService->notifyDisputeMessage($dispute, $user);

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال الرسالة',
                'data' => [
                    'message' => $message,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في إرسال الرسالة',
            ], 500);
        }
    }

    /**
     * Get dispute messages/conversation
     * 
     * @param Request $request
     * @param string $disputeId
     * @return JsonResponse
     */
    public function messages(Request $request, string $disputeId): JsonResponse
    {
        $user = $request->user();
        $dispute = $this->disputeService->getDispute($user, $disputeId);

        if (!$dispute) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع غير موجود',
            ], 404);
        }

        $messages = $this->disputeService->getMessages($dispute);

        return response()->json([
            'success' => true,
            'data' => [
                'messages' => $messages,
            ]
        ]);
    }

    /**
     * Get available dispute reasons
     * 
     * @return JsonResponse
     */
    public function reasons(): JsonResponse
    {
        $reasons = [
            [
                'id' => 'item_not_received',
                'name' => 'لم أستلم المنتج',
                'description' => 'المنتج لم يصل رغم مرور وقت التسليم المتوقع',
                'role' => 'buyer',
            ],
            [
                'id' => 'item_not_as_described',
                'name' => 'المنتج مختلف عن الوصف',
                'description' => 'المنتج المستلم لا يطابق الوصف أو الصور',
                'role' => 'buyer',
            ],
            [
                'id' => 'item_damaged',
                'name' => 'المنتج تالف',
                'description' => 'المنتج وصل بحالة تالفة أو معيبة',
                'role' => 'buyer',
            ],
            [
                'id' => 'wrong_item',
                'name' => 'منتج خاطئ',
                'description' => 'استلمت منتجاً مختلفاً عن الذي طلبته',
                'role' => 'buyer',
            ],
            [
                'id' => 'partial_delivery',
                'name' => 'توصيل جزئي',
                'description' => 'لم أستلم كل المنتجات المطلوبة',
                'role' => 'buyer',
            ],
            [
                'id' => 'payment_not_received',
                'name' => 'لم أستلم المبلغ',
                'description' => 'تم التسليم ولكن المبلغ لم يُحول لحسابي',
                'role' => 'merchant',
            ],
            [
                'id' => 'buyer_not_available',
                'name' => 'المشتري غير متاح',
                'description' => 'لم أتمكن من التواصل مع المشتري للتسليم',
                'role' => 'merchant',
            ],
            [
                'id' => 'delivery_refused',
                'name' => 'رفض الاستلام',
                'description' => 'المشتري رفض استلام المنتج دون سبب',
                'role' => 'merchant',
            ],
            [
                'id' => 'other',
                'name' => 'سبب آخر',
                'description' => 'سبب غير مدرج في القائمة',
                'role' => 'both',
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'reasons' => $reasons,
            ]
        ]);
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Resolve dispute by mutual agreement
     */
    private function resolveByAgreement($dispute, string $proposalType = 'initial'): mixed
    {
        return DB::transaction(function () use ($dispute, $proposalType) {
            $peaceLink = $dispute->peaceLink;
            
            // Determine resolution based on proposal accepted
            $resolution = $proposalType === 'counter' 
                ? $dispute->counter_proposal 
                : $dispute->requested_resolution;
            
            $amount = $proposalType === 'counter'
                ? $dispute->counter_amount
                : $dispute->requested_amount;

            // Execute resolution
            match ($resolution) {
                'full_refund' => $this->walletService->executeFullRefund($peaceLink),
                'partial_refund' => $this->walletService->executePartialRefund($peaceLink, $amount),
                'release_to_merchant' => $this->walletService->releaseFunds($peaceLink),
                'split' => $this->walletService->executeSplitResolution($peaceLink, $amount),
                default => null,
            };

            // Update dispute status
            return $this->disputeService->resolve($dispute, [
                'resolution' => $resolution,
                'resolution_amount' => $amount,
                'resolved_by' => 'agreement',
            ]);
        });
    }

    // ==================== ADMIN ENDPOINTS ====================

    /**
     * Admin: Get all disputes
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorize('admin');

        $validated = $request->validate([
            'status' => 'sometimes|string',
            'per_page' => 'sometimes|integer|min:10|max:100',
        ]);

        $disputes = $this->disputeService->getAllDisputes(
            $validated['status'] ?? null,
            $validated['per_page'] ?? 50
        );

        return response()->json([
            'success' => true,
            'data' => new DisputeCollection($disputes),
        ]);
    }

    /**
     * Admin: Resolve dispute
     */
    public function adminResolve(Request $request, string $disputeId): JsonResponse
    {
        $this->authorize('admin');

        $validated = $request->validate([
            'resolution' => 'required|string|in:full_refund,partial_refund,release_to_merchant,split',
            'amount' => 'required_if:resolution,partial_refund,split|numeric|min:0',
            'notes' => 'required|string|min:10|max:2000',
        ]);

        $dispute = $this->disputeService->findById($disputeId);

        if (!$dispute) {
            return response()->json([
                'success' => false,
                'message' => 'النزاع غير موجود',
            ], 404);
        }

        try {
            $dispute = DB::transaction(function () use ($dispute, $validated, $request) {
                $peaceLink = $dispute->peaceLink;

                // Execute resolution
                match ($validated['resolution']) {
                    'full_refund' => $this->walletService->executeFullRefund($peaceLink),
                    'partial_refund' => $this->walletService->executePartialRefund($peaceLink, $validated['amount']),
                    'release_to_merchant' => $this->walletService->releaseFunds($peaceLink),
                    'split' => $this->walletService->executeSplitResolution($peaceLink, $validated['amount']),
                };

                return $this->disputeService->resolve($dispute, [
                    'resolution' => $validated['resolution'],
                    'resolution_amount' => $validated['amount'] ?? null,
                    'resolved_by' => 'admin',
                    'admin_id' => $request->user()->id,
                    'admin_notes' => $validated['notes'],
                ]);
            });

            // Notify parties
            $this->notificationService->notifyDisputeResolved($dispute);

            return response()->json([
                'success' => true,
                'message' => 'تم حل النزاع بنجاح',
                'data' => [
                    'dispute' => new DisputeResource($dispute),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Admin dispute resolution failed', [
                'dispute_id' => $disputeId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في حل النزاع',
            ], 500);
        }
    }
}
