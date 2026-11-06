<?php
declare(strict_types=1);

/**
 * Database defaults. NO real credentials here.
 * Put real values in config/database.local.php (git-ignored).
 */
return [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'plastilink_db',
    'username' => '',
    'password' => '',
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
