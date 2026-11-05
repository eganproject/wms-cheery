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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Pembaruan aplikasi Android dari dalam aplikasi (/api/mobile/app-version).
     * Bawaan: versi terbaru dibaca dari GitHub Releases (tag android-v1.0.N).
     * Bila repo dijadikan private, isi MOBILE_APP_GITHUB_TOKEN (izin baca
     * Contents) — APK lalu diunduh lewat server ini. Bisa juga diisi manual
     * dengan MOBILE_APP_VERSION_CODE + MOBILE_APP_DOWNLOAD_URL.
     */
    'mobile_app' => [
        'github_repo' => env('MOBILE_APP_GITHUB_REPO', 'eganproject/wms-cheery'),
        'github_token' => env('MOBILE_APP_GITHUB_TOKEN'),
        'version_code' => env('MOBILE_APP_VERSION_CODE'),
        'version_name' => env('MOBILE_APP_VERSION_NAME'),
        'download_url' => env('MOBILE_APP_DOWNLOAD_URL'),
        'notes' => env('MOBILE_APP_NOTES'),
        // HP dengan versi di bawah angka ini wajib memperbarui dulu.
        'min_version_code' => (int) env('MOBILE_APP_MIN_VERSION_CODE', 0),
    ],

];
