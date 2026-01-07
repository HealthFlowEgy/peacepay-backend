<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID',""),
        'client_secret' => env('GOOGLE_CLIENT_SECRET',""),
        'redirect' => env('GOOGLE_CALLBACK',""),
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID',""),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET',""),
        'redirect' => env('FACEBOOK_CALLBACK',""),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cequens SMS Service
    |--------------------------------------------------------------------------
    |
    | Configuration for Cequens SMS API integration.
    | @see https://developer.cequens.com/reference/sending-sms
    |
    */
    'cequens' => [
        'api_token' => env('CEQUENS_API_TOKEN'),
        'api_key' => env('CEQUENS_API_KEY'),
        'sender_id' => env('CEQUENS_SENDER_ID', 'PeacePay'),
        'enabled' => env('CEQUENS_ENABLED', true),
        'api_url' => env('CEQUENS_API_URL', 'https://apis.cequens.com/sms/v1'),
        'dlr_webhook_url' => env('CEQUENS_DLR_WEBHOOK_URL'),
        'default_language' => env('CEQUENS_DEFAULT_LANGUAGE', 'ar'),
    ],

];
