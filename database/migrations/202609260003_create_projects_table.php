<?php

declare(strict_types=1);

return [
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS projects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY projects_user_created_idx (user_id, created_at),
            CONSTRAINT projects_user_id_foreign
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
          DEFAULT CHARACTER SET=utf8mb4
          COLLATE=utf8mb4_0900_ai_ci
        SQL,
];
