<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Exception;

class PushNotificationService
{
    private string $projectId;
    private ?string $accessToken = null;
    private string $fcmUrl;
    
    // Notification types for analytics
    const TYPE_PEACELINK = 'peacelink';
    const TYPE_TRANSACTION = 'transaction';
    const TYPE_DISPUTE = 'dispute';
    const TYPE_CASHOUT = 'cashout';
    const TYPE_KYC = 'kyc';
    const TYPE_GENERAL = 'general';

    public function __construct()
    {
        $this->projectId = config('services.firebase.project_id');
        $this->fcmUrl = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
    }

    /**
     * Send push notification to a single user
     */
    public function send(
        string $fcmToken,
        string $title,
        string $body,
        array $data = [],
        ?string $imageUrl = null
    ): array {
        try {
            $message = $this->buildMessage($fcmToken, $title, $body, $data, $imageUrl);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, ['message' => $message]);

            if ($response->successful()) {
                Log::info('Push notification sent', [
                    'token' => substr($fcmToken, 0, 20) . '...',
                    'title' => $title,
                ]);
                
                return [
                    'success' => true,
                    'message_id' => $response->json('name'),
                ];
            }

            $error = $response->json();
            Log::error('FCM request failed', [
                'status' => $response->status(),
                'error' => $error,
            ]);

            // Handle specific error codes
            $this->handleFcmError($error, $fcmToken);

            return [
                'success' => false,
                'error' => $error['error']['message'] ?? 'Unknown error',
            ];

        } catch (Exception $e) {
            Log::error('Push notification exception', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send push notification to a user (handles multiple devices)
     */
    public function sendToUser(
        User $user,
        string $title,
        string $body,
        array $data = [],
        ?string $imageUrl = null,
        bool $saveNotification = true
    ): array {
        $results = [];
        
        // Get all device tokens for user
        $tokens = $this->getUserTokens($user);
        
        if (empty($tokens)) {
            Log::info('No FCM tokens for user', ['user_id' => $user->id]);
            
            // Still save in-app notification
            if ($saveNotification) {
                $this->saveInAppNotification($user, $title, $body, $data);
            }
            
            return [
                'success' => false,
                'error' => 'No device tokens',
                'in_app_saved' => $saveNotification,
            ];
        }

        foreach ($tokens as $token) {
            $results[] = $this->send($token, $title, $body, $data, $imageUrl);
        }

        // Save in-app notification
        if ($saveNotification) {
            $this->saveInAppNotification($user, $title, $body, $data);
        }

        $successCount = collect($results)->where('success', true)->count();

        return [
            'success' => $successCount > 0,
            'sent' => $successCount,
            'failed' => count($results) - $successCount,
            'in_app_saved' => $saveNotification,
        ];
    }

    /**
     * Send notification to multiple users
     */
    public function sendToUsers(
        array $users,
        string $title,
        string $body,
        array $data = [],
        ?string $imageUrl = null
    ): array {
        $results = [];

        foreach ($users as $user) {
            $results[$user->id] = $this->sendToUser($user, $title, $body, $data, $imageUrl);
        }

        return $results;
    }

    /**
     * Send notification to a topic (e.g., all users, DSPs, etc.)
     */
    public function sendToTopic(
        string $topic,
        string $title,
        string $body,
        array $data = [],
        ?string $imageUrl = null
    ): array {
        try {
            $message = [
                'topic' => $topic,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $this->prepareData($data),
                'android' => $this->getAndroidConfig(),
                'apns' => $this->getApnsConfig(),
            ];

            if ($imageUrl) {
                $message['notification']['image'] = $imageUrl;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, ['message' => $message]);

            if ($response->successful()) {
                Log::info('Topic notification sent', [
                    'topic' => $topic,
                    'title' => $title,
                ]);

                return [
                    'success' => true,
                    'message_id' => $response->json('name'),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Unknown error',
            ];

        } catch (Exception $e) {
            Log::error('Topic notification exception', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Subscribe user to a topic
     */
    public function subscribeToTopic(string $fcmToken, string $topic): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ])->post("https://iid.googleapis.com/iid/v1/{$fcmToken}/rel/topics/{$topic}");

            return $response->successful();
        } catch (Exception $e) {
            Log::error('Topic subscription failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Unsubscribe user from a topic
     */
    public function unsubscribeFromTopic(string $fcmToken, string $topic): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ])->delete("https://iid.googleapis.com/iid/v1/{$fcmToken}/rel/topics/{$topic}");

            return $response->successful();
        } catch (Exception $e) {
            Log::error('Topic unsubscription failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Register device token for user
     */
    public function registerToken(User $user, string $fcmToken, string $deviceType = 'android', ?string $deviceId = null): DeviceToken
    {
        // Remove token if it exists for another user (token migration)
        DeviceToken::where('token', $fcmToken)
            ->where('user_id', '!=', $user->id)
            ->delete();

        // Update or create token for this user
        return DeviceToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $deviceId ?? Str::uuid(),
            ],
            [
                'token' => $fcmToken,
                'device_type' => $deviceType,
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );
    }

    /**
     * Remove device token
     */
    public function removeToken(string $fcmToken): bool
    {
        return DeviceToken::where('token', $fcmToken)->delete() > 0;
    }

    /**
     * Invalidate token (mark as inactive)
     */
    public function invalidateToken(string $fcmToken): void
    {
        DeviceToken::where('token', $fcmToken)->update(['is_active' => false]);
    }

    // =========================================================================
    // PeaceLink Notifications
    // =========================================================================

    public function notifyPeaceLinkCreated($peaceLink): void
    {
        $merchant = $peaceLink->merchant;
        $buyer = $peaceLink->buyer;

        $this->sendToUser(
            $merchant,
            'طلب PeaceLink جديد',
            "لديك طلب جديد من {$buyer->name} بقيمة {$peaceLink->item_amount} جنيه",
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'created',
                'peacelink_id' => $peaceLink->uuid,
                'reference' => $peaceLink->reference,
            ]
        );
    }

    public function notifyPeaceLinkAccepted($peaceLink): void
    {
        $buyer = $peaceLink->buyer;
        $merchant = $peaceLink->merchant;

        $this->sendToUser(
            $buyer,
            'تم قبول طلبك',
            "قام {$merchant->name} بقبول طلبك. جاري تجهيز الشحنة.",
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'accepted',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );
    }

    public function notifyDspAssigned($peaceLink): void
    {
        $buyer = $peaceLink->buyer;
        $dsp = $peaceLink->dsp;

        $this->sendToUser(
            $buyer,
            'تم تعيين مندوب التوصيل',
            "سيقوم {$dsp->name} بتوصيل طلبك",
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'dsp_assigned',
                'peacelink_id' => $peaceLink->uuid,
                'dsp_name' => $dsp->name,
            ]
        );

        // Also notify DSP
        $this->sendToUser(
            $dsp,
            'طلب توصيل جديد',
            "لديك طلب توصيل جديد إلى {$peaceLink->delivery_city}",
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'dsp_new_order',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );
    }

    public function notifyInTransit($peaceLink): void
    {
        $buyer = $peaceLink->buyer;

        $body = $peaceLink->tracking_code
            ? "طلبك في الطريق. رقم التتبع: {$peaceLink->tracking_code}"
            : 'الشحنة في الطريق إليك';

        $this->sendToUser(
            $buyer,
            'طلبك في الطريق',
            $body,
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'in_transit',
                'peacelink_id' => $peaceLink->uuid,
                'tracking_code' => $peaceLink->tracking_code,
            ]
        );
    }

    public function notifyDeliveryOtp($peaceLink): void
    {
        $buyer = $peaceLink->buyer;

        $this->sendToUser(
            $buyer,
            'رمز تأكيد الاستلام',
            'تم إرسال رمز التأكيد إلى رقم هاتفك. شاركه مع المندوب بعد استلام الطلب.',
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'delivery_otp',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );
    }

    public function notifyDelivered($peaceLink): void
    {
        $merchant = $peaceLink->merchant;

        $this->sendToUser(
            $merchant,
            'تم تأكيد الاستلام',
            "أكد المشتري استلام الطلب {$peaceLink->reference}. جاري تحويل المبلغ.",
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'delivered',
                'peacelink_id' => $peaceLink->uuid,
            ]
        );
    }

    public function notifyReleased($peaceLink, float $merchantAmount): void
    {
        $merchant = $peaceLink->merchant;

        $this->sendToUser(
            $merchant,
            'تم تحويل المبلغ',
            "تم إضافة {$merchantAmount} جنيه إلى محفظتك",
            [
                'type' => self::TYPE_PEACELINK,
                'action' => 'released',
                'peacelink_id' => $peaceLink->uuid,
                'amount' => $merchantAmount,
            ]
        );
    }

    public function notifyCancelled($peaceLink, string $cancelledBy, bool $refundIssued): void
    {
        $buyer = $peaceLink->buyer;
        $merchant = $peaceLink->merchant;

        $body = $refundIssued
            ? "تم إلغاء الطلب {$peaceLink->reference} واسترداد المبلغ"
            : "تم إلغاء الطلب {$peaceLink->reference}";

        // Notify both parties (except the one who cancelled)
        $notifyList = [];
        if ($cancelledBy !== 'buyer') $notifyList[] = $buyer;
        if ($cancelledBy !== 'merchant') $notifyList[] = $merchant;
        if ($peaceLink->dsp_id && $cancelledBy !== 'dsp') $notifyList[] = $peaceLink->dsp;

        foreach ($notifyList as $user) {
            $this->sendToUser(
                $user,
                'تم إلغاء الطلب',
                $body,
                [
                    'type' => self::TYPE_PEACELINK,
                    'action' => 'cancelled',
                    'peacelink_id' => $peaceLink->uuid,
                    'refund_issued' => $refundIssued,
                ]
            );
        }
    }

    // =========================================================================
    // Dispute Notifications
    // =========================================================================

    public function notifyDisputeOpened($dispute): void
    {
        $respondent = $dispute->respondent;
        $peaceLink = $dispute->peaceLink;

        $this->sendToUser(
            $respondent,
            'تم فتح نزاع',
            "تم فتح نزاع على الطلب {$peaceLink->reference}. يرجى الرد خلال 48 ساعة.",
            [
                'type' => self::TYPE_DISPUTE,
                'action' => 'opened',
                'dispute_id' => $dispute->uuid,
                'peacelink_id' => $peaceLink->uuid,
            ]
        );
    }

    public function notifyDisputeResolved($dispute, string $resolution, ?float $refundAmount = null): void
    {
        $opener = $dispute->opener;
        $respondent = $dispute->respondent;

        $resolutionLabel = match($resolution) {
            'buyer' => 'لصالح المشتري',
            'merchant' => 'لصالح البائع',
            'split' => 'بالتساوي بين الطرفين',
            default => $resolution,
        };

        foreach ([$opener, $respondent] as $user) {
            $this->sendToUser(
                $user,
                'تم حل النزاع',
                "تم حل النزاع {$resolutionLabel}",
                [
                    'type' => self::TYPE_DISPUTE,
                    'action' => 'resolved',
                    'dispute_id' => $dispute->uuid,
                    'resolution' => $resolution,
                    'refund_amount' => $refundAmount,
                ]
            );
        }
    }

    // =========================================================================
    // Cashout Notifications
    // =========================================================================

    public function notifyCashoutCompleted($cashout): void
    {
        $user = $cashout->user;

        $this->sendToUser(
            $user,
            'تم السحب بنجاح',
            "تم تحويل {$cashout->net_amount} جنيه إلى حسابك",
            [
                'type' => self::TYPE_CASHOUT,
                'action' => 'completed',
                'cashout_id' => $cashout->uuid,
                'amount' => $cashout->net_amount,
            ]
        );
    }

    public function notifyCashoutFailed($cashout, string $reason): void
    {
        $user = $cashout->user;

        $this->sendToUser(
            $user,
            'فشل السحب',
            "فشل طلب السحب: {$reason}. تم إرجاع المبلغ إلى محفظتك.",
            [
                'type' => self::TYPE_CASHOUT,
                'action' => 'failed',
                'cashout_id' => $cashout->uuid,
                'reason' => $reason,
            ]
        );
    }

    // =========================================================================
    // Transaction Notifications
    // =========================================================================

    public function notifyMoneyReceived(User $recipient, User $sender, float $amount): void
    {
        $this->sendToUser(
            $recipient,
            'تم استلام تحويل',
            "استلمت {$amount} جنيه من {$sender->name}",
            [
                'type' => self::TYPE_TRANSACTION,
                'action' => 'received',
                'amount' => $amount,
                'sender_name' => $sender->name,
            ]
        );
    }

    public function notifyMoneyAdded(User $user, float $amount, string $method): void
    {
        $methodLabel = match($method) {
            'fawry' => 'فوري',
            'vodafone_cash' => 'فودافون كاش',
            'card' => 'البطاقة',
            'instapay' => 'انستاباي',
            default => $method,
        };

        $this->sendToUser(
            $user,
            'تم إضافة الرصيد',
            "تم إضافة {$amount} جنيه عبر {$methodLabel}",
            [
                'type' => self::TYPE_TRANSACTION,
                'action' => 'added',
                'amount' => $amount,
                'method' => $method,
            ]
        );
    }

    // =========================================================================
    // KYC Notifications
    // =========================================================================

    public function notifyKycApproved(User $user, string $newLevel): void
    {
        $levelLabel = match($newLevel) {
            'silver' => 'الفضي',
            'gold' => 'الذهبي',
            default => $newLevel,
        };

        $this->sendToUser(
            $user,
            'تمت ترقية حسابك',
            "تهانينا! تمت ترقية حسابك إلى المستوى {$levelLabel}",
            [
                'type' => self::TYPE_KYC,
                'action' => 'approved',
                'new_level' => $newLevel,
            ]
        );
    }

    public function notifyKycRejected(User $user, string $reason): void
    {
        $this->sendToUser(
            $user,
            'تم رفض طلب الترقية',
            "السبب: {$reason}",
            [
                'type' => self::TYPE_KYC,
                'action' => 'rejected',
                'reason' => $reason,
            ]
        );
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Build FCM message structure
     */
    private function buildMessage(
        string $fcmToken,
        string $title,
        string $body,
        array $data,
        ?string $imageUrl
    ): array {
        $message = [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $this->prepareData($data),
            'android' => $this->getAndroidConfig(),
            'apns' => $this->getApnsConfig(),
        ];

        if ($imageUrl) {
            $message['notification']['image'] = $imageUrl;
        }

        return $message;
    }

    /**
     * Prepare data payload (all values must be strings)
     */
    private function prepareData(array $data): array
    {
        $prepared = [];
        
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $prepared[$key] = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $prepared[$key] = json_encode($value);
            } else {
                $prepared[$key] = (string) $value;
            }
        }

        // Add timestamp
        $prepared['sent_at'] = now()->toISOString();

        return $prepared;
    }

    /**
     * Get Android-specific configuration
     */
    private function getAndroidConfig(): array
    {
        return [
            'priority' => 'high',
            'notification' => [
                'channel_id' => 'peacepay_notifications',
                'sound' => 'default',
                'default_vibrate_timings' => true,
                'default_light_settings' => true,
                'notification_priority' => 'PRIORITY_HIGH',
            ],
        ];
    }

    /**
     * Get iOS-specific configuration
     */
    private function getApnsConfig(): array
    {
        return [
            'headers' => [
                'apns-priority' => '10',
            ],
            'payload' => [
                'aps' => [
                    'sound' => 'default',
                    'badge' => 1,
                    'content-available' => 1,
                ],
            ],
        ];
    }

    /**
     * Get OAuth2 access token for FCM v1 API
     */
    private function getAccessToken(): string
    {
        // Cache the token for 50 minutes (expires in 60)
        return Cache::remember('fcm_access_token', 3000, function () {
            $credentialsPath = config('services.firebase.credentials');
            
            if (!$credentialsPath || !file_exists($credentialsPath)) {
                throw new Exception('Firebase credentials file not found');
            }

            $credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                json_decode(file_get_contents($credentialsPath), true)
            );

            $token = $credentials->fetchAuthToken();
            
            return $token['access_token'];
        });
    }

    /**
     * Get user's active FCM tokens
     */
    private function getUserTokens(User $user): array
    {
        // If using single token on User model
        if ($user->fcm_token) {
            return [$user->fcm_token];
        }

        // If using DeviceToken model for multiple devices
        if (class_exists(DeviceToken::class)) {
            return DeviceToken::where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('token')
                ->toArray();
        }

        return [];
    }

    /**
     * Save in-app notification
     */
    private function saveInAppNotification(User $user, string $title, string $body, array $data): Notification
    {
        return Notification::create([
            'id' => Str::uuid(),
            'type' => 'App\Notifications\PushNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'title' => $title,
                'message' => $body,
                'payload' => $data,
            ]),
        ]);
    }

    /**
     * Handle FCM error codes
     */
    private function handleFcmError(array $error, string $fcmToken): void
    {
        $errorCode = $error['error']['details'][0]['errorCode'] ?? null;

        switch ($errorCode) {
            case 'UNREGISTERED':
            case 'INVALID_ARGUMENT':
                // Token is invalid, mark as inactive
                $this->invalidateToken($fcmToken);
                Log::info('Invalid FCM token removed', [
                    'token' => substr($fcmToken, 0, 20) . '...',
                ]);
                break;

            case 'SENDER_ID_MISMATCH':
                Log::error('FCM sender ID mismatch - check configuration');
                break;

            case 'QUOTA_EXCEEDED':
                Log::warning('FCM quota exceeded');
                break;
        }
    }
}


// ============================================================================
// DeviceToken Model (app/Models/DeviceToken.php)
// ============================================================================

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'token',
        'device_type',
        'device_name',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


// ============================================================================
// Migration: create_device_tokens_table
// ============================================================================

/*
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('device_id')->nullable();
            $table->text('token');
            $table->enum('device_type', ['android', 'ios', 'web'])->default('android');
            $table->string('device_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
            $table->index(['token']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
*/


// ============================================================================
// Config: config/services.php (add to existing)
// ============================================================================

/*
'firebase' => [
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'credentials' => env('FIREBASE_CREDENTIALS_PATH', storage_path('app/firebase-credentials.json')),
],
*/


// ============================================================================
// API Controller for Device Token Management
// ============================================================================

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DeviceTokenController extends Controller
{
    public function __construct(
        private PushNotificationService $pushService
    ) {}

    /**
     * Register device token
     * POST /api/v1/device-tokens
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'device_type' => 'required|in:android,ios,web',
            'device_id' => 'nullable|string',
            'device_name' => 'nullable|string|max:255',
        ]);

        $deviceToken = $this->pushService->registerToken(
            $request->user(),
            $request->token,
            $request->device_type,
            $request->device_id
        );

        // Subscribe to default topics
        $this->pushService->subscribeToTopic($request->token, 'all_users');
        
        if ($request->user()->is_dsp) {
            $this->pushService->subscribeToTopic($request->token, 'dsp_users');
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الجهاز بنجاح',
            'data' => [
                'device_id' => $deviceToken->device_id,
            ],
        ]);
    }

    /**
     * Remove device token
     * DELETE /api/v1/device-tokens
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        // Unsubscribe from topics
        $this->pushService->unsubscribeFromTopic($request->token, 'all_users');
        $this->pushService->unsubscribeFromTopic($request->token, 'dsp_users');

        $this->pushService->removeToken($request->token);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء تسجيل الجهاز',
        ]);
    }

    /**
     * Update notification preferences
     * PUT /api/v1/device-tokens/preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'topics' => 'required|array',
            'topics.*' => 'string|in:promotions,news,tips',
        ]);

        $token = $request->token;
        $topics = $request->topics;

        // Available optional topics
        $optionalTopics = ['promotions', 'news', 'tips'];

        foreach ($optionalTopics as $topic) {
            if (in_array($topic, $topics)) {
                $this->pushService->subscribeToTopic($token, $topic);
            } else {
                $this->pushService->unsubscribeFromTopic($token, $topic);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث تفضيلات الإشعارات',
        ]);
    }

    /**
     * Send test notification (for debugging)
     * POST /api/v1/device-tokens/test
     */
    public function test(Request $request): JsonResponse
    {
        if (!app()->environment('local', 'staging')) {
            return response()->json([
                'success' => false,
                'message' => 'Test notifications only available in development',
            ], 403);
        }

        $result = $this->pushService->sendToUser(
            $request->user(),
            'إشعار تجريبي',
            'هذا إشعار تجريبي للتأكد من عمل الإشعارات',
            ['type' => 'test'],
            null,
            false
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] ? 'تم إرسال الإشعار' : 'فشل إرسال الإشعار',
            'data' => $result,
        ]);
    }
}


// ============================================================================
// Routes (add to routes/api.php)
// ============================================================================

/*
Route::middleware('auth:sanctum')->group(function () {
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);
    Route::put('device-tokens/preferences', [DeviceTokenController::class, 'updatePreferences']);
    Route::post('device-tokens/test', [DeviceTokenController::class, 'test']);
});
*/
