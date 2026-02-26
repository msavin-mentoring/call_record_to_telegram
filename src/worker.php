<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Logger;
use App\WorkerApplication;

try {
    (new WorkerApplication())->run();
} catch (\Throwable $e) {
    Logger::info('Fatal error: ' . $e->getMessage());
    exit(1);
}
