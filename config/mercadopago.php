<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The access token of the Mercado Pago application that charges your
    | customers. Use the test credentials of a test seller in sandbox.
    |
    */

    'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The secret shown next to the webhook URL in the Mercado Pago developer
    | panel. Requests are rejected while it is missing. The tolerance is the
    | maximum age, in seconds, of a signed notification (0 disables it).
    |
    */

    'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),

    'webhook_tolerance' => (int) env('MERCADOPAGO_WEBHOOK_TOLERANCE', 300),

    'routes' => [
        'enabled' => (bool) env('MERCADOPAGO_ROUTES_ENABLED', true),
        'prefix' => 'mercadopago',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription billing
    |--------------------------------------------------------------------------
    |
    | Plans live here, not in Mercado Pago: every checkout creates a
    | subscription without an associated plan using these values. Changing a
    | price only affects new checkouts; move existing subscribers with swap().
    |
    | frequency_type and trial.frequency_type accept "days" or "months".
    |
    */

    'billing' => [

        'plans' => [
            // 'pro' => [
            //     'reason' => 'My SaaS Pro',
            //     'amount' => 12000,
            //     'currency' => 'ARS',
            //     'frequency' => 1,
            //     'frequency_type' => 'months',
            //     'trial' => ['frequency' => 14, 'frequency_type' => 'days'],
            //     'back_url' => null,
            // ],
        ],

        // Mercado Pago statuses that grant access.
        'active_statuses' => ['authorized'],

        // Statuses that grant access while trial_ends_at is in the future.
        // "pending" lets a new tenant in before they finish the checkout.
        'trial_statuses' => ['authorized', 'pending'],

        // Where the Filament billing page sends tenants that are already
        // subscribed. Defaults to the tenant's panel home when null.
        'manage_url' => env('MERCADOPAGO_BILLING_MANAGE_URL'),

    ],

];
