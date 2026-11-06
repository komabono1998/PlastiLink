<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE neighbourhoods (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(100) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    latitude   DECIMAL(9,6) NULL,
    longitude  DECIMAL(9,6) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_neighbourhoods_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE collection_hubs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(20) NULL,
    name             VARCHAR(120) NOT NULL,
    neighbourhood_id INT UNSIGNED NOT NULL,
    address_note     VARCHAR(255) NULL,
    latitude         DECIMAL(9,6) NULL,
    longitude        DECIMAL(9,6) NULL,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hubs_code (code),
    UNIQUE KEY uq_hubs_name (name),
    KEY idx_hubs_neighbourhood (neighbourhood_id),
    CONSTRAINT fk_hubs_neighbourhood FOREIGN KEY (neighbourhood_id) REFERENCES neighbourhoods (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE hub_users (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hub_id     INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    hub_role   ENUM('manager','operator') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hub_user (hub_id, user_id),
    KEY idx_hub_users_user (user_id),
    CONSTRAINT fk_hu_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE CASCADE,
    CONSTRAINT fk_hu_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Initial pilot neighbourhoods (real programme areas, not demo data).
        $ins = $pdo->prepare('INSERT INTO neighbourhoods (name) VALUES (?)');
        foreach (['Walbiongo', 'Olailong', 'Kabedo Opong'] as $n) {
            $ins->execute([$n]);
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS hub_users');
        $pdo->exec('DROP TABLE IF EXISTS collection_hubs');
        $pdo->exec('DROP TABLE IF EXISTS neighbourhoods');
    },
];
