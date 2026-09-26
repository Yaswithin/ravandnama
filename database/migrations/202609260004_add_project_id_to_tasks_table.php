<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE tasks
            ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER user_id,
            ADD KEY tasks_project_id_idx (project_id),
            ADD CONSTRAINT tasks_project_id_foreign
                FOREIGN KEY (project_id) REFERENCES projects (id)
                ON DELETE SET NULL
        SQL,
];
