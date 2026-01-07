<?php

namespace App\Services\Sms;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * OTP Service
 * 
 * Handles OTP generation, sending, verification, and rate limiting
 * for user authentication and transaction verification.
 */
class OtpService
{
    /**
     * OTP length
     */
    private const OTP_LENGTH = 6;

    /**
     * OTP validity in minutes
     */
    private const OTP_VALIDITY_MINUTES = 5;

    /**
     * Maximum OTP attempts before lockout
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Lockout duration in minutes after max attempts
     */
    private const LOCKOUT_MINUTES = 30;

    /**
     * Cooldown between OTP requests in seconds
     */
    private const COOLDOWN_SECONDS = 60;

    /**
     * Maximum OTPs per hour
     */
    private const MAX_OTPS_PER_HOUR = 10;

    /**
     * SMS Service instance
     */
    private CequensSmsService $smsService;

    /**
     * Constructor
     */
    public function __construct(CequensSmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Generate and send OTP to user
     *
     * @param User $user The user to send OTP to
     * @param string $purpose Purpose of OTP (login, transaction, verify_phone, etc.)
     * @param string $language Language for SMS (ar/en)
     * @return array Result with success status
     * @throws Exception
     */
    public function sendOtp(User $user, string $purpose = 'login', string $language = 'ar'): array
    {
        $phoneNumber = $user->mobile ?? $user->phone;

        if (empty($phoneNumber)) {
            throw new Exception('User does not have a phone number');
        }

        // Check rate limits
        $this->checkRateLimits($user->id, $phoneNumber);

        // Generate OTP
        $otp = $this->generateOtp();

        // Store OTP with hash
        $this->storeOtp($user->id, $otp, $purpose);

        // Send SMS
        try {
            $result = $this->smsService->sendOtp($phoneNumber, $otp, $language);

            // Increment rate limit counters
            $this->incrementRateLimitCounters($user->id, $phoneNumber);

            Log::info('OTP sent', [
                'user_id' => $user->id,
                'purpose' => $purpose,
                'message_id' => $result['message_id'] ?? null
            ]);

            return [
                'success' => true,
                'message' => $language === 'ar' 
                    ? 'تم إرسال رمز التحقق بنجاح' 
                    : 'OTP sent successfully',
                'expires_in' => self::OTP_VALIDITY_MINUTES * 60,
                'cooldown' => self::COOLDOWN_SECONDS
            ];

        } catch (Exception $e) {
            Log::error('Failed to send OTP', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            throw new Exception('Failed to send OTP: ' . $e->getMessage());
        }
    }

    /**
     * Verify OTP
     *
     * @param User $user The user verifying OTP
     * @param string $otp The OTP to verify
     * @param string $purpose Purpose of OTP
     * @return array Result with success status
     * @throws Exception
     */
    public function verifyOtp(User $user, string $otp, string $purpose = 'login'): array
    {
        $cacheKey = $this->getOtpCacheKey($user->id, $purpose);
        $attemptsKey = $this->getAttemptsKey($user->id, $purpose);
        $lockoutKey = $this->getLockoutKey($user->id);

        // Check if user is locked out
        if (Cache::has($lockoutKey)) {
            $lockoutRemaining = Cache::get($lockoutKey);
            throw new Exception("Account locked. Try again in {$lockoutRemaining} minutes.");
        }

        // Get stored OTP data
        $storedData = Cache::get($cacheKey);

        if (!$storedData) {
            $this->incrementAttempts($user->id, $purpose);
            throw new Exception('OTP expired or not found');
        }

        // Verify OTP hash
        if (!Hash::check($otp, $storedData['hash'])) {
            $attempts = $this->incrementAttempts($user->id, $purpose);
            $remaining = self::MAX_ATTEMPTS - $attempts;

            if ($remaining <= 0) {
                $this->lockoutUser($user->id);
                throw new Exception('Too many failed attempts. Account locked for ' . self::LOCKOUT_MINUTES . ' minutes.');
            }

            throw new Exception("Invalid OTP. {$remaining} attempts remaining.");
        }

        // OTP is valid - clear it
        Cache::forget($cacheKey);
        Cache::forget($attemptsKey);

        Log::info('OTP verified successfully', [
            'user_id' => $user->id,
            'purpose' => $purpose
        ]);

        return [
            'success' => true,
            'message' => 'OTP verified successfully',
            'verified_at' => now()->toIso8601String()
        ];
    }

    /**
     * Generate and send OTP for PeaceLink delivery
     *
     * @param int $peaceLinkId PeaceLink transaction ID
     * @param string $phoneNumber Recipient phone number
     * @param string $language Language (ar/en)
     * @return array Result with OTP hash for storage
     */
    public function sendDeliveryOtp(int $peaceLinkId, string $phoneNumber, string $language = 'ar'): array
    {
        $otp = $this->generateOtp();
        $hash = Hash::make($otp);

        // Send SMS
        $message = $language === 'ar'
            ? "رمز استلام الطلب الخاص بك هو: {$otp}\nأعطِ هذا الرمز للمندوب عند الاستلام."
            : "Your delivery OTP is: {$otp}\nGive this code to the delivery partner upon receipt.";

        $result = $this->smsService->send($phoneNumber, $message, [
            'clientMessageId' => 'delivery_otp_' . $peaceLinkId . '_' . time()
        ]);

        Log::info('Delivery OTP sent', [
            'peacelink_id' => $peaceLinkId,
            'message_id' => $result['message_id'] ?? null
        ]);

        return [
            'success' => true,
            'otp_hash' => $hash,
            'expires_at' => now()->addMinutes(self::OTP_VALIDITY_MINUTES)->toIso8601String()
        ];
    }

    /**
     * Verify delivery OTP
     *
     * @param string $otp The OTP entered
     * @param string $storedHash The stored hash to verify against
     * @return bool
     */
    public function verifyDeliveryOtp(string $otp, string $storedHash): bool
    {
        return Hash::check($otp, $storedHash);
    }

    /**
     * Resend OTP with cooldown check
     *
     * @param User $user
     * @param string $purpose
     * @param string $language
     * @return array
     * @throws Exception
     */
    public function resendOtp(User $user, string $purpose = 'login', string $language = 'ar'): array
    {
        $cooldownKey = $this->getCooldownKey($user->id, $purpose);

        if (Cache::has($cooldownKey)) {
            $remainingSeconds = Cache::get($cooldownKey);
            throw new Exception("Please wait {$remainingSeconds} seconds before requesting a new OTP.");
        }

        return $this->sendOtp($user, $purpose, $language);
    }

    /**
     * Generate a random OTP
     *
     * @return string
     */
    private function generateOtp(): string
    {
        // Generate cryptographically secure random OTP
        $otp = '';
        for ($i = 0; $i < self::OTP_LENGTH; $i++) {
            $otp .= random_int(0, 9);
        }

        return $otp;
    }

    /**
     * Store OTP in cache
     *
     * @param int $userId
     * @param string $otp
     * @param string $purpose
     */
    private function storeOtp(int $userId, string $otp, string $purpose): void
    {
        $cacheKey = $this->getOtpCacheKey($userId, $purpose);

        Cache::put($cacheKey, [
            'hash' => Hash::make($otp),
            'created_at' => now()->toIso8601String(),
            'purpose' => $purpose
        ], now()->addMinutes(self::OTP_VALIDITY_MINUTES));
    }

    /**
     * Check rate limits
     *
     * @param int $userId
     * @param string $phoneNumber
     * @throws Exception
     */
    private function checkRateLimits(int $userId, string $phoneNumber): void
    {
        // Check lockout
        $lockoutKey = $this->getLockoutKey($userId);
        if (Cache::has($lockoutKey)) {
            throw new Exception('Account temporarily locked due to too many attempts.');
        }

        // Check cooldown
        $cooldownKey = $this->getCooldownKey($userId, 'any');
        if (Cache::has($cooldownKey)) {
            $remaining = Cache::get($cooldownKey);
            throw new Exception("Please wait {$remaining} seconds before requesting a new OTP.");
        }

        // Check hourly limit
        $hourlyKey = "otp_hourly:{$userId}";
        $hourlyCount = Cache::get($hourlyKey, 0);
        if ($hourlyCount >= self::MAX_OTPS_PER_HOUR) {
            throw new Exception('Maximum OTP requests per hour exceeded. Please try again later.');
        }
    }

    /**
     * Increment rate limit counters
     *
     * @param int $userId
     * @param string $phoneNumber
     */
    private function incrementRateLimitCounters(int $userId, string $phoneNumber): void
    {
        // Set cooldown
        $cooldownKey = $this->getCooldownKey($userId, 'any');
        Cache::put($cooldownKey, self::COOLDOWN_SECONDS, now()->addSeconds(self::COOLDOWN_SECONDS));

        // Increment hourly counter
        $hourlyKey = "otp_hourly:{$userId}";
        $count = Cache::get($hourlyKey, 0) + 1;
        Cache::put($hourlyKey, $count, now()->addHour());
    }

    /**
     * Increment failed attempts
     *
     * @param int $userId
     * @param string $purpose
     * @return int Current attempt count
     */
    private function incrementAttempts(int $userId, string $purpose): int
    {
        $attemptsKey = $this->getAttemptsKey($userId, $purpose);
        $attempts = Cache::get($attemptsKey, 0) + 1;
        Cache::put($attemptsKey, $attempts, now()->addMinutes(self::LOCKOUT_MINUTES));

        return $attempts;
    }

    /**
     * Lock out user after too many failed attempts
     *
     * @param int $userId
     */
    private function lockoutUser(int $userId): void
    {
        $lockoutKey = $this->getLockoutKey($userId);
        Cache::put($lockoutKey, self::LOCKOUT_MINUTES, now()->addMinutes(self::LOCKOUT_MINUTES));

        Log::warning('User locked out due to too many OTP attempts', [
            'user_id' => $userId,
            'lockout_minutes' => self::LOCKOUT_MINUTES
        ]);
    }

    /**
     * Get cache key for OTP storage
     */
    private function getOtpCacheKey(int $userId, string $purpose): string
    {
        return "otp:{$userId}:{$purpose}";
    }

    /**
     * Get cache key for attempts counter
     */
    private function getAttemptsKey(int $userId, string $purpose): string
    {
        return "otp_attempts:{$userId}:{$purpose}";
    }

    /**
     * Get cache key for cooldown
     */
    private function getCooldownKey(int $userId, string $purpose): string
    {
        return "otp_cooldown:{$userId}:{$purpose}";
    }

    /**
     * Get cache key for lockout
     */
    private function getLockoutKey(int $userId): string
    {
        return "otp_lockout:{$userId}";
    }

    /**
     * Get remaining cooldown time for a user
     *
     * @param int $userId
     * @param string $purpose
     * @return int|null Remaining seconds or null if no cooldown
     */
    public function getRemainingCooldown(int $userId, string $purpose = 'any'): ?int
    {
        $cooldownKey = $this->getCooldownKey($userId, $purpose);
        
        if (Cache::has($cooldownKey)) {
            return Cache::get($cooldownKey);
        }

        return null;
    }

    /**
     * Check if user is locked out
     *
     * @param int $userId
     * @return bool
     */
    public function isLockedOut(int $userId): bool
    {
        return Cache::has($this->getLockoutKey($userId));
    }

    /**
     * Clear all OTP data for a user (admin function)
     *
     * @param int $userId
     */
    public function clearUserOtpData(int $userId): void
    {
        $purposes = ['login', 'transaction', 'verify_phone', 'reset_password', 'any'];

        foreach ($purposes as $purpose) {
            Cache::forget($this->getOtpCacheKey($userId, $purpose));
            Cache::forget($this->getAttemptsKey($userId, $purpose));
            Cache::forget($this->getCooldownKey($userId, $purpose));
        }

        Cache::forget($this->getLockoutKey($userId));
        Cache::forget("otp_hourly:{$userId}");

        Log::info('Cleared OTP data for user', ['user_id' => $userId]);
    }
}
