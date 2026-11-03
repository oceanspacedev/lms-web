<?php

return [

    // WagHub cukup diatur dengan WAG_URL dan WAG_TOKEN, sama dengan dnd-web, gais-web, dan helpdesk-web.
    // Variabel lama WAGHUB_* masih dibaca agar server yang sudah berjalan tidak wajib mengubah .env.
    'waghub' => [
        'url' => env('WAG_URL', env('WAGHUB_URL', 'https://waghub.mekayastudio.com')),
        'token' => env('WAG_TOKEN', env('WAGHUB_TOKEN')),
        'purpose' => env('WAGHUB_PURPOSE', 'notification'),
        'otp_purpose' => env('WAGHUB_OTP_PURPOSE', env('WAGHUB_PURPOSE', 'otp')),
        'mode' => env('WAGHUB_MODE', 'sync'),
        'route_key' => env('WAGHUB_ROUTE_KEY', 'default'),
        'timeout' => 20,
        'otp_expires_in' => 300,
    ],

    // Login email (kode OTP + magic link) memakai mailer default Laravel (MAIL_*).
    'email_login' => [
        'expires_in' => (int) env('EMAIL_LOGIN_EXPIRES_IN', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
