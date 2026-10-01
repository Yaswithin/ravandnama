<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE tasks
            ADD COLUMN due_at_utc DATETIME(6) NULL AFTER due_at
        SQL,
];
