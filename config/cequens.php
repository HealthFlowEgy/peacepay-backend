<?php

/**
 * Cequens SMS Service Configuration
 * 
 * Configuration for the Cequens SMS API integration.
 * 
 * @see https://developer.cequens.com/reference/sending-sms
 */

return [

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | The JWT token for authenticating with the Cequens API.
    | Generate this from the Cequens Communication Platform console.
    |
    */
    'api_token' => env('CEQUENS_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | The API key for additional authentication (if required).
    |
    */
    'api_key' => env('CEQUENS_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Sender ID
    |--------------------------------------------------------------------------
    |
    | The registered sender name that will appear on SMS messages.
    | This must be registered and approved by Cequens.
    |
    */
    'sender_id' => env('CEQUENS_SENDER_ID', 'PeacePay'),

    /*
    |--------------------------------------------------------------------------
    | Enable SMS Sending
    |--------------------------------------------------------------------------
    |
    | Set to false to disable actual SMS sending (useful for development).
    | When disabled, SMS operations will be logged but not sent.
    |
    */
    'enabled' => env('CEQUENS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the Cequens API.
    |
    */
    'api_url' => env('CEQUENS_API_URL', 'https://apis.cequens.com/sms/v1'),

    /*
    |--------------------------------------------------------------------------
    | Delivery Report Webhook
    |--------------------------------------------------------------------------
    |
    | URL to receive delivery status reports (DLR) from Cequens.
    |
    */
    'dlr_webhook_url' => env('CEQUENS_DLR_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Default Language
    |--------------------------------------------------------------------------
    |
    | Default language for SMS templates (ar = Arabic, en = English).
    |
    */
    'default_language' => env('CEQUENS_DEFAULT_LANGUAGE', 'ar'),

    /*
    |--------------------------------------------------------------------------
    | OTP Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for OTP generation and verification.
    |
    */
    'otp' => [
        'length' => env('OTP_LENGTH', 6),
        'validity_minutes' => env('OTP_VALIDITY_MINUTES', 5),
        'max_attempts' => env('OTP_MAX_ATTEMPTS', 5),
        'lockout_minutes' => env('OTP_LOCKOUT_MINUTES', 30),
        'cooldown_seconds' => env('OTP_COOLDOWN_SECONDS', 60),
        'max_per_hour' => env('OTP_MAX_PER_HOUR', 10),
    ],

];
