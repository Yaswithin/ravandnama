<?php

declare(strict_types=1);

use App\Core\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$application = new Application();
$application->run();
