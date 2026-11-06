<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE plastic_categories (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(20) NOT NULL,
    name       VARCHAR(80) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plastic_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE price_lists (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    hub_id              INT UNSIGNED NULL,
    price_per_kg_ugx    INT UNSIGNED NOT NULL,
    effective_from      DATETIME NOT NULL,
    note                VARCHAR(255) NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_price_lookup (plastic_category_id, hub_id, effective_from),
    CONSTRAINT fk_price_category FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_price_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_price_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        // PET is the primary material for the pilot. No prices are seeded: prices are a programme decision.
        $pdo->exec("INSERT INTO plastic_categories (code, name) VALUES ('PET', 'PET bottles')");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS price_lists');
        $pdo->exec('DROP TABLE IF EXISTS plastic_categories');
    },
];
