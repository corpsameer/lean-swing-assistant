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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'timeout_seconds' => env('OPENAI_TIMEOUT_SECONDS', 30),
    ],


    'intraday_validation' => [
        'near_band_tolerance_percent' => env('INTRADAY_NEAR_BAND_TOLERANCE_PERCENT', 0.75),
        'max_extension_percent' => env('INTRADAY_MAX_EXTENSION_PERCENT', 1.5),
    ],


    'intraday_fetch' => [
        'python_executable' => env('INTRADAY_PYTHON_EXECUTABLE', env('PYTHON_EXECUTABLE', 'python')),
        'script_path' => env('INTRADAY_FETCH_SCRIPT_PATH', base_path('../python_ibkr/scripts/fetch_intraday_data.py')),
        'output_path' => env('INTRADAY_FETCH_OUTPUT_PATH', storage_path('app/intraday_snapshot.json')),
        'timeout_seconds' => env('INTRADAY_FETCH_TIMEOUT_SECONDS', 180),
    ],

];
