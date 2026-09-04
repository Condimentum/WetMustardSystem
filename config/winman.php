<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WinMan Read Connection
    |--------------------------------------------------------------------------
    |
    | The Laravel database connection used for all read-only WinMan ERP access.
    | DBMTS never writes to WinMan tables directly; finished-goods booking (a
    | later phase) uses approved stored procedures only.
    |
    */

    'connection' => env('WINMAN_CONNECTION', 'winman'),

    /*
    |--------------------------------------------------------------------------
    | Environment Selection
    |--------------------------------------------------------------------------
    |
    | WinMan runs a production database (Condimentum) and a pre-release database
    | (PreRelease) for debug/testing on the same SQL Server host. The active
    | database name is resolved from configuration here rather than being
    | hardcoded in query logic (scope §11.1).
    |
    */

    'environment' => env('WINMAN_ENVIRONMENT', 'production'),

    'databases' => [
        'production' => env('WINMAN_DB_DATABASE', 'Condimentum'),
        'prerelease' => env('WINMAN_DB_PRERELEASE_DATABASE', 'PreRelease'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manufacturing Order Eligibility
    |--------------------------------------------------------------------------
    |
    | Only outstanding MOs of these WinMan SystemType codes are selectable in
    | DBMTS (scope §11.2: "firm or in-progress system types").
    |   F = Firm, I = In Progress, R = Released, P = Planned, C = Completed.
    |
    */

    'eligible_system_types' => ['F', 'I', 'R'],

    'system_type_labels' => [
        'F' => 'Firm',
        'I' => 'Issued',
        'R' => 'Released',
        'P' => 'Planned',
        'C' => 'Completed',
        'D' => 'Draft',
    ],

    /*
    |--------------------------------------------------------------------------
    | Live BOM / Component Item Types
    |--------------------------------------------------------------------------
    |
    | WorkInProgress.ItemType codes that represent consumable material/product
    | components (rows that carry a WinMan Product). Routing/resource lines
    | (ItemType 'R', Product NULL) are excluded.
    |
    | NOTE: Mustard-specific classification and issue rules must be reviewed
    | during implementation. Mint-specific classification assumptions must not
    | be copied blindly (scope §11.3).
    |
    */

    'component_item_types' => ['C', 'M'],

    /*
    |--------------------------------------------------------------------------
    | Component Issue (WIP Consumption)
    |--------------------------------------------------------------------------
    |
    | Ingredient allocation issues stock to WinMan immediately against a specific
    | WorkInProgress line via approved WinMan procedures.
    |
    */

    'issue' => [
        'inventory_issue_procedure' => 'wsp_InventoryIssue',
        'non_wmgo_procedure' => 'bsp_ManufacturingOrdersIssueNonWMGO',
        // Keep disabled for line-by-line allocation to avoid issuing unrelated MO lines.
        'run_non_wmgo_after_issue' => env('WINMAN_ISSUE_RUN_NON_WMGO_AFTER_ISSUE', false),
        // Optional status-only call that can transition MO state (for example F -> I)
        // without issuing additional materials.
        'status_only_procedure' => env('WINMAN_ISSUE_STATUS_ONLY_PROCEDURE', 'bsp_ManufacturingOrdersSetIssuedStatus'),
        'run_status_only_after_issue' => env('WINMAN_ISSUE_RUN_STATUS_ONLY_AFTER_ISSUE', true),
        'user_name' => env('WINMAN_ISSUE_USER', env('WINMAN_BOOKING_USER', 'DBMTS')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Finished-Goods Booking
    |--------------------------------------------------------------------------
    |
    | Controlled booking of completed finished goods to an existing WinMan MO via
    | the approved wsp_ManufacturingOrdersFinishing stored procedure (scope
    | §11.5). Booking is DISABLED by default and should only be enabled against
    | the PreRelease environment for testing. DBMTS never INSERTs/UPDATEs WinMan
    | tables directly and never creates WinMan MOs.
    |
    */

    'booking' => [
        'enabled' => env('WINMAN_BOOKING_ENABLED', false),
        'procedure' => 'wsp_ManufacturingOrdersFinishing',
        'user_name' => env('WINMAN_BOOKING_USER', 'DBMTS'),
        'notes' => '',
        // Internal WinMan Location key for the finished-goods receipt. When null,
        // the MO's own Location is used.
        'location_id' => env('WINMAN_BOOKING_LOCATION_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resilience (WinMan unavailability)
    |--------------------------------------------------------------------------
    |
    | WinMan is a separate, sometimes-unreachable server. These settings keep
    | DBMTS responsive and independently usable for existing batches when
    | WinMan is slow or down (see Documents/POC-Feature-Overview.md tier 1).
    |
    */

    'resilience' => [
        // Outstanding-MO search results are cached briefly so repeated page
        // loads/renders don't re-hit WinMan every time; a short TTL keeps the
        // list operationally fresh while absorbing burst traffic.
        'search_cache_seconds' => (int) env('WINMAN_SEARCH_CACHE_SECONDS', 30),
        // How long a cached "is WinMan reachable" health-check result is
        // trusted before probing again.
        'health_check_cache_seconds' => (int) env('WINMAN_HEALTH_CHECK_CACHE_SECONDS', 15),
    ],

];
