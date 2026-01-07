<?php

/**
 * PeacePay Events and Listeners
 * 
 * Event-driven architecture for PeaceLink state changes and notifications.
 * Split into separate files in production:
 * - app/Events/*.php
 * - app/Listeners/*.php
 * - app/Providers/EventServiceProvider.php
 */

namespace App\Events;

use App\Models\PeaceLink;
use App\Models\Dispute;
use App\Models\CashoutRequest;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ============================================================================
// PeaceLink Events
// ============================================================================

/**
 * Fired when a new PeaceLink is created
 */
class PeaceLinkCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;
    public User $buyer;
    public User $merchant;

    public function __construct(PeaceLink $peaceLink)
    {
        $this->peaceLink = $peaceLink;
        $this->buyer = $peaceLink->buyer;
        $this->merchant = $peaceLink->merchant;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->merchant->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'peacelink.created';
    }

    public function broadcastWith(): array
    {
        return [
            'peacelink_id' => $this->peaceLink->uuid,
            'reference' => $this->peaceLink->reference,
            'buyer_name' => $this->buyer->name,
            'product' => $this->peaceLink->product_description,
            'amount' => $this->peaceLink->item_amount,
        ];
    }
}

/**
 * Fired when funds are held in escrow
 */
class PeaceLinkFunded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;

    public function __construct(PeaceLink $peaceLink)
    {
        $this->peaceLink = $peaceLink;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->peaceLink->merchant_id),
            new PrivateChannel('user.' . $this->peaceLink->buyer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'peacelink.funded';
    }
}

/**
 * Fired when merchant accepts the PeaceLink
 */
class PeaceLinkAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;

    public function __construct(PeaceLink $peaceLink)
    {
        $this->peaceLink = $peaceLink;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->peaceLink->buyer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'peacelink.accepted';
    }
}

/**
 * Fired when merchant rejects the PeaceLink
 */
class PeaceLinkRejected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;
    public string $reason;

    public function __construct(PeaceLink $peaceLink, string $reason = '')
    {
        $this->peaceLink = $peaceLink;
        $this->reason = $reason;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->peaceLink->buyer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'peacelink.rejected';
    }
}

/**
 * Fired when a DSP is assigned
 */
class DspAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;
    public User $dsp;

    public function __construct(PeaceLink $peaceLink)
    {
        $this->peaceLink = $peaceLink;
        $this->dsp = $peaceLink->dsp;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->peaceLink->buyer_id),
            new PrivateChannel('user.' . $this->peaceLink->merchant_id),
            new PrivateChannel('user.' . $this->peaceLink->dsp_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'peacelink.dsp_assigned';
    }
}

/**
 * Fired when shipment starts (in transit)
 */
class PeaceLinkInTransit implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;
    public ?string $trackingCode;

    public function __construct(PeaceLink $peaceLink)
    {
        $this->peaceLink = $peaceLink;
        $this->trackingCode = $peaceLink->tracking_code;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->peaceLink->buyer_id),
            new PrivateChannel('user.' . $this->peaceLink->merchant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'peacelink.in_transit';
    }
}

/**
 * Fired when delivery OTP is sent
 */
class DeliveryOtpSent
{
    use Dispatchable, SerializesModels;

    public PeaceLink $peaceLink;
    public string $otp;
    public string $phone;

    public function __construct(PeaceLink $peaceLink, string $otp)
    {
        $this->peaceLink = $peaceLink;
        $this->otp = $otp;
        $this->phone = $peaceLink->buyer->phone;
    }
}

/**
 * Fired when buyer confirms delivery
 */
class PeaceLinkDelivered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;

    public function __construct(PeaceLink $peaceLink)
    {
        $this->peaceLink = $peaceLink;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->peaceLink->merchant_id),
        ];

        if ($this->peaceLink->dsp_id) {
            $channels[] = new PrivateChannel('user.' . $this->peaceLink->dsp_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'peacelink.delivered';
    }
}

/**
 * Fired when funds are released to merchant
 */
class PeaceLinkReleased implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;
    public float $merchantAmount;
    public float $platformFee;

    public function __construct(PeaceLink $peaceLink, float $merchantAmount, float $platformFee)
    {
        $this->peaceLink = $peaceLink;
        $this->merchantAmount = $merchantAmount;
        $this->platformFee = $platformFee;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->peaceLink->merchant_id),
            new PrivateChannel('user.' . $this->peaceLink->buyer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'peacelink.released';
    }
}

/**
 * Fired when PeaceLink is cancelled
 */
class PeaceLinkCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PeaceLink $peaceLink;
    public string $cancelledBy;
    public string $reason;
    public bool $refundIssued;

    public function __construct(PeaceLink $peaceLink, string $cancelledBy, string $reason, bool $refundIssued = false)
    {
        $this->peaceLink = $peaceLink;
        $this->cancelledBy = $cancelledBy;
        $this->reason = $reason;
        $this->refundIssued = $refundIssued;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->peaceLink->buyer_id),
            new PrivateChannel('user.' . $this->peaceLink->merchant_id),
        ];

        if ($this->peaceLink->dsp_id) {
            $channels[] = new PrivateChannel('user.' . $this->peaceLink->dsp_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'peacelink.cancelled';
    }
}

// ============================================================================
// Dispute Events
// ============================================================================

/**
 * Fired when a dispute is opened
 */
class DisputeOpened implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Dispute $dispute;
    public PeaceLink $peaceLink;
    public User $openedBy;
    public User $respondent;

    public function __construct(Dispute $dispute)
    {
        $this->dispute = $dispute;
        $this->peaceLink = $dispute->peaceLink;
        $this->openedBy = $dispute->opener;
        $this->respondent = $dispute->respondent;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->respondent->id),
            new PrivateChannel('admin.disputes'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dispute.opened';
    }
}

/**
 * Fired when respondent responds to dispute
 */
class DisputeResponseAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Dispute $dispute;

    public function __construct(Dispute $dispute)
    {
        $this->dispute = $dispute;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->dispute->opened_by),
            new PrivateChannel('admin.disputes'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dispute.response_added';
    }
}

/**
 * Fired when dispute is resolved
 */
class DisputeResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Dispute $dispute;
    public string $resolution;
    public ?float $refundAmount;

    public function __construct(Dispute $dispute, string $resolution, ?float $refundAmount = null)
    {
        $this->dispute = $dispute;
        $this->resolution = $resolution;
        $this->refundAmount = $refundAmount;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->dispute->opened_by),
            new PrivateChannel('user.' . $this->dispute->respondent_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dispute.resolved';
    }
}

// ============================================================================
// Cashout Events
// ============================================================================

/**
 * Fired when cashout is requested
 */
class CashoutRequested
{
    use Dispatchable, SerializesModels;

    public CashoutRequest $cashout;
    public User $user;

    public function __construct(CashoutRequest $cashout)
    {
        $this->cashout = $cashout;
        $this->user = $cashout->user;
    }
}

/**
 * Fired when cashout is being processed
 */
class CashoutProcessing implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public CashoutRequest $cashout;

    public function __construct(CashoutRequest $cashout)
    {
        $this->cashout = $cashout;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->cashout->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cashout.processing';
    }
}

/**
 * Fired when cashout is completed
 */
class CashoutCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public CashoutRequest $cashout;

    public function __construct(CashoutRequest $cashout)
    {
        $this->cashout = $cashout;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->cashout->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cashout.completed';
    }
}

/**
 * Fired when cashout fails
 */
class CashoutFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public CashoutRequest $cashout;
    public string $reason;

    public function __construct(CashoutRequest $cashout, string $reason)
    {
        $this->cashout = $cashout;
        $this->reason = $reason;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->cashout->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cashout.failed';
    }
}

// ============================================================================
// Wallet Events
// ============================================================================

/**
 * Fired when money is added to wallet
 */
class MoneyAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public float $amount;
    public string $method;
    public Transaction $transaction;

    public function __construct(User $user, float $amount, string $method, Transaction $transaction)
    {
        $this->user = $user;
        $this->amount = $amount;
        $this->method = $method;
        $this->transaction = $transaction;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->user->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'wallet.money_added';
    }
}

/**
 * Fired when P2P transfer is received
 */
class MoneyReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $recipient;
    public User $sender;
    public float $amount;
    public Transaction $transaction;

    public function __construct(User $recipient, User $sender, float $amount, Transaction $transaction)
    {
        $this->recipient = $recipient;
        $this->sender = $sender;
        $this->amount = $amount;
        $this->transaction = $transaction;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->recipient->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'wallet.money_received';
    }
}

// ============================================================================
// KYC Events
// ============================================================================

/**
 * Fired when KYC is approved
 */
class KycApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public string $newLevel;

    public function __construct(User $user, string $newLevel)
    {
        $this->user = $user;
        $this->newLevel = $newLevel;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->user->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'kyc.approved';
    }
}

/**
 * Fired when KYC is rejected
 */
class KycRejected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public string $reason;

    public function __construct(User $user, string $reason)
    {
        $this->user = $user;
        $this->reason = $reason;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->user->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'kyc.rejected';
    }
}


// ============================================================================
// LISTENERS
// ============================================================================

namespace App\Listeners;

use App\Events\*;
use App\Jobs\SendSmsNotification;
use App\Jobs\SendPushNotification;
use App\Jobs\RecordPlatformFee;
use App\Jobs\UpdateUserStatistics;
use App\Jobs\SendEmailNotification;
use App\Models\Notification;
use App\Models\PeaceLinkTimeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

// ============================================================================
// PeaceLink Listeners
// ============================================================================

/**
 * Handle PeaceLink created event
 */
class SendPeaceLinkCreatedNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PeaceLinkCreated $event): void
    {
        $peaceLink = $event->peaceLink;
        $merchant = $event->merchant;
        $buyer = $event->buyer;

        // Send push notification to merchant
        SendPushNotification::dispatch(
            $merchant,
            'طلب PeaceLink جديد',
            "لديك طلب جديد من {$buyer->name} بقيمة {$peaceLink->item_amount} جنيه",
            [
                'type' => 'peacelink_new',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );

        // Send SMS to merchant
        SendSmsNotification::dispatch(
            $merchant->phone,
            "PeacePay: طلب جديد من {$buyer->name} بقيمة {$peaceLink->item_amount} جنيه. رقم المرجع: {$peaceLink->reference}"
        );

        // Create in-app notification
        Notification::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => 'App\Notifications\NewPeaceLinkNotification',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id' => $merchant->id,
            'data' => json_encode([
                'title' => 'طلب PeaceLink جديد',
                'message' => "لديك طلب جديد من {$buyer->name}",
                'payload' => [
                    'peacelink_id' => $peaceLink->uuid,
                    'reference' => $peaceLink->reference,
                ],
                'action_url' => "/peacelinks/{$peaceLink->uuid}",
            ]),
        ]);

        // Record timeline event
        PeaceLinkTimeline::create([
            'peace_link_id' => $peaceLink->id,
            'event' => 'created',
            'actor_id' => $buyer->id,
            'description' => 'تم إنشاء الطلب',
        ]);

        Log::info('PeaceLink created notifications sent', [
            'peacelink_id' => $peaceLink->id,
            'merchant_id' => $merchant->id,
        ]);
    }
}

/**
 * Handle PeaceLink accepted event
 */
class SendPeaceLinkAcceptedNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PeaceLinkAccepted $event): void
    {
        $peaceLink = $event->peaceLink;
        $buyer = $peaceLink->buyer;
        $merchant = $peaceLink->merchant;

        // Notify buyer
        SendPushNotification::dispatch(
            $buyer,
            'تم قبول طلبك',
            "قام {$merchant->name} بقبول طلبك. جاري تجهيز الشحنة.",
            [
                'type' => 'peacelink_accepted',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );

        // Record timeline
        PeaceLinkTimeline::create([
            'peace_link_id' => $peaceLink->id,
            'event' => 'accepted',
            'actor_id' => $merchant->id,
            'description' => 'قام البائع بقبول الطلب',
        ]);
    }
}

/**
 * Handle DSP assigned event
 */
class SendDspAssignedNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(DspAssigned $event): void
    {
        $peaceLink = $event->peaceLink;
        $buyer = $peaceLink->buyer;
        $dsp = $event->dsp;

        // Notify buyer
        SendPushNotification::dispatch(
            $buyer,
            'تم تعيين مندوب التوصيل',
            "سيقوم {$dsp->name} بتوصيل طلبك",
            [
                'type' => 'peacelink_dsp_assigned',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );

        // Notify DSP
        SendPushNotification::dispatch(
            $dsp,
            'طلب توصيل جديد',
            "لديك طلب توصيل جديد إلى {$peaceLink->delivery_city}",
            [
                'type' => 'dsp_new_order',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );

        // Record timeline
        PeaceLinkTimeline::create([
            'peace_link_id' => $peaceLink->id,
            'event' => 'dsp_assigned',
            'actor_id' => $dsp->id,
            'description' => "تم تعيين {$dsp->name} للتوصيل",
        ]);
    }
}

/**
 * Handle PeaceLink in transit event
 */
class SendInTransitNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PeaceLinkInTransit $event): void
    {
        $peaceLink = $event->peaceLink;
        $buyer = $peaceLink->buyer;

        // Notify buyer
        SendPushNotification::dispatch(
            $buyer,
            'طلبك في الطريق',
            $event->trackingCode 
                ? "رقم التتبع: {$event->trackingCode}" 
                : 'الشحنة في الطريق إليك',
            [
                'type' => 'peacelink_in_transit',
                'peacelink_id' => $peaceLink->uuid,
                'tracking_code' => $event->trackingCode,
            ]
        );

        // SMS with tracking
        if ($event->trackingCode) {
            SendSmsNotification::dispatch(
                $buyer->phone,
                "PeacePay: طلبك في الطريق. رقم التتبع: {$event->trackingCode}"
            );
        }

        // Record timeline
        PeaceLinkTimeline::create([
            'peace_link_id' => $peaceLink->id,
            'event' => 'in_transit',
            'description' => 'الشحنة في الطريق',
            'metadata' => ['tracking_code' => $event->trackingCode],
        ]);
    }
}

/**
 * Handle delivery OTP sent event
 */
class HandleDeliveryOtpSent implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(DeliveryOtpSent $event): void
    {
        // Send OTP via SMS
        SendSmsNotification::dispatch(
            $event->phone,
            "PeacePay: رمز تأكيد الاستلام هو {$event->otp}. لا تشارك هذا الرمز مع أي شخص."
        );

        // Also send push notification
        SendPushNotification::dispatch(
            $event->peaceLink->buyer,
            'رمز تأكيد الاستلام',
            'تم إرسال رمز التأكيد إلى رقم هاتفك',
            [
                'type' => 'delivery_otp',
                'peacelink_id' => $event->peaceLink->uuid,
            ]
        );

        Log::info('Delivery OTP sent', [
            'peacelink_id' => $event->peaceLink->id,
            'phone' => substr($event->phone, 0, 5) . '****',
        ]);
    }
}

/**
 * Handle PeaceLink delivered event
 */
class HandlePeaceLinkDelivered implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PeaceLinkDelivered $event): void
    {
        $peaceLink = $event->peaceLink;
        $merchant = $peaceLink->merchant;

        // Notify merchant
        SendPushNotification::dispatch(
            $merchant,
            'تم تأكيد الاستلام',
            "أكد المشتري استلام الطلب {$peaceLink->reference}. جاري تحويل المبلغ.",
            [
                'type' => 'peacelink_delivered',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );

        // Record timeline
        PeaceLinkTimeline::create([
            'peace_link_id' => $peaceLink->id,
            'event' => 'delivered',
            'actor_id' => $peaceLink->buyer_id,
            'description' => 'أكد المشتري استلام الطلب',
        ]);
    }
}

/**
 * Handle PeaceLink released (funds transferred)
 */
class HandlePeaceLinkReleased implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PeaceLinkReleased $event): void
    {
        $peaceLink = $event->peaceLink;
        $merchant = $peaceLink->merchant;

        // Notify merchant of payment
        SendPushNotification::dispatch(
            $merchant,
            'تم تحويل المبلغ',
            "تم إضافة {$event->merchantAmount} جنيه إلى محفظتك",
            [
                'type' => 'peacelink_payment_received',
                'peacelink_id' => $peaceLink->uuid,
                'amount' => $event->merchantAmount,
            ]
        );

        // SMS confirmation
        SendSmsNotification::dispatch(
            $merchant->phone,
            "PeacePay: تم إضافة {$event->merchantAmount} جنيه إلى محفظتك من الطلب {$peaceLink->reference}"
        );

        // Record platform fee
        RecordPlatformFee::dispatch($peaceLink, $event->platformFee, 'peacelink_fee');

        // Update statistics
        UpdateUserStatistics::dispatch($merchant, 'merchant_completed');
        UpdateUserStatistics::dispatch($peaceLink->buyer, 'buyer_completed');

        // Record timeline
        PeaceLinkTimeline::create([
            'peace_link_id' => $peaceLink->id,
            'event' => 'released',
            'description' => "تم تحويل {$event->merchantAmount} جنيه للبائع",
            'metadata' => [
                'merchant_amount' => $event->merchantAmount,
                'platform_fee' => $event->platformFee,
            ],
        ]);
    }
}

/**
 * Handle PeaceLink cancelled
 */
class HandlePeaceLinkCancelled implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PeaceLinkCancelled $event): void
    {
        $peaceLink = $event->peaceLink;
        $buyer = $peaceLink->buyer;
        $merchant = $peaceLink->merchant;

        // Determine who to notify (not the one who cancelled)
        $notifyList = [];
        if ($event->cancelledBy !== 'buyer') {
            $notifyList[] = $buyer;
        }
        if ($event->cancelledBy !== 'merchant') {
            $notifyList[] = $merchant;
        }
        if ($peaceLink->dsp_id && $event->cancelledBy !== 'dsp') {
            $notifyList[] = $peaceLink->dsp;
        }

        foreach ($notifyList as $user) {
            SendPushNotification::dispatch(
                $user,
                'تم إلغاء الطلب',
                $event->refundIssued 
                    ? "تم إلغاء الطلب {$peaceLink->reference} واسترداد المبلغ"
                    : "تم إلغاء الطلب {$peaceLink->reference}",
                [
                    'type' => 'peacelink_cancelled',
                    'peacelink_id' => $peaceLink->uuid,
                    'refund_issued' => $event->refundIssued,
                ]
            );
        }

        // Record timeline
        PeaceLinkTimeline::create([
            'peace_link_id' => $peaceLink->id,
            'event' => 'cancelled',
            'description' => "تم الإلغاء بواسطة {$this->getCancellerLabel($event->cancelledBy)}: {$event->reason}",
            'metadata' => [
                'cancelled_by' => $event->cancelledBy,
                'reason' => $event->reason,
                'refund_issued' => $event->refundIssued,
            ],
        ]);
    }

    private function getCancellerLabel(string $cancelledBy): string
    {
        return match($cancelledBy) {
            'buyer' => 'المشتري',
            'merchant' => 'البائع',
            'dsp' => 'مندوب التوصيل',
            'admin' => 'الإدارة',
            default => $cancelledBy,
        };
    }
}

// ============================================================================
// Dispute Listeners
// ============================================================================

/**
 * Handle dispute opened
 */
class HandleDisputeOpened implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(DisputeOpened $event): void
    {
        $dispute = $event->dispute;
        $respondent = $event->respondent;

        // Notify respondent
        SendPushNotification::dispatch(
            $respondent,
            'تم فتح نزاع',
            "تم فتح نزاع على الطلب {$dispute->peaceLink->reference}. يرجى الرد خلال 48 ساعة.",
            [
                'type' => 'dispute_opened',
                'dispute_id' => $dispute->uuid,
                'peacelink_id' => $dispute->peaceLink->uuid,
            ]
        );

        // SMS notification
        SendSmsNotification::dispatch(
            $respondent->phone,
            "PeacePay: تم فتح نزاع على الطلب {$dispute->peaceLink->reference}. يرجى الرد من التطبيق خلال 48 ساعة."
        );

        // Notify admin
        SendEmailNotification::dispatch(
            config('peacepay.admin_email'),
            'نزاع جديد',
            "تم فتح نزاع جديد برقم {$dispute->reference} على الطلب {$dispute->peaceLink->reference}"
        );
    }
}

/**
 * Handle dispute resolved
 */
class HandleDisputeResolved implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(DisputeResolved $event): void
    {
        $dispute = $event->dispute;
        $opener = $dispute->opener;
        $respondent = $dispute->respondent;

        $resolutionLabel = match($event->resolution) {
            'buyer' => 'لصالح المشتري',
            'merchant' => 'لصالح البائع',
            'split' => 'بالتساوي بين الطرفين',
            default => $event->resolution,
        };

        // Notify both parties
        foreach ([$opener, $respondent] as $user) {
            SendPushNotification::dispatch(
                $user,
                'تم حل النزاع',
                "تم حل النزاع {$resolutionLabel}",
                [
                    'type' => 'dispute_resolved',
                    'dispute_id' => $dispute->uuid,
                    'resolution' => $event->resolution,
                    'refund_amount' => $event->refundAmount,
                ]
            );
        }

        // SMS if refund issued
        if ($event->refundAmount && $event->resolution === 'buyer') {
            SendSmsNotification::dispatch(
                $opener->phone,
                "PeacePay: تم حل النزاع لصالحك. تم إضافة {$event->refundAmount} جنيه إلى محفظتك."
            );
        }
    }
}

// ============================================================================
// Cashout Listeners
// ============================================================================

/**
 * Handle cashout completed
 */
class HandleCashoutCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CashoutCompleted $event): void
    {
        $cashout = $event->cashout;
        $user = $cashout->user;

        SendPushNotification::dispatch(
            $user,
            'تم السحب بنجاح',
            "تم تحويل {$cashout->net_amount} جنيه إلى حسابك",
            [
                'type' => 'cashout_completed',
                'cashout_id' => $cashout->uuid,
                'amount' => $cashout->net_amount,
            ]
        );

        SendSmsNotification::dispatch(
            $user->phone,
            "PeacePay: تم تحويل {$cashout->net_amount} جنيه إلى حسابك بنجاح. رقم المرجع: {$cashout->reference}"
        );

        // Record platform fee from cashout
        RecordPlatformFee::dispatch(null, $cashout->fee, 'cashout_fee', $cashout->id);
    }
}

/**
 * Handle cashout failed
 */
class HandleCashoutFailed implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CashoutFailed $event): void
    {
        $cashout = $event->cashout;
        $user = $cashout->user;

        SendPushNotification::dispatch(
            $user,
            'فشل السحب',
            "فشل طلب السحب: {$event->reason}. تم إرجاع المبلغ إلى محفظتك.",
            [
                'type' => 'cashout_failed',
                'cashout_id' => $cashout->uuid,
                'reason' => $event->reason,
            ]
        );

        SendSmsNotification::dispatch(
            $user->phone,
            "PeacePay: فشل طلب السحب. السبب: {$event->reason}. تم إرجاع المبلغ إلى محفظتك."
        );
    }
}

// ============================================================================
// Wallet Listeners
// ============================================================================

/**
 * Handle money received (P2P transfer)
 */
class HandleMoneyReceived implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(MoneyReceived $event): void
    {
        SendPushNotification::dispatch(
            $event->recipient,
            'تم استلام تحويل',
            "استلمت {$event->amount} جنيه من {$event->sender->name}",
            [
                'type' => 'money_received',
                'transaction_id' => $event->transaction->uuid,
                'amount' => $event->amount,
                'sender_name' => $event->sender->name,
            ]
        );

        SendSmsNotification::dispatch(
            $event->recipient->phone,
            "PeacePay: استلمت {$event->amount} جنيه من {$event->sender->name}. الرصيد الحالي: {$event->recipient->wallet->balance} جنيه"
        );
    }
}

// ============================================================================
// KYC Listeners
// ============================================================================

/**
 * Handle KYC approved
 */
class HandleKycApproved implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(KycApproved $event): void
    {
        $levelLabel = match($event->newLevel) {
            'silver' => 'الفضي',
            'gold' => 'الذهبي',
            default => $event->newLevel,
        };

        SendPushNotification::dispatch(
            $event->user,
            'تمت ترقية حسابك',
            "تهانينا! تمت ترقية حسابك إلى المستوى {$levelLabel}",
            [
                'type' => 'kyc_approved',
                'new_level' => $event->newLevel,
            ]
        );

        SendSmsNotification::dispatch(
            $event->user->phone,
            "PeacePay: تهانينا! تمت ترقية حسابك إلى المستوى {$levelLabel}. استمتع بحدود أعلى للتحويلات."
        );
    }
}

/**
 * Handle KYC rejected
 */
class HandleKycRejected implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(KycRejected $event): void
    {
        SendPushNotification::dispatch(
            $event->user,
            'تم رفض طلب الترقية',
            "السبب: {$event->reason}",
            [
                'type' => 'kyc_rejected',
                'reason' => $event->reason,
            ]
        );
    }
}


// ============================================================================
// EventServiceProvider Registration
// ============================================================================

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     */
    protected $listen = [
        // PeaceLink Events
        \App\Events\PeaceLinkCreated::class => [
            \App\Listeners\SendPeaceLinkCreatedNotifications::class,
        ],
        \App\Events\PeaceLinkFunded::class => [
            // Add listeners as needed
        ],
        \App\Events\PeaceLinkAccepted::class => [
            \App\Listeners\SendPeaceLinkAcceptedNotifications::class,
        ],
        \App\Events\PeaceLinkRejected::class => [
            // Buyer notification handled in controller
        ],
        \App\Events\DspAssigned::class => [
            \App\Listeners\SendDspAssignedNotifications::class,
        ],
        \App\Events\PeaceLinkInTransit::class => [
            \App\Listeners\SendInTransitNotifications::class,
        ],
        \App\Events\DeliveryOtpSent::class => [
            \App\Listeners\HandleDeliveryOtpSent::class,
        ],
        \App\Events\PeaceLinkDelivered::class => [
            \App\Listeners\HandlePeaceLinkDelivered::class,
        ],
        \App\Events\PeaceLinkReleased::class => [
            \App\Listeners\HandlePeaceLinkReleased::class,
        ],
        \App\Events\PeaceLinkCancelled::class => [
            \App\Listeners\HandlePeaceLinkCancelled::class,
        ],

        // Dispute Events
        \App\Events\DisputeOpened::class => [
            \App\Listeners\HandleDisputeOpened::class,
        ],
        \App\Events\DisputeResponseAdded::class => [
            // Notify opener
        ],
        \App\Events\DisputeResolved::class => [
            \App\Listeners\HandleDisputeResolved::class,
        ],

        // Cashout Events
        \App\Events\CashoutRequested::class => [
            // Notify admin
        ],
        \App\Events\CashoutProcessing::class => [
            // User already notified via broadcast
        ],
        \App\Events\CashoutCompleted::class => [
            \App\Listeners\HandleCashoutCompleted::class,
        ],
        \App\Events\CashoutFailed::class => [
            \App\Listeners\HandleCashoutFailed::class,
        ],

        // Wallet Events
        \App\Events\MoneyAdded::class => [
            // Confirmation notification
        ],
        \App\Events\MoneyReceived::class => [
            \App\Listeners\HandleMoneyReceived::class,
        ],

        // KYC Events
        \App\Events\KycApproved::class => [
            \App\Listeners\HandleKycApproved::class,
        ],
        \App\Events\KycRejected::class => [
            \App\Listeners\HandleKycRejected::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
