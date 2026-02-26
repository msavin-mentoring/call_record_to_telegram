<?php

declare(strict_types=1);

namespace App;

final class Logger
{
    public static function info(string $message): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}
