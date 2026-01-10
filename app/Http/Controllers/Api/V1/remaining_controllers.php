<?php

/**
 * PeacePay Remaining API Controllers
 * 
 * KycController, NotificationController, TransactionController, ReportController
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpgradeKycRequest;
use App\Http\Requests\TransactionListRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransactionCollection;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Models\KycRequest;
use App\Models\Transaction;
use App\Models\PeaceLink;
use App\Models\Dispute;
use App\Models\CashoutRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

// ============================================================================
// KycController
// ============================================================================

class KycController extends Controller
{
    use ApiResponse;

    /**
     * Get current KYC status and limits
     */
    public function status(Request $request)
    {
        $user = $request->user();
        $limits = config("peacepay.limits.kyc.{$user->kyc_level}");
        
        // Calculate current usage
        $dailyTransferred = $user->transactions()
            ->where('type', 'send')
            ->where('direction', 'debit')
            ->whereDate('created_at', today())
            ->sum('amount');
            
        $monthlyCashout = $user->wallet->cashoutRequests()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->sum('amount');

        return $this->success([
            'kyc_level' => $user->kyc_level,
            'kyc_level_label' => $this->getKycLevelLabel($user->kyc_level),
            'limits' => [
                'daily_transfer' => [
                    'limit' => $limits['daily_transfer'],
                    'used' => $dailyTransferred,
                    'remaining' => max(0, $limits['daily_transfer'] - $dailyTransferred),
                    'percentage' => round(($dailyTransferred / $limits['daily_transfer']) * 100, 1),
                ],
                'monthly_cashout' => [
                    'limit' => $limits['monthly_cashout'],
                    'used' => $monthlyCashout,
                    'remaining' => max(0, $limits['monthly_cashout'] - $monthlyCashout),
                    'percentage' => round(($monthlyCashout / $limits['monthly_cashout']) * 100, 1),
                ],
                'single_transaction' => $limits['single_transaction'],
            ],
            'can_upgrade' => $user->kyc_level !== 'gold',
            'next_level' => $this->getNextLevel($user->kyc_level),
            'upgrade_benefits' => $this->getUpgradeBenefits($user->kyc_level),
        ]);
    }

    /**
     * Request KYC upgrade
     */
    public function upgrade(UpgradeKycRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        // Check for pending request
        $pendingRequest = KycRequest::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'under_review'])
            ->first();

        if ($pendingRequest) {
            return $this->error(
                'لديك طلب ترقية قيد المراجعة بالفعل',
                'pending_request_exists',
                422
            );
        }

        // Validate level progression
        if (!$this->canUpgradeTo($user->kyc_level, $validated['target_level'])) {
            return $this->error(
                'لا يمكنك الترقية إلى هذا المستوى',
                'invalid_upgrade_level',
                422
            );
        }

        DB::beginTransaction();
        try {
            // Upload documents
            $documentUrls = $this->uploadKycDocuments($request, $validated['target_level']);

            // Create KYC request
            $kycRequest = KycRequest::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'current_level' => $user->kyc_level,
                'target_level' => $validated['target_level'],
                'national_id' => $validated['national_id'],
                'national_id_front_url' => $documentUrls['national_id_front'] ?? null,
                'national_id_back_url' => $documentUrls['national_id_back'] ?? null,
                'selfie_url' => $documentUrls['selfie'] ?? null,
                'address_proof_url' => $documentUrls['address_proof'] ?? null,
                'status' => 'pending',
            ]);

            DB::commit();

            return $this->created([
                'request_id' => $kycRequest->uuid,
                'status' => 'pending',
                'message' => 'تم استلام طلبك وسيتم مراجعته خلال 24-48 ساعة',
            ], 'تم إرسال طلب الترقية بنجاح');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('حدث خطأ أثناء معالجة الطلب', 'processing_error', 500);
        }
    }

    /**
     * Get user's KYC requests history
     */
    public function requests(Request $request)
    {
        $requests = KycRequest::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return $this->success([
            'requests' => $requests->map(fn($req) => [
                'id' => $req->uuid,
                'current_level' => $req->current_level,
                'target_level' => $req->target_level,
                'status' => $req->status,
                'status_label' => $this->getStatusLabel($req->status),
                'rejection_reason' => $req->rejection_reason,
                'submitted_at' => $req->created_at->format('Y-m-d H:i'),
                'reviewed_at' => $req->reviewed_at?->format('Y-m-d H:i'),
            ]),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Get single KYC request details
     */
    public function showRequest(Request $request, $requestId)
    {
        $kycRequest = KycRequest::where('uuid', $requestId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->success([
            'id' => $kycRequest->uuid,
            'current_level' => $kycRequest->current_level,
            'target_level' => $kycRequest->target_level,
            'national_id' => substr($kycRequest->national_id, 0, 4) . '**********',
            'status' => $kycRequest->status,
            'status_label' => $this->getStatusLabel($kycRequest->status),
            'rejection_reason' => $kycRequest->rejection_reason,
            'submitted_at' => $kycRequest->created_at->format('Y-m-d H:i'),
            'reviewed_at' => $kycRequest->reviewed_at?->format('Y-m-d H:i'),
            'documents' => [
                'national_id_front' => $kycRequest->national_id_front_url ? true : false,
                'national_id_back' => $kycRequest->national_id_back_url ? true : false,
                'selfie' => $kycRequest->selfie_url ? true : false,
                'address_proof' => $kycRequest->address_proof_url ? true : false,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin Methods
    // -------------------------------------------------------------------------

    /**
     * List all KYC requests (Admin)
     */
    public function adminIndex(Request $request)
    {
        $query = KycRequest::with('user:id,name,phone');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('created_at', 'desc')->paginate(20);

        return $this->success([
            'requests' => $requests->map(fn($req) => [
                'id' => $req->uuid,
                'user' => [
                    'name' => $req->user->name,
                    'phone' => $req->user->phone,
                ],
                'current_level' => $req->current_level,
                'target_level' => $req->target_level,
                'national_id' => $req->national_id,
                'status' => $req->status,
                'submitted_at' => $req->created_at->format('Y-m-d H:i'),
            ]),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Approve KYC request (Admin)
     */
    public function approve(Request $request, $requestId)
    {
        $kycRequest = KycRequest::where('uuid', $requestId)->firstOrFail();

        if ($kycRequest->status !== 'pending' && $kycRequest->status !== 'under_review') {
            return $this->error('لا يمكن الموافقة على هذا الطلب', 'invalid_status', 422);
        }

        DB::beginTransaction();
        try {
            // Update request
            $kycRequest->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            // Upgrade user's KYC level
            $kycRequest->user->update([
                'kyc_level' => $kycRequest->target_level,
            ]);

            // Send notification
            $this->notifyKycApproved($kycRequest);

            DB::commit();

            return $this->success(null, 'تمت الموافقة على طلب الترقية');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('حدث خطأ أثناء المعالجة', 'processing_error', 500);
        }
    }

    /**
     * Reject KYC request (Admin)
     */
    public function reject(Request $request, $requestId)
    {
        $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $kycRequest = KycRequest::where('uuid', $requestId)->firstOrFail();

        if ($kycRequest->status !== 'pending' && $kycRequest->status !== 'under_review') {
            return $this->error('لا يمكن رفض هذا الطلب', 'invalid_status', 422);
        }

        $kycRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // Send notification
        $this->notifyKycRejected($kycRequest);

        return $this->success(null, 'تم رفض طلب الترقية');
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    private function getKycLevelLabel(string $level): string
    {
        return match($level) {
            'basic' => 'أساسي',
            'silver' => 'فضي',
            'gold' => 'ذهبي',
            default => $level,
        };
    }

    private function getNextLevel(string $currentLevel): ?array
    {
        return match($currentLevel) {
            'basic' => [
                'level' => 'silver',
                'label' => 'فضي',
                'requirements' => [
                    'الرقم القومي (صورة الوجه والخلف)',
                ],
            ],
            'silver' => [
                'level' => 'gold',
                'label' => 'ذهبي',
                'requirements' => [
                    'صورة سيلفي للتحقق',
                    'إثبات العنوان (فاتورة مرافق)',
                ],
            ],
            default => null,
        };
    }

    private function getUpgradeBenefits(string $currentLevel): array
    {
        $silverLimits = config('peacepay.limits.kyc.silver');
        $goldLimits = config('peacepay.limits.kyc.gold');

        return match($currentLevel) {
            'basic' => [
                "زيادة الحد اليومي للتحويل إلى {$silverLimits['daily_transfer']} جنيه",
                "زيادة الحد الشهري للسحب إلى {$silverLimits['monthly_cashout']} جنيه",
            ],
            'silver' => [
                "زيادة الحد اليومي للتحويل إلى {$goldLimits['daily_transfer']} جنيه",
                "زيادة الحد الشهري للسحب إلى {$goldLimits['monthly_cashout']} جنيه",
                "أولوية في الدعم الفني",
            ],
            default => [],
        };
    }

    private function canUpgradeTo(string $current, string $target): bool
    {
        $levels = ['basic' => 1, 'silver' => 2, 'gold' => 3];
        return isset($levels[$target]) && $levels[$target] === $levels[$current] + 1;
    }

    private function getStatusLabel(string $status): string
    {
        return match($status) {
            'pending' => 'قيد الانتظار',
            'under_review' => 'قيد المراجعة',
            'approved' => 'تمت الموافقة',
            'rejected' => 'مرفوض',
            default => $status,
        };
    }

    private function uploadKycDocuments(Request $request, string $targetLevel): array
    {
        $urls = [];

        if ($request->hasFile('national_id_front')) {
            $urls['national_id_front'] = $request->file('national_id_front')
                ->store('kyc/national-ids', 'private');
        }

        if ($request->hasFile('national_id_back')) {
            $urls['national_id_back'] = $request->file('national_id_back')
                ->store('kyc/national-ids', 'private');
        }

        if ($targetLevel === 'gold') {
            if ($request->hasFile('selfie')) {
                $urls['selfie'] = $request->file('selfie')
                    ->store('kyc/selfies', 'private');
            }

            if ($request->hasFile('address_proof')) {
                $urls['address_proof'] = $request->file('address_proof')
                    ->store('kyc/address-proofs', 'private');
            }
        }

        return $urls;
    }

    private function notifyKycApproved(KycRequest $request): void
    {
        // TODO: Implement notification
    }

    private function notifyKycRejected(KycRequest $request): void
    {
        // TODO: Implement notification
    }
}


// ============================================================================
// NotificationController
// ============================================================================

class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * Get user's notifications
     */
    public function index(Request $request)
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return $this->success([
            'notifications' => $notifications->map(fn($n) => [
                'id' => $n->id,
                'type' => $this->getNotificationType($n->type),
                'title' => $n->data['title'] ?? '',
                'message' => $n->data['message'] ?? '',
                'data' => $n->data['payload'] ?? null,
                'action_url' => $n->data['action_url'] ?? null,
                'is_read' => $n->read_at !== null,
                'created_at' => $n->created_at->format('Y-m-d H:i'),
                'time_ago' => $this->getTimeAgo($n->created_at),
            ]),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Get unread count
     */
    public function unreadCount(Request $request)
    {
        $count = $request->user()->unreadNotifications()->count();

        return $this->success([
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, $notificationId)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->markAsRead();

        return $this->success(null, 'تم تحديد الإشعار كمقروء');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(null, 'تم تحديد جميع الإشعارات كمقروءة');
    }

    /**
     * Delete notification
     */
    public function destroy(Request $request, $notificationId)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->delete();

        return $this->success(null, 'تم حذف الإشعار');
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    private function getNotificationType(string $class): string
    {
        // Extract type from notification class name
        $parts = explode('\\', $class);
        $className = end($parts);
        
        return match($className) {
            'NewPeaceLinkNotification' => 'peacelink_new',
            'PeaceLinkAcceptedNotification' => 'peacelink_accepted',
            'DspAssignedNotification' => 'peacelink_dsp',
            'DeliveryOtpNotification' => 'peacelink_otp',
            'DeliveryCompletedNotification' => 'peacelink_completed',
            'PeaceLinkCancelledNotification' => 'peacelink_cancelled',
            'DisputeOpenedNotification' => 'dispute_opened',
            'DisputeResolvedNotification' => 'dispute_resolved',
            'CashoutCompletedNotification' => 'cashout_completed',
            'KycApprovedNotification' => 'kyc_approved',
            'KycRejectedNotification' => 'kyc_rejected',
            default => 'general',
        };
    }

    private function getTimeAgo(Carbon $date): string
    {
        $diff = $date->diffForHumans();
        
        // Arabic time ago formatting
        $translations = [
            'seconds ago' => 'منذ ثوان',
            'second ago' => 'منذ ثانية',
            'minutes ago' => 'منذ دقائق',
            'minute ago' => 'منذ دقيقة',
            'hours ago' => 'منذ ساعات',
            'hour ago' => 'منذ ساعة',
            'days ago' => 'منذ أيام',
            'day ago' => 'منذ يوم',
            'weeks ago' => 'منذ أسابيع',
            'week ago' => 'منذ أسبوع',
            'months ago' => 'منذ شهور',
            'month ago' => 'منذ شهر',
        ];

        foreach ($translations as $en => $ar) {
            if (str_contains($diff, $en)) {
                $number = (int) filter_var($diff, FILTER_SANITIZE_NUMBER_INT);
                return str_replace($en, $ar, $diff);
            }
        }

        return $date->format('Y-m-d');
    }
}


// ============================================================================
// TransactionController
// ============================================================================

class TransactionController extends Controller
{
    use ApiResponse;

    /**
     * Get transaction history
     */
    public function index(TransactionListRequest $request)
    {
        $user = $request->user();
        $query = Transaction::where('user_id', $user->id);

        // Filter by type
        if ($request->has('type')) {
            $types = explode(',', $request->type);
            $query->whereIn('type', $types);
        }

        // Filter by direction (credit/debit)
        if ($request->has('direction')) {
            $query->where('direction', $request->direction);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->with('counterparty:id,name,phone')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return $this->success([
            'transactions' => $transactions->map(fn($t) => [
                'id' => $t->uuid,
                'reference' => $t->reference,
                'type' => $t->type,
                'type_label' => $this->getTypeLabel($t->type),
                'direction' => $t->direction,
                'amount' => $t->amount,
                'formatted_amount' => $this->formatAmount($t->amount, $t->direction),
                'fee' => $t->fee,
                'balance_after' => $t->balance_after,
                'status' => $t->status,
                'status_label' => $this->getStatusLabel($t->status),
                'counterparty' => $t->counterparty ? [
                    'name' => $t->counterparty->name,
                    'phone' => $this->maskPhone($t->counterparty->phone),
                ] : null,
                'payment_method' => $t->payment_method,
                'description' => $t->description,
                'created_at' => $t->created_at->format('Y-m-d H:i'),
                'date' => $t->created_at->format('Y-m-d'),
                'time' => $t->created_at->format('H:i'),
            ]),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Get single transaction details
     */
    public function show(Request $request, $transactionId)
    {
        $transaction = Transaction::where(function($q) use ($transactionId) {
            $q->where('uuid', $transactionId)
              ->orWhere('reference', $transactionId);
        })
        ->where('user_id', $request->user()->id)
        ->with(['counterparty:id,name,phone', 'peaceLink:id,uuid,reference,product_description'])
        ->firstOrFail();

        return $this->success([
            'id' => $transaction->uuid,
            'reference' => $transaction->reference,
            'type' => $transaction->type,
            'type_label' => $this->getTypeLabel($transaction->type),
            'direction' => $transaction->direction,
            'amount' => $transaction->amount,
            'formatted_amount' => $this->formatAmount($transaction->amount, $transaction->direction),
            'fee' => $transaction->fee,
            'balance_before' => $transaction->balance_before,
            'balance_after' => $transaction->balance_after,
            'status' => $transaction->status,
            'status_label' => $this->getStatusLabel($transaction->status),
            'counterparty' => $transaction->counterparty ? [
                'name' => $transaction->counterparty->name,
                'phone' => $this->maskPhone($transaction->counterparty->phone),
            ] : null,
            'peace_link' => $transaction->peaceLink ? [
                'id' => $transaction->peaceLink->uuid,
                'reference' => $transaction->peaceLink->reference,
                'product' => $transaction->peaceLink->product_description,
            ] : null,
            'payment_method' => $transaction->payment_method,
            'payment_reference' => $transaction->payment_reference,
            'description' => $transaction->description,
            'metadata' => $transaction->metadata,
            'created_at' => $transaction->created_at->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get monthly transaction summary
     */
    public function monthlySummary(Request $request)
    {
        $user = $request->user();
        $month = $request->get('month', now()->month);
        $year = $request->get('year', now()->year);

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $transactions = Transaction::where('user_id', $user->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'completed')
            ->get();

        $credits = $transactions->where('direction', 'credit')->sum('amount');
        $debits = $transactions->where('direction', 'debit')->sum('amount');
        $fees = $transactions->sum('fee');

        // Group by type
        $byType = $transactions->groupBy('type')->map(function($group, $type) {
            return [
                'type' => $type,
                'label' => $this->getTypeLabel($type),
                'count' => $group->count(),
                'total' => $group->sum('amount'),
            ];
        })->values();

        // Daily breakdown
        $daily = $transactions->groupBy(fn($t) => $t->created_at->format('Y-m-d'))
            ->map(function($group, $date) {
                return [
                    'date' => $date,
                    'credits' => $group->where('direction', 'credit')->sum('amount'),
                    'debits' => $group->where('direction', 'debit')->sum('amount'),
                    'count' => $group->count(),
                ];
            })->values();

        return $this->success([
            'period' => [
                'month' => $month,
                'year' => $year,
                'month_name' => $this->getArabicMonth($month),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'summary' => [
                'total_credits' => $credits,
                'total_debits' => $debits,
                'total_fees' => $fees,
                'net_change' => $credits - $debits,
                'transaction_count' => $transactions->count(),
            ],
            'by_type' => $byType,
            'daily' => $daily,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    private function getTypeLabel(string $type): string
    {
        return match($type) {
            'add_money' => 'إضافة رصيد',
            'send' => 'تحويل مرسل',
            'receive' => 'تحويل مستلم',
            'peacelink_hold' => 'حجز رصيد PeaceLink',
            'peacelink_release' => 'تحرير رصيد PeaceLink',
            'peacelink_refund' => 'استرداد PeaceLink',
            'peacelink_fee' => 'رسوم PeaceLink',
            'dsp_payment' => 'دفع التوصيل',
            'cashout' => 'سحب',
            'cashout_fee' => 'رسوم السحب',
            'platform_fee' => 'رسوم المنصة',
            default => $type,
        };
    }

    private function getStatusLabel(string $status): string
    {
        return match($status) {
            'pending' => 'قيد الانتظار',
            'completed' => 'مكتملة',
            'failed' => 'فاشلة',
            'cancelled' => 'ملغاة',
            default => $status,
        };
    }

    private function formatAmount(float $amount, string $direction): string
    {
        $sign = $direction === 'credit' ? '+' : '-';
        return "{$sign}" . number_format($amount, 2) . " EGP";
    }

    private function maskPhone(string $phone): string
    {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    private function getArabicMonth(int $month): string
    {
        $months = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس',
            4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
            7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر',
            10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];
        return $months[$month] ?? '';
    }
}


// ============================================================================
// ReportController (Admin)
// ============================================================================

class ReportController extends Controller
{
    use ApiResponse;

    /**
     * Get dashboard summary
     */
    public function dashboard(Request $request)
    {
        $today = today();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        // User stats
        $totalUsers = User::count();
        $newUsersToday = User::whereDate('created_at', $today)->count();
        $newUsersThisMonth = User::where('created_at', '>=', $thisMonth)->count();
        $activeUsers = User::where('last_login_at', '>=', now()->subDays(30))->count();

        // PeaceLink stats
        $totalPeaceLinks = PeaceLink::count();
        $activePeaceLinks = PeaceLink::whereIn('status', ['pending', 'funded', 'dsp_assigned', 'in_transit'])->count();
        $completedToday = PeaceLink::whereDate('released_at', $today)->count();
        $peaceLinkVolume = PeaceLink::where('status', 'released')
            ->where('released_at', '>=', $thisMonth)
            ->sum('item_amount');

        // Transaction stats
        $transactionsToday = Transaction::whereDate('created_at', $today)->count();
        $volumeToday = Transaction::whereDate('created_at', $today)
            ->where('direction', 'credit')
            ->sum('amount');

        // Revenue stats
        $revenueToday = Transaction::whereDate('created_at', $today)
            ->where('type', 'platform_fee')
            ->sum('amount');
        $revenueThisMonth = Transaction::where('created_at', '>=', $thisMonth)
            ->where('type', 'platform_fee')
            ->sum('amount');

        // Pending items
        $pendingCashouts = CashoutRequest::where('status', 'pending')->count();
        $pendingDisputes = Dispute::whereIn('status', ['pending', 'under_review'])->count();
        $pendingKyc = KycRequest::whereIn('status', ['pending', 'under_review'])->count();

        return $this->success([
            'users' => [
                'total' => $totalUsers,
                'new_today' => $newUsersToday,
                'new_this_month' => $newUsersThisMonth,
                'active_30_days' => $activeUsers,
            ],
            'peacelinks' => [
                'total' => $totalPeaceLinks,
                'active' => $activePeaceLinks,
                'completed_today' => $completedToday,
                'volume_this_month' => $peaceLinkVolume,
            ],
            'transactions' => [
                'count_today' => $transactionsToday,
                'volume_today' => $volumeToday,
            ],
            'revenue' => [
                'today' => $revenueToday,
                'this_month' => $revenueThisMonth,
            ],
            'pending_actions' => [
                'cashouts' => $pendingCashouts,
                'disputes' => $pendingDisputes,
                'kyc_requests' => $pendingKyc,
            ],
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get transaction reports
     */
    public function transactions(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $transactions = Transaction::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                type,
                direction,
                COUNT(*) as count,
                SUM(amount) as total_amount,
                SUM(fee) as total_fees,
                AVG(amount) as avg_amount
            ')
            ->groupBy('type', 'direction')
            ->get();

        $daily = Transaction::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as count,
                SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as debits
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $this->success([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'by_type' => $transactions,
            'daily' => $daily,
        ]);
    }

    /**
     * Get PeaceLink reports
     */
    public function peacelinks(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $stats = PeaceLink::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                status,
                COUNT(*) as count,
                SUM(item_amount) as total_amount,
                SUM(platform_fee) as total_fees,
                AVG(item_amount) as avg_amount
            ')
            ->groupBy('status')
            ->get();

        $completionStats = PeaceLink::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "released" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = "disputed" OR status = "refunded" THEN 1 ELSE 0 END) as disputed
            ')
            ->first();

        $avgCompletionTime = PeaceLink::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->whereNotNull('released_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, released_at)) as avg_hours')
            ->first();

        return $this->success([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'by_status' => $stats,
            'completion' => [
                'total' => $completionStats->total,
                'completed' => $completionStats->completed,
                'cancelled' => $completionStats->cancelled,
                'disputed' => $completionStats->disputed,
                'completion_rate' => $completionStats->total > 0 
                    ? round(($completionStats->completed / $completionStats->total) * 100, 1) 
                    : 0,
            ],
            'avg_completion_hours' => round($avgCompletionTime->avg_hours ?? 0, 1),
        ]);
    }

    /**
     * Get revenue reports
     */
    public function revenue(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        // Platform fees from PeaceLinks
        $peacelinkFees = PeaceLink::whereBetween('released_at', [$startDate, $endDate . ' 23:59:59'])
            ->sum('platform_fee');

        // Cashout fees
        $cashoutFees = CashoutRequest::whereBetween('completed_at', [$startDate, $endDate . ' 23:59:59'])
            ->where('status', 'completed')
            ->sum('fee');

        // Add money fees
        $addMoneyFees = Transaction::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->where('type', 'add_money')
            ->sum('fee');

        // Daily breakdown
        $dailyRevenue = DB::table('platform_fees')
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                DATE(created_at) as date,
                type,
                SUM(amount) as total
            ')
            ->groupBy('date', 'type')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(function($items, $date) {
                return [
                    'date' => $date,
                    'peacelink_fees' => $items->where('type', 'peacelink_fee')->sum('total'),
                    'cashout_fees' => $items->where('type', 'cashout_fee')->sum('total'),
                    'add_money_fees' => $items->where('type', 'add_money_fee')->sum('total'),
                    'total' => $items->sum('total'),
                ];
            })->values();

        $totalRevenue = $peacelinkFees + $cashoutFees + $addMoneyFees;

        return $this->success([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'total' => $totalRevenue,
                'peacelink_fees' => $peacelinkFees,
                'cashout_fees' => $cashoutFees,
                'add_money_fees' => $addMoneyFees,
            ],
            'breakdown' => [
                'peacelink_percentage' => $totalRevenue > 0 ? round(($peacelinkFees / $totalRevenue) * 100, 1) : 0,
                'cashout_percentage' => $totalRevenue > 0 ? round(($cashoutFees / $totalRevenue) * 100, 1) : 0,
                'add_money_percentage' => $totalRevenue > 0 ? round(($addMoneyFees / $totalRevenue) * 100, 1) : 0,
            ],
            'daily' => $dailyRevenue,
        ]);
    }
}
