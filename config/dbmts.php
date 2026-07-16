<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DBMTS Administrators
    |--------------------------------------------------------------------------
    |
    | Email addresses granted the DBMTS "administrator" role on sign-in. Every
    | other authenticated tenant user receives the default "operator" role.
    | Comma-separated in DBMTS_ADMIN_EMAILS.
    |
    */

    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DBMTS_ADMIN_EMAILS', 'adrian.lacki@condimentum.co.uk,stuart.riches@condimentum.co.uk'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Temporary Login Allowlist
    |--------------------------------------------------------------------------
    |
    | Restrict sign-in to specific email addresses while temporary access
    | controls are in place. Applies to both email/password and Microsoft
    | OAuth sign-in paths.
    |
    */

    'temporary_login_allow_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DBMTS_TEMP_LOGIN_ALLOW_EMAILS', 'adrian.lacki@condimentum.co.uk'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Centralized runtime switches for operational UI and integration behavior.
    | Keep defaults conservative and control environment-specific behavior via
    | .env values.
    |
    */

    'features' => [
        'allocation' => [
            'scanner' => (bool) env('DBMTS_FEATURE_ALLOCATION_SCANNER', true),
            'scanner_camera_autostart' => (bool) env('DBMTS_FEATURE_ALLOCATION_SCANNER_CAMERA_AUTOSTART', true),
            'scanner_debug_panel' => (bool) env('DBMTS_FEATURE_ALLOCATION_SCANNER_DEBUG_PANEL', true),
            'scanner_winman_lookup' => (bool) env('DBMTS_FEATURE_ALLOCATION_SCANNER_WINMAN_LOOKUP', true),
        ],
    ],

];
