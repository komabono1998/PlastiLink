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
    'session'  => [
        'name'     => 'plastilink_session',
        'lifetime' => 7200,              // seconds of inactivity
    ],
];
