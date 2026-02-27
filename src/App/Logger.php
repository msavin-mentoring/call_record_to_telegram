<?php

declare(strict_types=1);

namespace App;

use DateTimeZone;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

final class Logger
{
    private static ?MonologLogger $logger = null;
    private static string $timezone = 'UTC';

    public static function setTimezone(string $timezone): void
    {
        self::$timezone = trim($timezone) !== '' ? trim($timezone) : 'UTC';
        if (self::$logger !== null) {
            try {
                self::$logger->setTimezone(new DateTimeZone(self::$timezone));
            } catch (\Throwable) {
                self::$logger->setTimezone(new DateTimeZone('UTC'));
            }
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::logger()->info(self::sanitize($message), self::sanitizeContext($context));
    }

    public static function warning(string $message, array $context = []): void
    {
        self::logger()->warning(self::sanitize($message), self::sanitizeContext($context));
    }

    public static function error(string $message, array $context = []): void
    {
        self::logger()->error(self::sanitize($message), self::sanitizeContext($context));
    }

    private static function logger(): MonologLogger
    {
        if (self::$logger !== null) {
            return self::$logger;
        }

        $handler = new StreamHandler('php://stdout', Level::Debug);
        $formatter = new LineFormatter(
            "[%datetime%] %level_name% %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            false,
            true
        );
        $handler->setFormatter($formatter);

        $logger = new MonologLogger('worker');
        $logger->pushHandler($handler);

        try {
            $logger->setTimezone(new DateTimeZone(self::$timezone));
        } catch (\Throwable) {
            $logger->setTimezone(new DateTimeZone('UTC'));
        }

        self::$logger = $logger;
        return self::$logger;
    }

    private static function sanitize(string $message): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $message);
        $value = preg_replace('/bot\d+:[A-Za-z0-9_\-]+/u', 'bot***', $value) ?? $value;
        return trim($value);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $normalized[$key] = self::sanitize($value);
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = self::sanitizeContext($value);
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
