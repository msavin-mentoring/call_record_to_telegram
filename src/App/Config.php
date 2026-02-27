<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;
use RuntimeException;

final class Config
{
    public function __construct(
        public readonly string $recordingsDir,
        public readonly string $stateFile,
        public readonly string $tempDir,
        public readonly string $telegramToken,
        public readonly ?string $telegramChatId,
        public readonly int $telegramUploadMaxBytes,
        public readonly int $pollIntervalSeconds,
        public readonly int $fileMinAgeSeconds,
        public readonly int $stabilityWaitSeconds,
        public readonly int $clipDurationSeconds,
        public readonly bool $runOnce,
        public readonly int $updatesTimeoutSeconds,
        public readonly ?string $openAiApiKey,
        public readonly string $openAiTranscribeModel,
        public readonly string $openAiSummaryModel,
        public readonly ?string $openAiLanguage,
        public readonly int $openAiAudioChunkSeconds,
        public readonly int $openAiSummaryChunkChars,
        public readonly bool $sendTranscriptFile,
        public readonly int $reminderBaseSeconds,
        public readonly int $reminderMaxSeconds,
        public readonly string $reminderTimezone,
        public readonly int $reminderNightStartHour,
        public readonly int $reminderNightEndHour,
    ) {
    }

    public static function fromEnv(): self
    {
        self::bootstrapEnvValidation();

        return new self(
            recordingsDir: rtrim((string) getenv('RECORDINGS_DIR') ?: '/recordings', '/'),
            stateFile: (string) getenv('STATE_FILE') ?: '/app/data/state.json',
            tempDir: rtrim((string) getenv('TEMP_DIR') ?: '/tmp/call_clips', '/'),
            telegramToken: self::requiredEnv('TELEGRAM_BOT_TOKEN'),
            telegramChatId: self::nullableEnv('TELEGRAM_CHAT_ID'),
            telegramUploadMaxBytes: self::intEnv('TELEGRAM_UPLOAD_MAX_BYTES', 49 * 1024 * 1024),
            pollIntervalSeconds: self::intEnv('POLL_INTERVAL_SECONDS', 30),
            fileMinAgeSeconds: self::intEnv('FILE_MIN_AGE_SECONDS', 60),
            stabilityWaitSeconds: self::intEnv('STABILITY_WAIT_SECONDS', 5),
            clipDurationSeconds: self::intEnv('CLIP_DURATION_SECONDS', 10),
            runOnce: self::boolEnv('RUN_ONCE', false),
            updatesTimeoutSeconds: self::intEnv('UPDATES_TIMEOUT_SECONDS', 2),
            openAiApiKey: self::nullableEnv('OPENAI_API_KEY'),
            openAiTranscribeModel: self::stringEnv('OPENAI_TRANSCRIBE_MODEL', 'gpt-4o-mini-transcribe'),
            openAiSummaryModel: self::stringEnv('OPENAI_SUMMARY_MODEL', 'gpt-4o-mini'),
            openAiLanguage: self::nullableEnv('OPENAI_TRANSCRIBE_LANGUAGE'),
            openAiAudioChunkSeconds: self::intEnv('OPENAI_AUDIO_CHUNK_SECONDS', 900),
            openAiSummaryChunkChars: self::intEnv('OPENAI_SUMMARY_CHUNK_CHARS', 30000),
            sendTranscriptFile: self::boolEnv('SEND_TRANSCRIPT_FILE', true),
            reminderBaseSeconds: self::intEnv('REMINDER_BASE_SECONDS', 300),
            reminderMaxSeconds: self::intEnv('REMINDER_MAX_SECONDS', 14400),
            reminderTimezone: self::stringEnv('REMINDER_TIMEZONE', 'Europe/Moscow'),
            reminderNightStartHour: self::hourEnv('REMINDER_NIGHT_START_HOUR', 23),
            reminderNightEndHour: self::hourEnv('REMINDER_NIGHT_END_HOUR', 9),
        );
    }

    private static function bootstrapEnvValidation(): void
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeLoad();

        $dotenv->required(['TELEGRAM_BOT_TOKEN'])->notEmpty();

        $dotenv->ifPresent([
            'POLL_INTERVAL_SECONDS',
            'FILE_MIN_AGE_SECONDS',
            'STABILITY_WAIT_SECONDS',
            'CLIP_DURATION_SECONDS',
            'UPDATES_TIMEOUT_SECONDS',
            'OPENAI_AUDIO_CHUNK_SECONDS',
            'OPENAI_SUMMARY_CHUNK_CHARS',
            'TELEGRAM_UPLOAD_MAX_BYTES',
            'REMINDER_BASE_SECONDS',
            'REMINDER_MAX_SECONDS',
            'REMINDER_NIGHT_START_HOUR',
            'REMINDER_NIGHT_END_HOUR',
        ])->isInteger();

        $dotenv->ifPresent([
            'RUN_ONCE',
            'SEND_TRANSCRIPT_FILE',
        ])->isBoolean();
    }

    private static function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException("Environment variable {$name} is required.");
        }

        return trim($value);
    }

    private static function nullableEnv(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function stringEnv(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return trim((string) $value);
    }

    private static function intEnv(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException("Environment variable {$name} must be integer.");
        }

        return max(0, (int) $value);
    }

    private static function boolEnv(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function hourEnv(string $name, int $default): int
    {
        $hour = self::intEnv($name, $default);
        return max(0, min(23, $hour));
    }
}
