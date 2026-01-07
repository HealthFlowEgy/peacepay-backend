<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Cequens SMS Service
 * 
 * Handles SMS sending via Cequens API for OTP verification,
 * notifications, and transactional messages.
 * 
 * @see https://developer.cequens.com/reference/sending-sms
 */
class CequensSmsService
{
    /**
     * Cequens API endpoint for sending SMS
     */
    private const API_URL = 'https://apis.cequens.com/sms/v1/messages';

    /**
     * API Token for authentication
     */
    private string $apiToken;

    /**
     * API Key for additional authentication
     */
    private string $apiKey;

    /**
     * Sender ID (registered with Cequens)
     */
    private string $senderId;

    /**
     * Whether SMS sending is enabled
     */
    private bool $enabled;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->apiToken = config('services.cequens.api_token');
        $this->apiKey = config('services.cequens.api_key');
        $this->senderId = config('services.cequens.sender_id', 'PeacePay');
        $this->enabled = config('services.cequens.enabled', true);
    }

    /**
     * Send an SMS message
     *
     * @param string $phoneNumber Recipient phone number (with country code)
     * @param string $message Message content
     * @param array $options Additional options (clientMessageId, dlrUrl, etc.)
     * @return array Response with success status and message ID
     * @throws Exception
     */
    public function send(string $phoneNumber, string $message, array $options = []): array
    {
        // Check if SMS is enabled
        if (!$this->enabled) {
            Log::info('SMS sending disabled', [
                'phone' => $this->maskPhone($phoneNumber),
                'message_preview' => substr($message, 0, 20) . '...'
            ]);
            
            return [
                'success' => true,
                'message_id' => 'disabled_' . uniqid(),
                'status' => 'disabled',
                'note' => 'SMS sending is disabled in configuration'
            ];
        }

        // Validate phone number
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$this->isValidPhoneNumber($phoneNumber)) {
            throw new Exception('Invalid phone number format');
        }

        // Determine message type (text or unicode for Arabic)
        $messageType = $this->detectMessageType($message);

        // Build request payload
        $payload = [
            'messageText' => $message,
            'senderName' => $this->senderId,
            'messageType' => $messageType,
            'recipients' => $phoneNumber,
            'shortURL' => false,
        ];

        // Add optional parameters
        if (isset($options['clientMessageId'])) {
            $payload['clientMessageId'] = $options['clientMessageId'];
        }

        if (isset($options['dlrUrl'])) {
            $payload['dlrUrl'] = $options['dlrUrl'];
        }

        if (isset($options['deliveryTime'])) {
            $payload['deliveryTime'] = $options['deliveryTime'];
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiToken,
            ])->post(self::API_URL, $payload);

            $responseData = $response->json();

            if ($response->successful()) {
                Log::info('SMS sent successfully', [
                    'phone' => $this->maskPhone($phoneNumber),
                    'message_id' => $responseData['messageId'] ?? null,
                    'status' => $responseData['status'] ?? 'sent'
                ]);

                return [
                    'success' => true,
                    'message_id' => $responseData['messageId'] ?? null,
                    'status' => $responseData['status'] ?? 'sent',
                    'response' => $responseData
                ];
            }

            // Handle error response
            $errorCode = $responseData['errorCode'] ?? 'unknown';
            $errorMessage = $this->getErrorMessage($errorCode);

            Log::error('SMS sending failed', [
                'phone' => $this->maskPhone($phoneNumber),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'response' => $responseData
            ]);

            throw new Exception("SMS sending failed: {$errorMessage} (Code: {$errorCode})");

        } catch (Exception $e) {
            Log::error('SMS service exception', [
                'phone' => $this->maskPhone($phoneNumber),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Send OTP message
     *
     * @param string $phoneNumber Recipient phone number
     * @param string $otp The OTP code
     * @param string $language Language for message template (ar/en)
     * @return array
     */
    public function sendOtp(string $phoneNumber, string $otp, string $language = 'ar'): array
    {
        $message = $this->getOtpMessage($otp, $language);
        
        return $this->send($phoneNumber, $message, [
            'clientMessageId' => 'otp_' . time() . '_' . uniqid()
        ]);
    }

    /**
     * Send PeaceLink notification
     *
     * @param string $phoneNumber Recipient phone number
     * @param string $type Notification type (created, approved, delivered, etc.)
     * @param array $data Additional data for the message
     * @param string $language Language (ar/en)
     * @return array
     */
    public function sendPeaceLinkNotification(
        string $phoneNumber, 
        string $type, 
        array $data = [], 
        string $language = 'ar'
    ): array {
        $message = $this->getPeaceLinkMessage($type, $data, $language);
        
        return $this->send($phoneNumber, $message, [
            'clientMessageId' => 'peacelink_' . $type . '_' . time()
        ]);
    }

    /**
     * Send transaction notification
     *
     * @param string $phoneNumber Recipient phone number
     * @param string $type Transaction type (credit, debit, cashout)
     * @param float $amount Transaction amount
     * @param float $balance New balance
     * @param string $language Language (ar/en)
     * @return array
     */
    public function sendTransactionNotification(
        string $phoneNumber,
        string $type,
        float $amount,
        float $balance,
        string $language = 'ar'
    ): array {
        $message = $this->getTransactionMessage($type, $amount, $balance, $language);
        
        return $this->send($phoneNumber, $message, [
            'clientMessageId' => 'txn_' . $type . '_' . time()
        ]);
    }

    /**
     * Normalize phone number to international format
     *
     * @param string $phoneNumber
     * @return string
     */
    private function normalizePhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Handle Egyptian numbers
        if (str_starts_with($phoneNumber, '01') && strlen($phoneNumber) === 11) {
            // Egyptian mobile number without country code
            $phoneNumber = '2' . $phoneNumber;
        } elseif (str_starts_with($phoneNumber, '1') && strlen($phoneNumber) === 10) {
            // Egyptian mobile number without leading 0
            $phoneNumber = '20' . $phoneNumber;
        }

        return $phoneNumber;
    }

    /**
     * Validate phone number format
     *
     * @param string $phoneNumber
     * @return bool
     */
    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        // Egyptian mobile numbers: 201XXXXXXXXX (12 digits)
        if (preg_match('/^20(10|11|12|15)\d{8}$/', $phoneNumber)) {
            return true;
        }

        // Generic international format (10-15 digits)
        if (preg_match('/^\d{10,15}$/', $phoneNumber)) {
            return true;
        }

        return false;
    }

    /**
     * Detect message type based on content
     *
     * @param string $message
     * @return string 'text' or 'unicode'
     */
    private function detectMessageType(string $message): string
    {
        // Check for Arabic characters
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $message)) {
            return 'unicode';
        }

        return 'text';
    }

    /**
     * Mask phone number for logging
     *
     * @param string $phoneNumber
     * @return string
     */
    private function maskPhone(string $phoneNumber): string
    {
        $length = strlen($phoneNumber);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($phoneNumber, 0, 3) . str_repeat('*', $length - 6) . substr($phoneNumber, -3);
    }

    /**
     * Get human-readable error message
     *
     * @param mixed $errorCode
     * @return string
     */
    private function getErrorMessage($errorCode): string
    {
        $errors = [
            -6 => 'Invalid recipients - at least one valid recipient required',
            -8 => 'Invalid message type - only text and unicode are supported',
            -9 => 'Empty message text',
            -12 => 'Invalid sender name',
            -13 => 'Invalid flashing value - should be 0 or 1',
            -14 => 'Invalid acknowledgment value - should be 0 or 1',
            -15 => 'Invalid validity period',
            -16 => 'Invalid delivery time',
            -17 => 'Not enough credits',
            -41 => 'Message text exceeds 20 parts',
        ];

        return $errors[$errorCode] ?? 'Unknown error';
    }

    /**
     * Get OTP message template
     *
     * @param string $otp
     * @param string $language
     * @return string
     */
    private function getOtpMessage(string $otp, string $language): string
    {
        if ($language === 'ar') {
            return "رمز التحقق الخاص بك في PeacePay هو: {$otp}\nصالح لمدة 5 دقائق. لا تشاركه مع أي شخص.";
        }

        return "Your PeacePay verification code is: {$otp}\nValid for 5 minutes. Do not share with anyone.";
    }

    /**
     * Get PeaceLink notification message
     *
     * @param string $type
     * @param array $data
     * @param string $language
     * @return string
     */
    private function getPeaceLinkMessage(string $type, array $data, string $language): string
    {
        $amount = $data['amount'] ?? 0;
        $orderId = $data['order_id'] ?? '';

        $messages = [
            'created' => [
                'ar' => "تم إنشاء طلب PeaceLink جديد بقيمة {$amount} ج.م. رقم الطلب: {$orderId}",
                'en' => "New PeaceLink order created for {$amount} EGP. Order ID: {$orderId}"
            ],
            'approved' => [
                'ar' => "تمت الموافقة على طلب PeaceLink رقم {$orderId}. جاري تجهيز الشحنة.",
                'en' => "PeaceLink order {$orderId} approved. Preparing shipment."
            ],
            'dsp_assigned' => [
                'ar' => "تم تعيين مندوب التوصيل لطلبك رقم {$orderId}. سيتم التواصل معك قريباً.",
                'en' => "Delivery partner assigned to order {$orderId}. You will be contacted soon."
            ],
            'picked_up' => [
                'ar' => "تم استلام طلبك رقم {$orderId} من التاجر. في الطريق إليك.",
                'en' => "Order {$orderId} picked up from merchant. On the way to you."
            ],
            'delivered' => [
                'ar' => "تم تسليم طلبك رقم {$orderId} بنجاح. شكراً لاستخدامك PeacePay!",
                'en' => "Order {$orderId} delivered successfully. Thank you for using PeacePay!"
            ],
            'cancelled' => [
                'ar' => "تم إلغاء طلب PeaceLink رقم {$orderId}. تم إرجاع المبلغ إلى محفظتك.",
                'en' => "PeaceLink order {$orderId} cancelled. Amount refunded to your wallet."
            ],
            'dispute_opened' => [
                'ar' => "تم فتح نزاع على طلب رقم {$orderId}. سيتم مراجعته خلال 48 ساعة.",
                'en' => "Dispute opened for order {$orderId}. Will be reviewed within 48 hours."
            ],
        ];

        return $messages[$type][$language] ?? $messages[$type]['en'] ?? "PeaceLink notification for order {$orderId}";
    }

    /**
     * Get transaction notification message
     *
     * @param string $type
     * @param float $amount
     * @param float $balance
     * @param string $language
     * @return string
     */
    private function getTransactionMessage(string $type, float $amount, float $balance, string $language): string
    {
        $formattedAmount = number_format($amount, 2);
        $formattedBalance = number_format($balance, 2);

        $messages = [
            'credit' => [
                'ar' => "تم إضافة {$formattedAmount} ج.م إلى محفظتك. الرصيد الحالي: {$formattedBalance} ج.م",
                'en' => "{$formattedAmount} EGP added to your wallet. Current balance: {$formattedBalance} EGP"
            ],
            'debit' => [
                'ar' => "تم خصم {$formattedAmount} ج.م من محفظتك. الرصيد الحالي: {$formattedBalance} ج.م",
                'en' => "{$formattedAmount} EGP deducted from your wallet. Current balance: {$formattedBalance} EGP"
            ],
            'cashout' => [
                'ar' => "تم تحويل {$formattedAmount} ج.م إلى حسابك البنكي. الرصيد المتبقي: {$formattedBalance} ج.م",
                'en' => "{$formattedAmount} EGP transferred to your bank. Remaining balance: {$formattedBalance} EGP"
            ],
        ];

        return $messages[$type][$language] ?? $messages[$type]['en'] ?? "Transaction of {$formattedAmount} EGP processed.";
    }

    /**
     * Check if SMS service is properly configured
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiToken) && !empty($this->senderId);
    }

    /**
     * Get account balance (if supported by API)
     *
     * @return array|null
     */
    public function getBalance(): ?array
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiToken,
            ])->get('https://apis.cequens.com/sms/v1/balance');

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (Exception $e) {
            Log::error('Failed to get SMS balance', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
