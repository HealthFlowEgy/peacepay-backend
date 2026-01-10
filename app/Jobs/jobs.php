<?php

/**
 * PeacePay Queue Jobs
 * 
 * Background jobs for async processing.
 * Split into separate files in production: app/Jobs/*.php
 */

namespace App\Jobs;

use App\Models\User;
use App\Models\PeaceLink;
use App\Models\Transaction;
use App\Models\CashoutRequest;
use App\Models\PlatformFee;
use App\Models\Notification;
use App\Services\SmsService;
use App\Services\PushNotificationService;
use App\Services\EmailService;
use App\Services\PaymentGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

// ============================================================================
// Notification Jobs
// ============================================================================

/**
 * Send SMS notification
 */
class SendSmsNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 30;

    public function __construct(
        public string $phone,
        public string $message,
        public ?string $templateId = null
    ) {}

    public function handle(SmsService $smsService): void
    {
        try {
            $result = $smsService->send($this->phone, $this->message, $this->templateId);
            
            Log::info('SMS sent successfully', [
                'phone' => substr($this->phone, 0, 5) . '****',
                'message_id' => $result['message_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('SMS sending failed', [
                'phone' => substr($this->phone, 0, 5) . '****',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SMS job failed permanently', [
            'phone' => substr($this->phone, 0, 5) . '****',
            'error' => $exception->getMessage(),
        ]);
    }

    public function middleware(): array
    {
        return [
            new RateLimited('sms'),
        ];
    }
}

/**
 * Send push notification via FCM
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public User $user,
        public string $title,
        public string $body,
        public array $data = []
    ) {}

    public function handle(PushNotificationService $pushService): void
    {
        if (!$this->user->fcm_token) {
            Log::info('User has no FCM token, skipping push', [
                'user_id' => $this->user->id,
            ]);
            return;
        }

        try {
            $result = $pushService->send(
                $this->user->fcm_token,
                $this->title,
                $this->body,
                $this->data
            );

            Log::info('Push notification sent', [
                'user_id' => $this->user->id,
                'title' => $this->title,
            ]);

            // Also create in-app notification
            $this->createInAppNotification();

        } catch (\Exception $e) {
            // If token is invalid, clear it
            if (str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'unregistered')) {
                $this->user->update(['fcm_token' => null]);
                Log::info('Cleared invalid FCM token', ['user_id' => $this->user->id]);
            }
            throw $e;
        }
    }

    private function createInAppNotification(): void
    {
        Notification::create([
            'id' => Str::uuid(),
            'type' => 'App\Notifications\PushNotification',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id' => $this->user->id,
            'data' => json_encode([
                'title' => $this->title,
                'message' => $this->body,
                'payload' => $this->data,
            ]),
        ]);
    }
}

/**
 * Send email notification
 */
class SendEmailNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public string $email,
        public string $subject,
        public string $body,
        public ?string $template = null,
        public array $data = []
    ) {}

    public function handle(EmailService $emailService): void
    {
        try {
            $emailService->send(
                $this->email,
                $this->subject,
                $this->body,
                $this->template,
                $this->data
            );

            Log::info('Email sent', [
                'email' => $this->email,
                'subject' => $this->subject,
            ]);
        } catch (\Exception $e) {
            Log::error('Email sending failed', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

// ============================================================================
// Payment Processing Jobs
// ============================================================================

/**
 * Process pending add money payment
 */
class ProcessAddMoneyPayment implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $uniqueFor = 300; // 5 minutes

    public function __construct(
        public Transaction $transaction,
        public string $paymentMethod,
        public array $paymentData
    ) {}

    public function uniqueId(): string
    {
        return $this->transaction->uuid;
    }

    public function handle(PaymentGatewayService $paymentService): void
    {
        Log::info('Processing add money payment', [
            'transaction_id' => $this->transaction->uuid,
            'method' => $this->paymentMethod,
        ]);

        DB::beginTransaction();
        try {
            // Verify payment with gateway
            $result = $paymentService->verifyPayment(
                $this->paymentMethod,
                $this->paymentData['reference']
            );

            if ($result['status'] === 'success') {
                // Update transaction
                $this->transaction->update([
                    'status' => 'completed',
                    'payment_reference' => $result['gateway_reference'],
                    'metadata' => array_merge(
                        $this->transaction->metadata ?? [],
                        ['gateway_response' => $result]
                    ),
                ]);

                // Credit wallet
                $wallet = $this->transaction->wallet;
                $wallet->increment('balance', $this->transaction->amount);

                Log::info('Add money completed', [
                    'transaction_id' => $this->transaction->uuid,
                    'amount' => $this->transaction->amount,
                ]);

                // Fire event
                event(new \App\Events\MoneyAdded(
                    $wallet->user,
                    $this->transaction->amount,
                    $this->paymentMethod,
                    $this->transaction
                ));

            } elseif ($result['status'] === 'pending') {
                // Re-queue for later check
                self::dispatch($this->transaction, $this->paymentMethod, $this->paymentData)
                    ->delay(now()->addMinutes(2));
                    
            } else {
                // Payment failed
                $this->transaction->update([
                    'status' => 'failed',
                    'metadata' => array_merge(
                        $this->transaction->metadata ?? [],
                        ['failure_reason' => $result['message'] ?? 'Payment failed']
                    ),
                ]);

                Log::warning('Add money payment failed', [
                    'transaction_id' => $this->transaction->uuid,
                    'reason' => $result['message'] ?? 'Unknown',
                ]);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Add money processing error', [
                'transaction_id' => $this->transaction->uuid,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->transaction->uuid),
        ];
    }
}

/**
 * Process cashout to bank or wallet
 */
class ProcessCashout implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;
    public int $uniqueFor = 600; // 10 minutes

    public function __construct(
        public CashoutRequest $cashout
    ) {}

    public function uniqueId(): string
    {
        return $this->cashout->uuid;
    }

    public function handle(PaymentGatewayService $paymentService): void
    {
        if ($this->cashout->status !== 'processing') {
            Log::info('Cashout not in processing status, skipping', [
                'cashout_id' => $this->cashout->uuid,
                'status' => $this->cashout->status,
            ]);
            return;
        }

        Log::info('Processing cashout', [
            'cashout_id' => $this->cashout->uuid,
            'method' => $this->cashout->method,
            'amount' => $this->cashout->net_amount,
        ]);

        try {
            if ($this->cashout->method === 'bank') {
                $result = $paymentService->bankTransfer(
                    $this->cashout->bank_code,
                    $this->cashout->account_number,
                    $this->cashout->account_holder_name,
                    $this->cashout->net_amount,
                    $this->cashout->reference
                );
            } else {
                $result = $paymentService->walletTransfer(
                    $this->cashout->wallet_provider,
                    $this->cashout->wallet_phone,
                    $this->cashout->net_amount,
                    $this->cashout->reference
                );
            }

            if ($result['status'] === 'success') {
                $this->cashout->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'gateway_reference' => $result['reference'] ?? null,
                ]);

                // Clear pending cashout from wallet
                $wallet = $this->cashout->wallet;
                $wallet->decrement('pending_cashout', $this->cashout->amount);

                // Fire event
                event(new \App\Events\CashoutCompleted($this->cashout));

                Log::info('Cashout completed', [
                    'cashout_id' => $this->cashout->uuid,
                ]);

            } elseif ($result['status'] === 'pending') {
                // Still processing, check again later
                self::dispatch($this->cashout)->delay(now()->addMinutes(5));
                
            } else {
                $this->handleCashoutFailure($result['message'] ?? 'Transfer failed');
            }

        } catch (\Exception $e) {
            Log::error('Cashout processing error', [
                'cashout_id' => $this->cashout->uuid,
                'error' => $e->getMessage(),
            ]);
            
            // After max retries, fail the cashout
            if ($this->attempts() >= $this->tries) {
                $this->handleCashoutFailure($e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    private function handleCashoutFailure(string $reason): void
    {
        DB::transaction(function () use ($reason) {
            // Update cashout status
            $this->cashout->update([
                'status' => 'failed',
                'failure_reason' => $reason,
            ]);

            // Return funds to wallet balance
            $wallet = $this->cashout->wallet;
            $wallet->decrement('pending_cashout', $this->cashout->amount);
            $wallet->increment('balance', $this->cashout->amount);

            // Fire event
            event(new \App\Events\CashoutFailed($this->cashout, $reason));
        });

        Log::warning('Cashout failed', [
            'cashout_id' => $this->cashout->uuid,
            'reason' => $reason,
        ]);
    }

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->cashout->uuid),
        ];
    }
}

// ============================================================================
// PeaceLink Jobs
// ============================================================================

/**
 * Auto-release funds after delivery timeout
 */
class AutoReleasePeaceLinkFunds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public PeaceLink $peaceLink
    ) {}

    public function handle(): void
    {
        // Only auto-release if still in delivered status after timeout
        if ($this->peaceLink->status !== 'delivered') {
            Log::info('PeaceLink no longer in delivered status, skipping auto-release', [
                'peacelink_id' => $this->peaceLink->uuid,
                'current_status' => $this->peaceLink->status,
            ]);
            return;
        }

        // Check if 72 hours have passed since delivery
        $deliveredAt = $this->peaceLink->delivered_at;
        if ($deliveredAt->addHours(72)->isFuture()) {
            // Re-schedule for later
            self::dispatch($this->peaceLink)
                ->delay($deliveredAt->addHours(72));
            return;
        }

        Log::info('Auto-releasing PeaceLink funds', [
            'peacelink_id' => $this->peaceLink->uuid,
        ]);

        // Use PeaceLinkService to release funds
        app(\App\Services\PeaceLinkService::class)->releaseFunds($this->peaceLink);
    }
}

/**
 * Send reminder for pending PeaceLink
 */
class SendPeaceLinkReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public PeaceLink $peaceLink,
        public string $reminderType // 'merchant_accept', 'buyer_confirm', 'dispute_respond'
    ) {}

    public function handle(): void
    {
        // Check if reminder is still needed
        if (!$this->isReminderNeeded()) {
            return;
        }

        $user = $this->getTargetUser();
        $message = $this->getReminderMessage();

        SendPushNotification::dispatch($user, 'تذكير', $message, [
            'type' => 'reminder',
            'reminder_type' => $this->reminderType,
            'peacelink_id' => $this->peaceLink->uuid,
        ]);

        SendSmsNotification::dispatch($user->phone, "PeacePay: {$message}");

        Log::info('PeaceLink reminder sent', [
            'peacelink_id' => $this->peaceLink->uuid,
            'type' => $this->reminderType,
        ]);
    }

    private function isReminderNeeded(): bool
    {
        return match($this->reminderType) {
            'merchant_accept' => $this->peaceLink->status === 'pending',
            'buyer_confirm' => $this->peaceLink->status === 'delivered',
            'dispute_respond' => $this->peaceLink->activeDispute?->status === 'pending',
            default => false,
        };
    }

    private function getTargetUser(): User
    {
        return match($this->reminderType) {
            'merchant_accept' => $this->peaceLink->merchant,
            'buyer_confirm' => $this->peaceLink->buyer,
            'dispute_respond' => $this->peaceLink->activeDispute->respondent,
            default => $this->peaceLink->buyer,
        };
    }

    private function getReminderMessage(): string
    {
        return match($this->reminderType) {
            'merchant_accept' => "لديك طلب PeaceLink بانتظار الموافقة. رقم المرجع: {$this->peaceLink->reference}",
            'buyer_confirm' => "يرجى تأكيد استلام الطلب {$this->peaceLink->reference} أو فتح نزاع.",
            'dispute_respond' => "يرجى الرد على النزاع المفتوح للطلب {$this->peaceLink->reference}",
            default => "لديك إجراء مطلوب للطلب {$this->peaceLink->reference}",
        };
    }
}

/**
 * Cancel expired pending PeaceLinks
 */
class CancelExpiredPeaceLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Find pending PeaceLinks older than 48 hours
        $expiredLinks = PeaceLink::where('status', 'pending')
            ->where('created_at', '<', now()->subHours(48))
            ->get();

        foreach ($expiredLinks as $peaceLink) {
            DB::transaction(function () use ($peaceLink) {
                // Refund buyer if funds were held
                if ($peaceLink->buyer->wallet->hold_balance >= $peaceLink->total_amount) {
                    $wallet = $peaceLink->buyer->wallet;
                    $wallet->decrement('hold_balance', $peaceLink->total_amount);
                    $wallet->increment('balance', $peaceLink->total_amount);
                }

                $peaceLink->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => 'system',
                    'cancellation_reason' => 'انتهت مهلة قبول الطلب',
                ]);

                event(new \App\Events\PeaceLinkCancelled(
                    $peaceLink,
                    'system',
                    'انتهت مهلة قبول الطلب',
                    true
                ));
            });

            Log::info('Expired PeaceLink cancelled', [
                'peacelink_id' => $peaceLink->uuid,
            ]);
        }

        Log::info('Expired PeaceLinks cleanup completed', [
            'cancelled_count' => $expiredLinks->count(),
        ]);
    }
}

// ============================================================================
// Statistics & Reporting Jobs
// ============================================================================

/**
 * Update user statistics
 */
class UpdateUserStatistics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $statType
    ) {}

    public function handle(): void
    {
        $stats = $this->user->statistics ?? [];

        switch ($this->statType) {
            case 'merchant_completed':
                $stats['total_sales'] = ($stats['total_sales'] ?? 0) + 1;
                $stats['last_sale_at'] = now()->toISOString();
                break;

            case 'buyer_completed':
                $stats['total_purchases'] = ($stats['total_purchases'] ?? 0) + 1;
                $stats['last_purchase_at'] = now()->toISOString();
                break;

            case 'dispute_opened':
                $stats['disputes_opened'] = ($stats['disputes_opened'] ?? 0) + 1;
                break;

            case 'dispute_won':
                $stats['disputes_won'] = ($stats['disputes_won'] ?? 0) + 1;
                break;
        }

        $this->user->update(['statistics' => $stats]);

        Log::debug('User statistics updated', [
            'user_id' => $this->user->id,
            'stat_type' => $this->statType,
        ]);
    }
}

/**
 * Record platform fee for reporting
 */
class RecordPlatformFee implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?PeaceLink $peaceLink,
        public float $amount,
        public string $feeType,
        public ?int $relatedId = null
    ) {}

    public function handle(): void
    {
        PlatformFee::create([
            'peace_link_id' => $this->peaceLink?->id,
            'transaction_id' => $this->relatedId,
            'type' => $this->feeType,
            'amount' => $this->amount,
            'currency' => 'EGP',
            'description' => $this->getDescription(),
        ]);

        Log::info('Platform fee recorded', [
            'type' => $this->feeType,
            'amount' => $this->amount,
            'peacelink_id' => $this->peaceLink?->id,
        ]);
    }

    private function getDescription(): string
    {
        return match($this->feeType) {
            'peacelink_fee' => "رسوم PeaceLink {$this->peaceLink?->reference}",
            'cashout_fee' => 'رسوم سحب',
            'add_money_fee' => 'رسوم إضافة رصيد',
            default => 'رسوم منصة',
        };
    }
}

/**
 * Generate daily report
 */
class GenerateDailyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?Carbon $date = null
    ) {
        $this->date = $date ?? now()->subDay();
    }

    public function handle(): void
    {
        $startOfDay = $this->date->copy()->startOfDay();
        $endOfDay = $this->date->copy()->endOfDay();

        $report = [
            'date' => $this->date->format('Y-m-d'),
            'users' => [
                'new_registrations' => User::whereBetween('created_at', [$startOfDay, $endOfDay])->count(),
                'active_users' => User::whereBetween('last_login_at', [$startOfDay, $endOfDay])->count(),
            ],
            'peacelinks' => [
                'created' => PeaceLink::whereBetween('created_at', [$startOfDay, $endOfDay])->count(),
                'completed' => PeaceLink::whereBetween('released_at', [$startOfDay, $endOfDay])->count(),
                'cancelled' => PeaceLink::whereBetween('cancelled_at', [$startOfDay, $endOfDay])->count(),
                'volume' => PeaceLink::whereBetween('released_at', [$startOfDay, $endOfDay])->sum('item_amount'),
            ],
            'transactions' => [
                'count' => Transaction::whereBetween('created_at', [$startOfDay, $endOfDay])->count(),
                'volume' => Transaction::whereBetween('created_at', [$startOfDay, $endOfDay])
                    ->where('direction', 'credit')
                    ->sum('amount'),
            ],
            'revenue' => [
                'platform_fees' => PlatformFee::whereBetween('created_at', [$startOfDay, $endOfDay])->sum('amount'),
            ],
            'disputes' => [
                'opened' => \App\Models\Dispute::whereBetween('created_at', [$startOfDay, $endOfDay])->count(),
                'resolved' => \App\Models\Dispute::whereBetween('resolved_at', [$startOfDay, $endOfDay])->count(),
            ],
        ];

        // Store report
        \App\Models\DailyReport::updateOrCreate(
            ['date' => $this->date->format('Y-m-d')],
            ['data' => $report]
        );

        // Send to admin
        SendEmailNotification::dispatch(
            config('peacepay.admin_email'),
            "تقرير يومي - {$this->date->format('Y-m-d')}",
            $this->formatReportEmail($report),
            'reports.daily',
            $report
        );

        Log::info('Daily report generated', [
            'date' => $this->date->format('Y-m-d'),
        ]);
    }

    private function formatReportEmail(array $report): string
    {
        return "التقرير اليومي لـ {$report['date']}\n\n" .
            "المستخدمون الجدد: {$report['users']['new_registrations']}\n" .
            "PeaceLinks مكتملة: {$report['peacelinks']['completed']}\n" .
            "حجم المعاملات: {$report['peacelinks']['volume']} جنيه\n" .
            "إيرادات المنصة: {$report['revenue']['platform_fees']} جنيه";
    }
}

// ============================================================================
// Cleanup Jobs
// ============================================================================

/**
 * Clean up expired OTPs
 */
class CleanupExpiredOtps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $deleted = \App\Models\Otp::where('expires_at', '<', now())->delete();

        Log::info('Expired OTPs cleaned up', ['deleted_count' => $deleted]);
    }
}

/**
 * Clean up old notifications
 */
class CleanupOldNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Delete read notifications older than 30 days
        $deletedRead = Notification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(30))
            ->delete();

        // Delete unread notifications older than 90 days
        $deletedUnread = Notification::whereNull('read_at')
            ->where('created_at', '<', now()->subDays(90))
            ->delete();

        Log::info('Old notifications cleaned up', [
            'deleted_read' => $deletedRead,
            'deleted_unread' => $deletedUnread,
        ]);
    }
}

/**
 * Archive old transactions for reporting
 */
class ArchiveOldTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Move transactions older than 2 years to archive table
        $cutoffDate = now()->subYears(2);

        $archived = DB::table('transactions')
            ->where('created_at', '<', $cutoffDate)
            ->orderBy('id')
            ->limit(1000)
            ->get();

        if ($archived->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($archived) {
            // Insert into archive table
            DB::table('transactions_archive')->insert(
                $archived->map(fn($t) => (array) $t)->toArray()
            );

            // Delete from main table
            DB::table('transactions')
                ->whereIn('id', $archived->pluck('id'))
                ->delete();
        });

        Log::info('Transactions archived', ['count' => $archived->count()]);

        // Continue archiving if more exist
        if ($archived->count() === 1000) {
            self::dispatch()->delay(now()->addMinutes(5));
        }
    }
}


// ============================================================================
// Console Kernel Schedule (Add to app/Console/Kernel.php)
// ============================================================================

/*
protected function schedule(Schedule $schedule): void
{
    // Cleanup jobs
    $schedule->job(new CleanupExpiredOtps)->hourly();
    $schedule->job(new CleanupOldNotifications)->daily();
    $schedule->job(new ArchiveOldTransactions)->weekly();

    // Cancel expired PeaceLinks
    $schedule->job(new CancelExpiredPeaceLinks)->everyFourHours();

    // Daily report
    $schedule->job(new GenerateDailyReport)->dailyAt('06:00');

    // Reminders for pending actions
    $schedule->call(function () {
        // Send reminders for pending merchant acceptance (after 12 hours)
        PeaceLink::where('status', 'pending')
            ->where('created_at', '<', now()->subHours(12))
            ->where('created_at', '>', now()->subHours(13))
            ->each(function ($pl) {
                SendPeaceLinkReminder::dispatch($pl, 'merchant_accept');
            });

        // Send reminders for buyer confirmation (after 24 hours of delivery)
        PeaceLink::where('status', 'delivered')
            ->where('delivered_at', '<', now()->subHours(24))
            ->where('delivered_at', '>', now()->subHours(25))
            ->each(function ($pl) {
                SendPeaceLinkReminder::dispatch($pl, 'buyer_confirm');
            });
    })->hourly();
}
*/
