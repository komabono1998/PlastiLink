<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        // Outbound SMS queue (written in the same transaction as the event that causes it; sent by `php jobs.php send-sms`)
        $pdo->exec(<<<'SQL'
CREATE TABLE sms_outbox (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NULL,
    recipient           VARCHAR(16) NOT NULL,
    body                VARCHAR(480) NOT NULL,
    event               VARCHAR(40) NOT NULL,
    dedupe_key          VARCHAR(80) NULL,
    status              ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at     DATETIME NOT NULL,
    locked_at           DATETIME NULL,
    provider            VARCHAR(30) NULL,
    provider_message_id VARCHAR(80) NULL,
    error               VARCHAR(255) NULL,
    delivery_status     VARCHAR(20) NULL,
    delivered_at        DATETIME NULL,
    created_at          DATETIME NOT NULL,
    sent_at             DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sms_dedupe (dedupe_key),
    KEY idx_sms_due (status, next_attempt_at),
    KEY idx_sms_message (provider_message_id),
    CONSTRAINT fk_sms_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // One row per callback request (no payloads: they can contain phone numbers). Used for rate limiting and troubleshooting.
        $pdo->exec(<<<'SQL'
CREATE TABLE webhook_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source      VARCHAR(20) NOT NULL,
    ip_address  VARCHAR(45) NOT NULL,
    outcome     VARCHAR(30) NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_webhook_ip (ip_address, created_at),
    KEY idx_webhook_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec('ALTER TABLE users ADD COLUMN sms_opt_out TINYINT(1) NOT NULL DEFAULT 0');
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('ALTER TABLE users DROP COLUMN sms_opt_out');
        $pdo->exec('DROP TABLE IF EXISTS webhook_log');
        $pdo->exec('DROP TABLE IF EXISTS sms_outbox');
    },
];
