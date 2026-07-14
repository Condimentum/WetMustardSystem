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

    'microsoft' => [
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/auth/microsoft/callback'),
        'scopes' => explode(' ', env('MICROSOFT_SCOPES', 'openid profile email User.Read')),
    ],

    'microsoft_mail' => [
        'tenant_id' => env('MICROSOFT_MAIL_TENANT_ID'),
        'client_id' => env('MICROSOFT_MAIL_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
        'refresh_token' => env('MICROSOFT_MAIL_REFRESH_TOKEN'),
        'username' => env('OFFICE365_MAIL_USERNAME'),
    ],

    'bartender' => [
        'enabled' => env('BARTENDER_ENABLED', false),
        'base_url' => env('BARTENDER_BASE_URL', 'http://192.168.27.4/BarTender'),
        'authenticate_path' => env('BARTENDER_AUTH_PATH', '/api/v1/Authenticate'),
        'print_path' => env('BARTENDER_PRINT_PATH', '/api/v1/print'),
        'username' => env('BARTENDER_USERNAME'),
        'password' => env('BARTENDER_PASSWORD'),
        'library_id' => env('BARTENDER_LIBRARY_ID'),
        'default_printer' => env('BARTENDER_DEFAULT_PRINTER'),
        'enforce_printer_match' => env('BARTENDER_ENFORCE_PRINTER_MATCH', false),
        'timeout_seconds' => (int) env('BARTENDER_TIMEOUT_SECONDS', 30),
        'labels' => [
            'wet_mustard_test' => env('BARTENDER_LABEL_WET_MUSTARD_TEST', 'WetMustard LabelNew.btw'),
        ],
        'wet_mustard_shelf_life_months' => (int) env('BARTENDER_WET_MUSTARD_SHELF_LIFE_MONTHS', 5),
    ],

];
