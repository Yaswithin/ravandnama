<?php

declare(strict_types=1);

return [
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY notes_user_updated_idx (user_id, updated_at),
            CONSTRAINT notes_user_id_foreign
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
          DEFAULT CHARACTER SET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
        SQL,
];
