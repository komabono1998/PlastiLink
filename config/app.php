<?php
declare(strict_types=1);

/**
 * Application configuration (safe defaults = production).
 * Override per machine in config/app.local.php (git-ignored).
 */
return [
    'name'     => 'PlastiLink',
    'tagline'  => 'Turning Plastic Waste into Income.',
    'env'      => 'production',          // local | staging | production
    'debug'    => false,                 // NEVER true in production
    'timezone' => 'Africa/Kampala',
    'currency' => 'UGX',
    'locale'   => 'en_UG',
    'url'      => '',                    // e.g. https://plastilink.example (set in production; used in emailed links)
    'mail'     => ['driver' => 'log'],   // log = write to storage/logs/outbox.log (no real delivery yet)
    'auth'     => [
        'max_account_failures' => 5,     // failed logins per account...
        'max_ip_failures'      => 20,    // ...or per IP (higher: hubs share one network)
        'lockout_minutes'      => 15,
        'reset_token_minutes'  => 60,
        'reset_max_per_hour'   => 3,
    ],
    'finance'  => [
        'provider'           => 'manual',    // manual = staff record real mobile money / cash payments; mock = development simulator
        'payout_min_ugx'     => 1000,        // smallest payout request
        'payout_max_ugx'     => 5000000,     // largest single payout request
        'invoice_terms_days' => 14,          // days from issue to due date
    ],
    // Optional impact conversions. LEFT EMPTY on purpose: PlastiLink never invents statistics.
    // Fill these in ONLY with factors approved by the programme or a partner, and state the source.
    'impact'   => [
        'bottles_per_kg'  => null,   // e.g. number of PET bottles in 1 kg (use your own measured or published figure)
        'co2e_kg_per_kg'  => null,   // kg CO2-equivalent avoided per kg of PET recycled (cite the study)
        'source'          => '',     // where the factors come from; printed on the report next to the figures
    ],
    // External integrations (Phase 9). EVERYTHING here is off/empty by default. Put real values ONLY in config/app.local.php
    // (git-ignored) on the machine or server that needs them. Never commit keys.
    'integrations' => [
        'webhook_token' => '',               // secret in the callback URLs (/webhooks/<kind>/<token>, /ussd/<token>); 24+ random characters. Empty = endpoints disabled (404)
        'sms' => [
            'driver'   => 'log',             // log (writes storage/logs/outbox.log) | mock (tests) | africastalking
            'africastalking' => [
                'username'  => '',           // 'sandbox' while testing
                'api_key'   => '',
                'sender_id' => '',           // optional approved sender ID
                'sandbox'   => true,         // true = api.sandbox.africastalking.com; set false for live
                'base_url'  => '',           // optional override (tests)
            ],
        ],
        'ussd' => [
            'enabled'            => false,
            'payout_max_ugx'     => 100000,  // largest payout a picker can request by USSD (it still needs approval)
            'payout_max_per_day' => 3,
        ],
        'momo' => [
            'mtn' => [
                'enabled' => false, 'base_url' => 'https://sandbox.momodeveloper.mtn.com', 'environment' => 'sandbox',   // live: mtnuganda
                'currency' => 'EUR',         // sandbox only supports EUR; use UGX live
                'subscription_key' => '', 'api_user' => '', 'api_key' => '', 'callback_url' => '',
            ],
            'airtel' => [
                'enabled' => false, 'base_url' => 'https://openapiuat.airtel.africa', 'country' => 'UG', 'currency' => 'UGX',   // live: https://openapi.airtel.africa
                'client_id' => '', 'client_secret' => '', 'pin' => '', 'public_key' => '',   // public_key: PEM from Airtel, used to encrypt the PIN
                'status_path' => '/standard/v1/disbursements/{id}', 'callback_url' => '',
            ],
        ],
    ],
    'session'  => [
        'name'     => 'plastilink_session',
        'lifetime' => 7200,              // seconds of inactivity
    ],
];
