<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        // Running balance per hub and material. Derived from stock_movements (never edited by hand); verified against the ledger.
        $pdo->exec(<<<'SQL'
CREATE TABLE inventory (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hub_id              INT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    on_hand_kg          DECIMAL(10,2) NOT NULL DEFAULT 0,
    reserved_kg         DECIMAL(10,2) NOT NULL DEFAULT 0,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_hub_cat (hub_id, plastic_category_id),
    CONSTRAINT fk_inv_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_cat FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT chk_inv_non_negative CHECK (on_hand_kg >= 0 AND reserved_kg >= 0 AND reserved_kg <= on_hand_kg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Append-only ledger. qty_kg is the signed change in stock on hand; reserved_delta_kg the signed change in reserved stock.
        $pdo->exec(<<<'SQL'
CREATE TABLE stock_movements (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hub_id              INT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    movement_type       ENUM('collection_in','collection_reversal','transfer_out','transfer_in','transfer_return',
                             'dispatch_out','dispatch_return','adjustment_in','adjustment_out','write_off','reserve','release') NOT NULL,
    qty_kg              DECIMAL(10,2) NOT NULL DEFAULT 0,
    reserved_delta_kg   DECIMAL(10,2) NOT NULL DEFAULT 0,
    balance_after_kg    DECIMAL(10,2) NOT NULL,
    reserved_after_kg   DECIMAL(10,2) NOT NULL,
    source_type         VARCHAR(30) NOT NULL,
    source_id           BIGINT UNSIGNED NOT NULL,
    reference           VARCHAR(40) NULL,
    reason              VARCHAR(255) NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_movement_source (source_type, source_id, movement_type),
    KEY idx_movement_hub (hub_id, plastic_category_id, created_at),
    KEY idx_movement_type (movement_type, created_at),
    CONSTRAINT fk_mov_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_cat FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mov_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE stock_transfers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(30) NULL,
    submission_token    CHAR(32) NOT NULL,
    from_hub_id         INT UNSIGNED NOT NULL,
    to_hub_id           INT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    qty_sent_kg         DECIMAL(10,2) NOT NULL,
    qty_received_kg     DECIMAL(10,2) NULL,
    status              ENUM('in_transit','received','cancelled') NOT NULL DEFAULT 'in_transit',
    notes               VARCHAR(255) NULL,
    sent_by             INT UNSIGNED NOT NULL,
    sent_at             DATETIME NOT NULL,
    received_by         INT UNSIGNED NULL,
    received_at         DATETIME NULL,
    receive_note        VARCHAR(255) NULL,
    cancelled_by        INT UNSIGNED NULL,
    cancelled_at        DATETIME NULL,
    cancel_reason       VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transfer_ref (reference),
    UNIQUE KEY uq_transfer_token (submission_token),
    KEY idx_transfer_from (from_hub_id, status),
    KEY idx_transfer_to (to_hub_id, status),
    CONSTRAINT fk_tr_from FOREIGN KEY (from_hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tr_to FOREIGN KEY (to_hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tr_cat FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tr_sent_by FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tr_recv_by FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tr_cancel_by FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_tr_hubs CHECK (from_hub_id <> to_hub_id),
    CONSTRAINT chk_tr_qty CHECK (qty_sent_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE stock_dispatches (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(30) NULL,
    submission_token    CHAR(32) NOT NULL,
    hub_id              INT UNSIGNED NOT NULL,
    recycler_id         INT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    qty_kg              DECIMAL(10,2) NOT NULL,
    status              ENUM('dispatched','delivered','cancelled') NOT NULL DEFAULT 'dispatched',
    vehicle             VARCHAR(60) NULL,
    driver              VARCHAR(100) NULL,
    notes               VARCHAR(255) NULL,
    dispatched_by       INT UNSIGNED NOT NULL,
    dispatched_at       DATETIME NOT NULL,
    delivered_by        INT UNSIGNED NULL,
    delivered_at        DATETIME NULL,
    delivery_note       VARCHAR(255) NULL,
    cancelled_by        INT UNSIGNED NULL,
    cancelled_at        DATETIME NULL,
    cancel_reason       VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dispatch_ref (reference),
    UNIQUE KEY uq_dispatch_token (submission_token),
    KEY idx_dispatch_hub (hub_id, status),
    KEY idx_dispatch_recycler (recycler_id),
    CONSTRAINT fk_dp_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dp_recycler FOREIGN KEY (recycler_id) REFERENCES recyclers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dp_cat FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dp_by FOREIGN KEY (dispatched_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dp_deliv_by FOREIGN KEY (delivered_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_dp_cancel_by FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_dp_qty CHECK (qty_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Manual stock entry, write-offs of rejected material and physical-count variances: all need a second person to approve.
        $pdo->exec(<<<'SQL'
CREATE TABLE stock_adjustments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(30) NULL,
    submission_token    CHAR(32) NOT NULL,
    hub_id              INT UNSIGNED NOT NULL,
    plastic_category_id SMALLINT UNSIGNED NOT NULL,
    kind                ENUM('manual_entry','write_off','count') NOT NULL,
    system_kg           DECIMAL(10,2) NULL,
    counted_kg          DECIMAL(10,2) NULL,
    change_kg           DECIMAL(10,2) NOT NULL,
    reason              VARCHAR(255) NOT NULL,
    status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by        INT UNSIGNED NOT NULL,
    requested_at        DATETIME NOT NULL,
    decided_by          INT UNSIGNED NULL,
    decided_at          DATETIME NULL,
    decision_note       VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_adjust_ref (reference),
    UNIQUE KEY uq_adjust_token (submission_token),
    KEY idx_adjust_hub (hub_id, status),
    CONSTRAINT fk_adj_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_adj_cat FOREIGN KEY (plastic_category_id) REFERENCES plastic_categories (id) ON DELETE RESTRICT,
    CONSTRAINT fk_adj_req FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_adj_dec FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_adj_change CHECK (change_kg <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS stock_adjustments');
        $pdo->exec('DROP TABLE IF EXISTS stock_dispatches');
        $pdo->exec('DROP TABLE IF EXISTS stock_transfers');
        $pdo->exec('DROP TABLE IF EXISTS stock_movements');
        $pdo->exec('DROP TABLE IF EXISTS inventory');
    },
];
