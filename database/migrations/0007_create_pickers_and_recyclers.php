<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
CREATE TABLE pickers (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    picker_code         VARCHAR(20) NULL,
    user_id             INT UNSIGNED NOT NULL,
    neighbourhood_id    INT UNSIGNED NOT NULL,
    preferred_hub_id    INT UNSIGNED NULL,
    national_id         VARCHAR(30) NULL,
    verification_status ENUM('unverified','verified') NOT NULL DEFAULT 'unverified',
    consent_version     VARCHAR(30) NOT NULL,
    consented_at        DATETIME NOT NULL,
    registered_by       INT UNSIGNED NULL,
    review_note         VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pickers_code (picker_code),
    UNIQUE KEY uq_pickers_user (user_id),
    KEY idx_pickers_neighbourhood (neighbourhood_id),
    KEY idx_pickers_hub (preferred_hub_id),
    CONSTRAINT fk_pickers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pickers_neighbourhood FOREIGN KEY (neighbourhood_id) REFERENCES neighbourhoods (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pickers_hub FOREIGN KEY (preferred_hub_id) REFERENCES collection_hubs (id) ON DELETE SET NULL,
    CONSTRAINT fk_pickers_registered_by FOREIGN KEY (registered_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE recyclers (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    business_name       VARCHAR(150) NOT NULL,
    registration_number VARCHAR(60) NULL,
    contact_person      VARCHAR(150) NOT NULL,
    address             VARCHAR(255) NOT NULL,
    latitude            DECIMAL(9,6) NULL,
    longitude           DECIMAL(9,6) NULL,
    materials           VARCHAR(255) NULL,
    status              ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    decided_by          INT UNSIGNED NULL,
    decided_at          DATETIME NULL,
    decision_reason     VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recyclers_user (user_id),
    KEY idx_recyclers_status (status),
    CONSTRAINT fk_recyclers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_recyclers_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS recyclers');
        $pdo->exec('DROP TABLE IF EXISTS pickers');
    },
];
