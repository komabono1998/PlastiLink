<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        // Optional details staff can add after registration
        $pdo->exec('ALTER TABLE pickers
            ADD COLUMN address_details VARCHAR(190) NULL AFTER national_id,
            ADD COLUMN notes VARCHAR(1000) NULL AFTER address_details');
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE pickers DROP COLUMN notes, DROP COLUMN address_details');
    },
];
