<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePeaceLinkRequest;
use App\Http\Requests\ConfirmDeliveryRequest;
use App\Http\Requests\AssignDspRequest;
use App\Http\Resources\PeaceLinkResource;
use App\Http\Resources\PeaceLinkCollection;
use App\Services\PeaceLinkService;
use App\Services\WalletService;
use App\Services\Sms\OtpService;
use App\Services\NotificationService;
use App\Enums\PeaceLinkStatus;
use App\Enums\PeaceLinkRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PeaceLinkController extends Controller
{
    public function __construct(
        private readonly PeaceLinkService $peaceLinkService,
        private readonly WalletService $walletService,
        private readonly OtpService $otpService,
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Get PeaceLinks list with filters
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'sometimes|string|in:buyer,merchant',
            'status' => 'sometimes|string|in:active,in_transit,completed,cancelled',
            'per_page' => 'sometimes|integer|min:10|max:50',
        ]);

        $user = $request->user();
        $peaceLinks = $this->peaceLinkService->getUserPeaceLinks(
            $user,
            $validated['role'] ?? null,
            $validated['status'] ?? null,
            $validated['per_page'] ?? 20
        );

        return response()->json([
            'success' => true,
            'data' => new PeaceLinkCollection($peaceLinks),
        ]);
    }

    /**
     * Get single PeaceLink details
     * 
     * @param Request $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function show(Request $request, string $peaceLinkId): JsonResponse
    {
        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'peacelink' => new PeaceLinkResource($peaceLink),
            ]
        ]);
    }

    /**
     * Create new PeaceLink (buyer initiates)
     * 
     * @param CreatePeaceLinkRequest $request
     * @return JsonResponse
     */
    public function store(CreatePeaceLinkRequest $request): JsonResponse
    {
        $buyer = $request->user();

        try {
            // Calculate total with fees
            $fees = $this->peaceLinkService->calculateFees(
                $request->item_amount,
                $request->delivery_fee ?? 0,
                $request->buyer_pays_delivery
            );

            $totalRequired = $fees['total_buyer_pays'];

            // Validate buyer balance
            $this->walletService->validateBalance($buyer, $totalRequired);

            // Find merchant
            $merchant = $this->walletService->searchUserByPhone(
                $request->merchant_phone,
                $buyer->id
            );

            if (!$merchant) {
                return response()->json([
                    'success' => false,
                    'message' => 'التاجر غير موجود',
                ], 404);
            }

            // Create PeaceLink and hold funds
            $peaceLink = DB::transaction(function () use ($buyer, $merchant, $request, $fees) {
                // Create PeaceLink record
                $peaceLink = $this->peaceLinkService->create([
                    'buyer_id' => $buyer->id,
                    'merchant_id' => $merchant->id,
                    'product_description' => $request->product_description,
                    'item_amount' => $request->item_amount,
                    'delivery_fee' => $request->delivery_fee ?? 0,
                    'buyer_pays_delivery' => $request->buyer_pays_delivery,
                    'platform_fee' => $fees['platform_fee'],
                    'delivery_address' => $request->delivery_address,
                    'delivery_notes' => $request->delivery_notes,
                    'use_internal_dsp' => $request->use_internal_dsp ?? false,
                ]);

                // Hold funds from buyer wallet
                $this->walletService->holdFunds(
                    $buyer,
                    $fees['total_buyer_pays'],
                    'peacelink',
                    $peaceLink->id
                );

                // Update status to funded
                $peaceLink->update(['status' => PeaceLinkStatus::FUNDED]);

                return $peaceLink;
            });

            // Notify merchant
            $this->notificationService->notifyNewPeaceLink($peaceLink);

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء معاملة PeaceLink بنجاح',
                'data' => [
                    'peacelink' => new PeaceLinkResource($peaceLink->fresh(['buyer', 'merchant'])),
                    'fees' => $fees,
                ]
            ], 201);

        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return response()->json([
                'success' => false,
                'message' => 'رصيدك غير كافي لإتمام هذه المعاملة',
                'data' => [
                    'available' => $e->getAvailable(),
                    'required' => $e->getRequired(),
                ]
            ], 422);

        } catch (\Exception $e) {
            Log::error('PeaceLink creation failed', [
                'buyer_id' => $buyer->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في إنشاء معاملة PeaceLink',
            ], 500);
        }
    }

    /**
     * Calculate PeaceLink fees
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function calculateFees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_amount' => 'required|numeric|min:50',
            'delivery_fee' => 'sometimes|numeric|min:0',
            'buyer_pays_delivery' => 'sometimes|boolean',
        ]);

        $fees = $this->peaceLinkService->calculateFees(
            $validated['item_amount'],
            $validated['delivery_fee'] ?? 0,
            $validated['buyer_pays_delivery'] ?? true
        );

        return response()->json([
            'success' => true,
            'data' => $fees,
        ]);
    }

    /**
     * Merchant accepts PeaceLink
     * 
     * @param Request $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function accept(Request $request, string $peaceLinkId): JsonResponse
    {
        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        // Verify user is merchant
        if ($peaceLink->merchant_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        // Verify status
        if ($peaceLink->status !== PeaceLinkStatus::FUNDED) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن قبول هذه المعاملة في حالتها الحالية',
            ], 422);
        }

        try {
            $peaceLink = $this->peaceLinkService->acceptPeaceLink($peaceLink);

            // Notify buyer
            $this->notificationService->notifyPeaceLinkAccepted($peaceLink);

            return response()->json([
                'success' => true,
                'message' => 'تم قبول المعاملة بنجاح',
                'data' => [
                    'peacelink' => new PeaceLinkResource($peaceLink),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في قبول المعاملة',
            ], 500);
        }
    }

    /**
     * Assign DSP to PeaceLink
     * 
     * @param AssignDspRequest $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function assignDsp(AssignDspRequest $request, string $peaceLinkId): JsonResponse
    {
        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        // Only merchant or admin can assign DSP
        if ($peaceLink->merchant_id !== $user->id && !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        try {
            $peaceLink = $this->peaceLinkService->assignDsp(
                $peaceLink,
                $request->dsp_id,
                $request->estimated_delivery_date
            );

            // Notify all parties
            $this->notificationService->notifyDspAssigned($peaceLink);

            return response()->json([
                'success' => true,
                'message' => 'تم تعيين مندوب التوصيل',
                'data' => [
                    'peacelink' => new PeaceLinkResource($peaceLink),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تعيين مندوب التوصيل',
            ], 500);
        }
    }

    /**
     * Update delivery status to in-transit
     * 
     * @param Request $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function markInTransit(Request $request, string $peaceLinkId): JsonResponse
    {
        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        // Only DSP or merchant can mark in transit
        $isDsp = $peaceLink->dsp_id === $user->id;
        $isMerchant = $peaceLink->merchant_id === $user->id;

        if (!$isDsp && !$isMerchant) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        if (!in_array($peaceLink->status, [PeaceLinkStatus::FUNDED, PeaceLinkStatus::DSP_ASSIGNED])) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تحديث حالة هذه المعاملة',
            ], 422);
        }

        try {
            $peaceLink = $this->peaceLinkService->markInTransit($peaceLink);

            // Notify buyer
            $this->notificationService->notifyInTransit($peaceLink);

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث الحالة إلى "في الطريق"',
                'data' => [
                    'peacelink' => new PeaceLinkResource($peaceLink),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في تحديث الحالة',
            ], 500);
        }
    }

    /**
     * Generate delivery OTP (called by DSP on arrival)
     * 
     * @param Request $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function generateDeliveryOtp(Request $request, string $peaceLinkId): JsonResponse
    {
        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        // Only DSP or merchant can generate OTP
        $isDsp = $peaceLink->dsp_id === $user->id;
        $isMerchant = $peaceLink->merchant_id === $user->id && !$peaceLink->use_internal_dsp;

        if (!$isDsp && !$isMerchant) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        if ($peaceLink->status !== PeaceLinkStatus::IN_TRANSIT) {
            return response()->json([
                'success' => false,
                'message' => 'المعاملة ليست في حالة التوصيل',
            ], 422);
        }

        try {
            $otp = $this->otpService->generateDeliveryOtp($peaceLink);

            // Send OTP to buyer via SMS
            $this->notificationService->sendDeliveryOtp($peaceLink, $otp);

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال رمز التأكيد للمشتري',
                'data' => [
                    'expires_in' => 300, // 5 minutes
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل في إنشاء رمز التأكيد',
            ], 500);
        }
    }

    /**
     * Confirm delivery with OTP (buyer confirms)
     * 
     * @param ConfirmDeliveryRequest $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function confirmDelivery(ConfirmDeliveryRequest $request, string $peaceLinkId): JsonResponse
    {
        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        // Only buyer can confirm delivery
        if ($peaceLink->buyer_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        if ($peaceLink->status !== PeaceLinkStatus::IN_TRANSIT) {
            return response()->json([
                'success' => false,
                'message' => 'المعاملة ليست في حالة التوصيل',
            ], 422);
        }

        try {
            // Verify OTP
            $otpResult = $this->otpService->verifyDeliveryOtp(
                $peaceLink,
                $request->otp
            );

            if (!$otpResult['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $otpResult['message'],
                    'data' => [
                        'attempts_remaining' => $otpResult['attempts_remaining'] ?? 0
                    ]
                ], 422);
            }

            // Complete delivery and release funds
            $result = DB::transaction(function () use ($peaceLink) {
                // Mark as delivered
                $peaceLink = $this->peaceLinkService->markDelivered($peaceLink);

                // Release funds to merchant
                $this->walletService->releaseFunds($peaceLink);

                // Pay DSP if internal
                if ($peaceLink->use_internal_dsp && $peaceLink->dsp_id) {
                    $this->walletService->payDsp($peaceLink);
                }

                // Update status to released
                $peaceLink->update(['status' => PeaceLinkStatus::RELEASED]);

                return $peaceLink;
            });

            // Notify all parties
            $this->notificationService->notifyDeliveryCompleted($result);

            return response()->json([
                'success' => true,
                'message' => 'تم تأكيد الاستلام وإتمام المعاملة بنجاح',
                'data' => [
                    'peacelink' => new PeaceLinkResource($result),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Delivery confirmation failed', [
                'peacelink_id' => $peaceLinkId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في تأكيد الاستلام',
            ], 500);
        }
    }

    /**
     * Cancel PeaceLink (before in-transit)
     * 
     * @param Request $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function cancel(Request $request, string $peaceLinkId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        // Both buyer and merchant can cancel before in-transit
        $isBuyer = $peaceLink->buyer_id === $user->id;
        $isMerchant = $peaceLink->merchant_id === $user->id;

        if (!$isBuyer && !$isMerchant) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بهذا الإجراء',
            ], 403);
        }

        // Can only cancel before in-transit
        $cancellableStatuses = [
            PeaceLinkStatus::PENDING,
            PeaceLinkStatus::FUNDED,
            PeaceLinkStatus::DSP_ASSIGNED,
        ];

        if (!in_array($peaceLink->status, $cancellableStatuses)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إلغاء هذه المعاملة في حالتها الحالية. يرجى فتح نزاع إذا لزم الأمر',
            ], 422);
        }

        try {
            $peaceLink = DB::transaction(function () use ($peaceLink, $user, $validated) {
                // Refund buyer
                $this->walletService->refundHeldFunds($peaceLink);

                // Update status
                $peaceLink = $this->peaceLinkService->cancel(
                    $peaceLink,
                    $user->id,
                    $validated['reason']
                );

                return $peaceLink;
            });

            // Notify parties
            $this->notificationService->notifyPeaceLinkCancelled($peaceLink);

            return response()->json([
                'success' => true,
                'message' => 'تم إلغاء المعاملة واسترداد المبلغ',
                'data' => [
                    'peacelink' => new PeaceLinkResource($peaceLink),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('PeaceLink cancellation failed', [
                'peacelink_id' => $peaceLinkId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل في إلغاء المعاملة',
            ], 500);
        }
    }

    /**
     * Get PeaceLink timeline/history
     * 
     * @param Request $request
     * @param string $peaceLinkId
     * @return JsonResponse
     */
    public function timeline(Request $request, string $peaceLinkId): JsonResponse
    {
        $user = $request->user();
        $peaceLink = $this->peaceLinkService->getPeaceLink($user, $peaceLinkId);

        if (!$peaceLink) {
            return response()->json([
                'success' => false,
                'message' => 'معاملة PeaceLink غير موجودة',
            ], 404);
        }

        $timeline = $this->peaceLinkService->getTimeline($peaceLink);

        return response()->json([
            'success' => true,
            'data' => [
                'timeline' => $timeline,
            ]
        ]);
    }

    /**
     * Get PeaceLink statistics for user
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        $user = $request->user();
        $stats = $this->peaceLinkService->getUserStatistics($user);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
