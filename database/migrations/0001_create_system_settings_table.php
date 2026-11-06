<?php
declare(strict_types=1);

/** Migration files return ['up' => fn(PDO), 'down' => fn(PDO)]. */
return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE system_settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key   VARCHAR(100)  NOT NULL,
    setting_value TEXT          NULL,
    value_type    ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
    description   VARCHAR(255)  NULL,
    is_public     TINYINT(1)    NOT NULL DEFAULT 0,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Default configuration (not demo data). Targets come from the project brief.
        $rows = [
            ['app.name',              'PlastiLink',                          'string', 'Application name', 1],
            ['app.tagline',           'Turning Plastic Waste into Income.',  'string', 'Application tagline', 1],
            ['app.currency',          'UGX',                                 'string', 'Default currency', 1],
            ['app.timezone',          'Africa/Kampala',                      'string', 'Application timezone', 0],
            ['target.pilot_pickers',  '30',                                  'int',    'Pilot waste picker target', 1],
            ['target.pickers',        '100',                                 'int',    '12-month registered waste picker target', 1],
            ['target.hubs',           '3',                                   'int',    '12-month collection hub target', 1],
            ['target.recyclers',      '3',                                   'int',    'Minimum recycling company target', 1],
            ['target.kg_12_months',   '30000',                               'int',    '12-month plastic collected and sold (kg)', 1],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, value_type, description, is_public)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $stmt->execute($r);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS system_settings');
    },
];
