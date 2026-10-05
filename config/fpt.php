<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Factory Performance Tracker Connection
    |--------------------------------------------------------------------------
    |
    | Separate PHP app (c:\xampp\htdocs\FactoryPerformanceTracker), same SQL
    | Server host, its own database. DBMTS reads/writes the same tables that
    | app's "Record Hours" screen uses, so data recorded in either app shows
    | up in both.
    |
    */

    'connection' => env('FPT_CONNECTION', 'fpt'),

    // "Wet Mustard - Manufacturing" department id in Factory Performance Tracker's
    // Departments table. DBMTS only ever reads/writes this one department's rows.
    'manufacturing_department_id' => (int) env('FPT_MANUFACTURING_DEPARTMENT_ID', 4),

    // Packing Hours entered against the manufacturing day is mirrored by Factory
    // Performance Tracker into this sibling department's own OperationalHours row.
    'packing_department_name' => env('FPT_PACKING_DEPARTMENT_NAME', 'Wet Mustard - Packing'),

];
