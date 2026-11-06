<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45)  NOT NULL,
    user_agent VARCHAR(255) NULL,
    successful TINYINT(1)   NOT NULL DEFAULT 0,
    reason     VARCHAR(40)  NULL,
    user_id    INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_identifier (identifier, created_at),
    KEY idx_la_ip (ip_address, created_at),
    KEY idx_la_user (user_id),
    CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_token (token_hash),
    KEY idx_pr_user (user_id, created_at),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80)  NOT NULL,
    entity_type VARCHAR(60)  NULL,
    entity_id   BIGINT UNSIGNED NULL,
    details     TEXT NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS audit_logs');
        $pdo->exec('DROP TABLE IF EXISTS password_resets');
        $pdo->exec('DROP TABLE IF EXISTS login_attempts');
    },
];
