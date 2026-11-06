<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE purchase_orders (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(30) NULL,
    submission_token    CHAR(32) NOT NULL,
    recycler_id         INT UNSIGNED NOT NULL,
    hub_id              INT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    status              ENUM('negotiating','confirmed','in_delivery','completed','rejected','withdrawn','cancelled','expired') NOT NULL DEFAULT 'negotiating',
    last_offer_by       ENUM('recycler','seller') NOT NULL,
    round_count         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    expires_at          DATETIME NULL,
    created_by          INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL,
    scheduled_for       DATE NULL,
    schedule_note       VARCHAR(255) NULL,
    agreed_at           DATETIME NULL,
    accepted_by         INT UNSIGNED NULL,
    completed_at        DATETIME NULL,
    short_closed_kg     DECIMAL(10,2) NOT NULL DEFAULT 0,
    end_note            VARCHAR(500) NULL,
    ended_by            INT UNSIGNED NULL,
    ended_at            DATETIME NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_reference (reference),
    UNIQUE KEY uq_po_token (submission_token),
    KEY idx_po_recycler (recycler_id, status),
    KEY idx_po_hub (hub_id, status),
    KEY idx_po_expiry (status, expires_at),
    CONSTRAINT fk_po_recycler FOREIGN KEY (recycler_id) REFERENCES recyclers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_cat FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_accepted FOREIGN KEY (accepted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_po_ended FOREIGN KEY (ended_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Agreed terms, locked at acceptance. Delivered/received weights accumulate here; Phase 7 invoices from received_kg x price.
        $pdo->exec(<<<'SQL'
CREATE TABLE purchase_order_items (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id            BIGINT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    agreed_qty_kg       DECIMAL(10,2) NOT NULL,
    price_per_kg_ugx    INT UNSIGNED NOT NULL,
    line_total_ugx      BIGINT UNSIGNED NOT NULL,
    dispatched_kg       DECIMAL(10,2) NOT NULL DEFAULT 0,
    received_kg         DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_poi_order (order_id),
    CONSTRAINT fk_poi_order FOREIGN KEY (order_id) REFERENCES purchase_orders (id) ON DELETE RESTRICT,
    CONSTRAINT fk_poi_cat FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT chk_poi_qty CHECK (agreed_qty_kg > 0 AND dispatched_kg >= 0 AND dispatched_kg <= agreed_qty_kg AND received_kg >= 0 AND received_kg <= dispatched_kg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE order_offers (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         BIGINT UNSIGNED NOT NULL,
    round_no         TINYINT UNSIGNED NOT NULL,
    offered_by_side  ENUM('recycler','seller') NOT NULL,
    offered_by       INT UNSIGNED NOT NULL,
    qty_kg           DECIMAL(10,2) NOT NULL,
    price_per_kg_ugx INT UNSIGNED NOT NULL,
    total_ugx        BIGINT UNSIGNED NOT NULL,
    delivery_date    DATE NULL,
    note             VARCHAR(255) NULL,
    status           ENUM('open','accepted','superseded','rejected','withdrawn','expired') NOT NULL DEFAULT 'open',
    created_at       DATETIME NOT NULL,
    responded_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_offer_round (order_id, round_no),
    CONSTRAINT fk_offer_order FOREIGN KEY (order_id) REFERENCES purchase_orders (id) ON DELETE RESTRICT,
    CONSTRAINT fk_offer_user FOREIGN KEY (offered_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_offer_qty CHECK (qty_kg > 0 AND price_per_kg_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Dispatches made against an order, and the recycler's confirmation of what arrived.
        $pdo->exec('ALTER TABLE stock_dispatches
            ADD COLUMN purchase_order_id BIGINT UNSIGNED NULL AFTER recycler_id,
            ADD COLUMN received_kg DECIMAL(10,2) NULL AFTER delivery_note,
            ADD COLUMN receive_note VARCHAR(255) NULL AFTER received_kg,
            ADD KEY idx_dispatch_order (purchase_order_id),
            ADD CONSTRAINT fk_dp_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE RESTRICT');
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE stock_dispatches DROP FOREIGN KEY fk_dp_order');
        $pdo->exec('ALTER TABLE stock_dispatches DROP KEY idx_dispatch_order, DROP COLUMN receive_note, DROP COLUMN received_kg, DROP COLUMN purchase_order_id');
        $pdo->exec('DROP TABLE IF EXISTS order_offers');
        $pdo->exec('DROP TABLE IF EXISTS purchase_order_items');
        $pdo->exec('DROP TABLE IF EXISTS purchase_orders');
    },
];
