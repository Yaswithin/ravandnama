<?php

declare(strict_types=1);

return [
    <<<'SQL'
        ALTER TABLE users
            ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran' AFTER email
        SQL,
];
