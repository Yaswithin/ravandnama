<?php

declare(strict_types=1);

return [
    <<<'SQL'
        CREATE TABLE IF NOT EXISTS tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
            due_at DATETIME(6) NULL,
            completed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            KEY tasks_user_status_idx (user_id, status),
            KEY tasks_user_due_at_idx (user_id, due_at),
            CONSTRAINT tasks_user_id_foreign
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
          DEFAULT CHARACTER SET=utf8mb4
          COLLATE=utf8mb4_0900_ai_ci
        SQL,
];
