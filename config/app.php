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
    'session'  => [
        'name'     => 'plastilink_session',
        'lifetime' => 7200,              // seconds of inactivity
    ],
];
