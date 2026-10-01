-- Fresh-database schema import for phpMyAdmin.
-- Generated from database/migrate.php and database/migrations/*.php.
-- Select an empty database before importing. Do not import into an existing
-- application database or rerun after a partial/successful import.
-- Uses utf8mb4_unicode_ci for MySQL/MariaDB compatibility.
-- No production data, accounts, credentials, or environment settings included.

CREATE TABLE IF NOT EXISTS migrations (
    migration VARCHAR(190) NOT NULL,
    executed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (migration)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 202609260001_create_users_table.php
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran',
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 202609260002_create_tasks_table.php
CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    due_at DATETIME(6) NULL,
    due_at_utc DATETIME(6) NULL,
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
  COLLATE=utf8mb4_unicode_ci;

-- 202609260003_create_projects_table.php
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
  COLLATE=utf8mb4_unicode_ci;

-- 202609260004_add_project_id_to_tasks_table.php
ALTER TABLE tasks
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD KEY tasks_project_id_idx (project_id),
    ADD CONSTRAINT tasks_project_id_foreign
        FOREIGN KEY (project_id) REFERENCES projects (id)
        ON DELETE SET NULL;

-- 202609260005_create_notes_table.php
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
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration) VALUES
    ('202609260001_create_users_table'),
    ('202609260002_create_tasks_table'),
    ('202609260003_create_projects_table'),
    ('202609260004_add_project_id_to_tasks_table'),
    ('202609260005_create_notes_table'),
    ('202609260006_add_timezone_to_users_table'),
    ('202609290001_add_due_at_utc_to_tasks_table');
