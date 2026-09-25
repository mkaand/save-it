<?php

$abuseLimits = [
    'analyze' => (int) env('ABUSE_LIMIT_ANALYZE_PER_MINUTE', 30),
    'download' => (int) env('ABUSE_LIMIT_DOWNLOAD_PER_MINUTE', 120),
    'share' => (int) env('ABUSE_LIMIT_SHARE_PER_MINUTE', 20),
    'job_create' => (int) env('ABUSE_LIMIT_JOB_CREATE_PER_MINUTE', 20),
    'job_poll' => (int) env('ABUSE_LIMIT_JOB_POLL_PER_MINUTE', 120),
    'preview' => (int) env('ABUSE_LIMIT_PREVIEW_PER_MINUTE', 60),
    'invalid_token' => (int) env('ABUSE_LIMIT_INVALID_TOKEN_PER_MINUTE', 15),
];

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

    'extractor' => [
        'base_url' => env('EXTRACTOR_BASE_URL', 'http://extractor:8000'),
        'timeout_seconds' => (float) env('EXTRACTOR_TIMEOUT_SECONDS', 15),
        'connect_timeout_seconds' => (float) env('EXTRACTOR_CONNECT_TIMEOUT_SECONDS', 2),
    ],

    'downloads' => [
        'token_ttl_seconds' => (int) env('DOWNLOAD_TOKEN_TTL_SECONDS', 600),
        'job_ttl_seconds' => (int) env('DOWNLOAD_JOB_TTL_SECONDS', 3600),
        'connect_timeout_seconds' => (float) env('DOWNLOAD_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout_seconds' => (float) env('DOWNLOAD_TIMEOUT_SECONDS', 120),
        'max_redirects' => (int) env('DOWNLOAD_MAX_REDIRECTS', 3),
        'max_file_bytes' => (int) env('DOWNLOAD_MAX_FILE_BYTES', 536870912),
        'max_zip_assets' => (int) env('DOWNLOAD_MAX_ZIP_ASSETS', 20),
        'max_zip_bytes' => (int) env('DOWNLOAD_MAX_ZIP_BYTES', 1073741824),
        'job_timeout_seconds' => (int) env('DOWNLOAD_JOB_TIMEOUT_SECONDS', 900),
        // Web Share buffers media in the browser, so keep its server-side
        // preparation bounded independently from normal downloads.
        'share_max_file_bytes' => 104_857_600,
        'share_preparation_ttl_seconds' => 600,
        'share_ffmpeg_binary' => '/usr/bin/ffmpeg',
    ],

    'abuse_limits' => $abuseLimits,
    'abuse_limit_defaults' => $abuseLimits,

    'turnstile' => [
        'enabled' => (bool) env('TURNSTILE_ENABLED', false),
        'protect_analyze' => (bool) env('TURNSTILE_PROTECT_ANALYZE', false),
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    ],

    'umami' => [
        'enabled' => (bool) env('UMAMI_ENABLED', false),
        'script_url' => env('UMAMI_SCRIPT_URL'),
        'website_id' => env('UMAMI_WEBSITE_ID'),
    ],

];
