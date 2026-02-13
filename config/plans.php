<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plans Feature Toggle
    |--------------------------------------------------------------------------
    |
    | Set PLANS_ACTIVE=1 to enforce plan restrictions (usage/concurrency checks).
    | Set PLANS_ACTIVE=0 to bypass plan checks and allow unrestricted usage.
    |
    */
    'active' => (bool) env('PLANS_ACTIVE', false),

    /*
    |--------------------------------------------------------------------------
    | Admin API Key
    |--------------------------------------------------------------------------
    |
    | Used by admin-ready plan management endpoints via X-Admin-Key header.
    |
    */
    'admin_key' => env('PLANS_ADMIN_KEY', ''),
];
