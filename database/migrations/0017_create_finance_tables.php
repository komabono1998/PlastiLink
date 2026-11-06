<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        // Append-only money ledger. Positive/negative amounts are from the account's point of view:
        //   picker:   +earning, -payout            (balance > 0 means PlastiLink owes the picker)
        //   recycler: +invoice, -payment received  (balance > 0 means the recycler owes PlastiLink)
        $pdo->exec(<<<'SQL'
CREATE TABLE financial_ledger (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_type ENUM('picker','recycler') NOT NULL,
    account_id   INT UNSIGNED NOT NULL,
    entry_type   ENUM('collection_earning','collection_reversal','payout','payout_reversal',
                      'invoice_issued','invoice_void','invoice_payment','invoice_payment_reversal') NOT NULL,
    amount_ugx   BIGINT NOT NULL,
    source_type  VARCHAR(30) NOT NULL,
    source_id    BIGINT UNSIGNED NOT NULL,
    reference    VARCHAR(40) NULL,
    note         VARCHAR(255) NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ledger_source (source_type, source_id, entry_type),
    KEY idx_ledger_account (account_type, account_id, created_at),
    CONSTRAINT fk_ledger_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ledger_nonzero CHECK (amount_ugx <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE invoices (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_number   VARCHAR(30) NULL,
    order_id         BIGINT UNSIGNED NOT NULL,
    recycler_id      INT UNSIGNED NOT NULL,
    hub_id           INT UNSIGNED NOT NULL,
    received_kg      DECIMAL(10,2) NOT NULL,
    price_per_kg_ugx INT UNSIGNED NOT NULL,
    amount_ugx       BIGINT UNSIGNED NOT NULL,
    issued_at        DATETIME NOT NULL,
    due_date         DATE NOT NULL,
    status           ENUM('issued','partially_paid','paid','void') NOT NULL DEFAULT 'issued',
    voided_by        INT UNSIGNED NULL,
    voided_at        DATETIME NULL,
    void_reason      VARCHAR(500) NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_number (invoice_number),
    UNIQUE KEY uq_invoice_order (order_id),
    KEY idx_invoice_recycler (recycler_id, status),
    KEY idx_invoice_due (status, due_date),
    CONSTRAINT fk_inv_order FOREIGN KEY (order_id) REFERENCES purchase_orders (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_recycler FOREIGN KEY (recycler_id) REFERENCES recyclers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_hub FOREIGN KEY (hub_id) REFERENCES collection_hubs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoice_voided_by FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_invoice_amount CHECK (amount_ugx > 0 AND received_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE payment_requests (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference         VARCHAR(30) NULL,
    submission_token  CHAR(32) NOT NULL,
    picker_id         INT UNSIGNED NOT NULL,
    amount_ugx        BIGINT UNSIGNED NOT NULL,
    method            ENUM('cash','mobile_money') NOT NULL,
    destination_phone VARCHAR(16) NULL,
    status            ENUM('pending_approval','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'pending_approval',
    requested_by      INT UNSIGNED NOT NULL,
    requested_at      DATETIME NOT NULL,
    decided_by        INT UNSIGNED NULL,
    decided_at        DATETIME NULL,
    decision_note     VARCHAR(255) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prq_reference (reference),
    UNIQUE KEY uq_prq_token (submission_token),
    KEY idx_prq_picker (picker_id, status),
    CONSTRAINT fk_prq_picker FOREIGN KEY (picker_id) REFERENCES pickers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_prq_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_prq_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_prq_amount CHECK (amount_ugx > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // One row per money movement. direction 'out' = payout to a picker, 'in' = receipt from a recycler against an invoice.
        $pdo->exec(<<<'SQL'
CREATE TABLE payments (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference          VARCHAR(30) NULL,
    idempotency_key    VARCHAR(64) NOT NULL,
    direction          ENUM('out','in') NOT NULL,
    picker_id          INT UNSIGNED NULL,
    recycler_id        INT UNSIGNED NULL,
    request_id         BIGINT UNSIGNED NULL,
    invoice_id         BIGINT UNSIGNED NULL,
    amount_ugx         BIGINT UNSIGNED NOT NULL,
    method             ENUM('cash','mobile_money','bank') NOT NULL,
    provider           VARCHAR(30) NOT NULL,
    provider_reference VARCHAR(80) NULL,
    destination_phone  VARCHAR(16) NULL,
    status             ENUM('pending','processing','successful','failed','reversed') NOT NULL DEFAULT 'pending',
    failure_reason     VARCHAR(255) NULL,
    received_on        DATE NULL,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL,
    processed_by       INT UNSIGNED NULL,
    processed_at       DATETIME NULL,
    confirmed_by       INT UNSIGNED NULL,
    confirmed_at       DATETIME NULL,
    reversed_by        INT UNSIGNED NULL,
    reversed_at        DATETIME NULL,
    reversal_reason    VARCHAR(500) NULL,
    reconciled_by      INT UNSIGNED NULL,
    reconciled_at      DATETIME NULL,
    statement_reference VARCHAR(80) NULL,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_reference (reference),
    UNIQUE KEY uq_payment_idem (idempotency_key),
    UNIQUE KEY uq_payment_provider_ref (provider, provider_reference),
    KEY idx_payment_picker (picker_id, status),
    KEY idx_payment_invoice (invoice_id, status),
    KEY idx_payment_status (status, created_at),
    CONSTRAINT fk_pay_picker FOREIGN KEY (picker_id) REFERENCES pickers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pay_recycler FOREIGN KEY (recycler_id) REFERENCES recyclers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pay_request FOREIGN KEY (request_id) REFERENCES payment_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pay_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pay_processed_by FOREIGN KEY (processed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_reversed_by FOREIGN KEY (reversed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pay_reconciled_by FOREIGN KEY (reconciled_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_pay_amount CHECK (amount_ugx > 0),
    CONSTRAINT chk_pay_party CHECK ((direction = 'out' AND picker_id IS NOT NULL AND recycler_id IS NULL AND invoice_id IS NULL)
                                 OR (direction = 'in' AND recycler_id IS NOT NULL AND invoice_id IS NOT NULL AND picker_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE payment_attempts (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id         BIGINT UNSIGNED NOT NULL,
    attempt_no         TINYINT UNSIGNED NOT NULL,
    provider           VARCHAR(30) NOT NULL,
    status             ENUM('processing','successful','failed') NOT NULL,
    provider_reference VARCHAR(80) NULL,
    message            VARCHAR(255) NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attempt_no (payment_id, attempt_no),
    CONSTRAINT fk_attempt_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_attempt_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },

    'down' => function (PDO $pdo): void {
        foreach (['payment_attempts', 'payments', 'payment_requests', 'invoices', 'financial_ledger'] as $t) {
            $pdo->exec("DROP TABLE IF EXISTS $t");
        }
    },
];
