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
    'session'  => [
        'name'     => 'plastilink_session',
        'lifetime' => 7200,              // seconds of inactivity
    ],
];
