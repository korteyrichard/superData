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

    'bulkclix' => [
        'api_key' => env('BULKCLIX_API_KEY'),
        'sender_id' => env('BULKCLIX_SENDER_ID'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    'dataeasy' => [
        'base_url' => env('DATAEASY_BASE_URL', 'https://dataeasy.onrender.com/api/v1'),
        'api_key' => env('DATAEASY_API_KEY'),
    ],
    'dataflow' => [
        'base_url' => env('DATAFLOW_BASE_URL', 'https://dataflowghana.com/api/v1'),
        'api_key'  => env('DATAFLOW_API_KEY'),
    ],

    'order_pusher' => [
        'base_url' => env('ORDER_PUSHER_BASE_URL', ''),
        'api_key'  => env('ORDER_PUSHER_API_KEY', ''),
    ],

    'codecraft' => [
        'api_key' => env('CODECRAFT_API_KEY', ''),
    ],

    'prodataworld' => [
        'api_key' => env('PRODATAWORLD_API_KEY', ''),
    ],

    'bundleportal' => [
        'base_url' => env('BUNDLEPORTAL_BASE_URL', 'https://api.bundleportal.com/v2'),
        'api_key'  => env('BUNDLEPORTAL_API_KEY', ''),
        'webhook_secret' => env('WEBHOOK_SECRET', ''),
    ],

];
