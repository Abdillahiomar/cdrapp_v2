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

    // Capture PDF des pages (rapport du tableau de bord envoyé par email)
    'browsershot' => [
        // Adresse à laquelle le serveur joint l'application elle-même (par défaut APP_URL)
        'base_url'     => env('BROWSERSHOT_BASE_URL'),
        'node_binary'  => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary'   => env('BROWSERSHOT_NPM_BINARY'),
        'chrome_path'  => env('BROWSERSHOT_CHROME_PATH'),
        'node_modules' => env('BROWSERSHOT_NODE_MODULES'),
        'no_sandbox'   => (bool) env('BROWSERSHOT_NO_SANDBOX', false),
    ],

];
