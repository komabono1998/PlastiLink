<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE collection_transactions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(30) NULL,
    submission_token    CHAR(32) NOT NULL,
    picker_id           INT UNSIGNED NOT NULL,
    hub_id              INT UNSIGNED NOT NULL,
    neighbourhood_id    INT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    status              ENUM('pending','approved','rejected','reversed') NOT NULL DEFAULT 'pending',
    gross_weight_kg     DECIMAL(8,2) NOT NULL,
    tare_weight_kg      DECIMAL(8,2) NOT NULL DEFAULT 0,
    net_weight_kg       DECIMAL(8,2) NOT NULL,
    price_per_kg_ugx    INT UNSIGNED NOT NULL,
    price_list_id       INT UNSIGNED NULL,
    total_value_ugx     BIGINT UNSIGNED NOT NULL,
    notes               VARCHAR(255) NULL,
    recorded_by         INT UNSIGNED NOT NULL,
    recorded_at         DATETIME NOT NULL,
    decided_by          INT UNSIGNED NULL,
    decided_at          DATETIME NULL,
    decision_reason     VARCHAR(500) NULL,
    reversed_by         INT UNSIGNED NULL,
    reversed_at         DATETIME NULL,
    reversal_reason     VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_collection_reference (reference),
    UNIQUE KEY uq_collection_token (submission_token),
    KEY idx_collection_hub_status (hub_id, status, recorded_at),
    KEY idx_collection_picker (picker_id, recorded_at),
    KEY idx_collection_status (status, recorded_at),
    CONSTRAINT fk_col_picker FOREIGN KEY (picker_id) REFERENCES pickers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_col_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_col_neighbourhood FOREIGN KEY (neighbourhood_id) REFERENCES neighbourhoods (id) ON DELETE RESTRICT,
    CONSTRAINT fk_col_category FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_col_price FOREIGN KEY (price_list_id) REFERENCES price_lists (id) ON DELETE SET NULL,
    CONSTRAINT fk_col_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_col_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_col_reversed_by FOREIGN KEY (reversed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_col_weights CHECK (gross_weight_kg >= tare_weight_kg AND net_weight_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE weighing_records (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id    BIGINT UNSIGNED NOT NULL,
    sequence_no      TINYINT UNSIGNED NOT NULL,
    gross_weight_kg  DECIMAL(8,2) NOT NULL,
    tare_weight_kg   DECIMAL(8,2) NOT NULL,
    net_weight_kg    DECIMAL(8,2) NOT NULL,
    price_per_kg_ugx INT UNSIGNED NOT NULL,
    total_value_ugx  BIGINT UNSIGNED NOT NULL,
    reason           VARCHAR(255) NULL,
    weighed_by       INT UNSIGNED NOT NULL,
    weighed_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_weighing_seq (collection_id, sequence_no),
    CONSTRAINT fk_weigh_collection FOREIGN KEY (collection_id) REFERENCES collection_transactions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_weigh_user FOREIGN KEY (weighed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE receipts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_number VARCHAR(30) NULL,
    collection_id  BIGINT UNSIGNED NOT NULL,
    picker_id      INT UNSIGNED NOT NULL,
    issued_by      INT UNSIGNED NULL,
    issued_at      DATETIME NOT NULL,
    snapshot       TEXT NOT NULL,
    voided_at      DATETIME NULL,
    void_reason    VARCHAR(500) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipt_number (receipt_number),
    UNIQUE KEY uq_receipt_collection (collection_id),
    KEY idx_receipt_picker (picker_id),
    CONSTRAINT fk_receipt_collection FOREIGN KEY (collection_id) REFERENCES collection_transactions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipt_picker FOREIGN KEY (picker_id) REFERENCES pickers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipt_user FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS receipts');
        $pdo->exec('DROP TABLE IF EXISTS weighing_records');
        $pdo->exec('DROP TABLE IF EXISTS collection_transactions');
    },
];
