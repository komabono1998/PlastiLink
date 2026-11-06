<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id             TINYINT UNSIGNED NOT NULL,
    full_name           VARCHAR(150) NOT NULL,
    email               VARCHAR(190) NULL,
    phone_e164          VARCHAR(16)  NULL,
    password_hash       VARCHAR(255) NOT NULL,
    status              ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
    last_login_at       DATETIME NULL,
    last_login_ip       VARCHAR(45) NULL,
    password_changed_at DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone_e164),
    KEY idx_users_role (role_id),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT,
    CONSTRAINT chk_users_contact CHECK (email IS NOT NULL OR phone_e164 IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS users');
    },
];
