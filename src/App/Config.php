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
        public readonly string $appTimezone,
        public readonly string $telegramToken,
        public readonly ?string $telegramChatId,
        public readonly string $telegramApiBaseUrl,
        public readonly string $telegramUploadApiBaseUrl,
        /** @var string[] */
        public readonly array $telegramParticipantPresets,
        public readonly int $telegramUploadMaxBytes,
        public readonly int $pollIntervalSeconds,
        public readonly int $fileMinAgeSeconds,
        public readonly int $stabilityWaitSeconds,
        public readonly int $clipDurationSeconds,
        public readonly bool $runOnce,
        public readonly int $updatesTimeoutSeconds,
        public readonly ?string $openAiApiKey,
        public readonly string $openAiBaseUrl,
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
        public readonly string $platformApiBaseUrl,
        public readonly string $platformIdentityExchangeSecret,
        public readonly string $platformIdentityExchangeIssuer,
        public readonly string $platformIdentityExchangeAudience,
        public readonly int $platformApiActorSubjectId,
        public readonly string $platformApiTelegramUsername,
        public readonly ?string $platformApiDisplayName,
        public readonly int $platformStudentsCacheSeconds,
        public readonly ?string $platformDbHost,
        public readonly int $platformDbPort,
        public readonly ?string $platformDbName,
        public readonly ?string $platformDbUser,
        public readonly ?string $platformDbPassword,
        public readonly int $platformSessionMatchWindowMinutes,
    ) {
    }

    public static function fromEnv(): self
    {
        self::bootstrapEnvValidation();
        $telegramApiBaseUrl = self::stringEnv('TELEGRAM_API_BASE_URL', 'https://api.telegram.org');

        return new self(
            recordingsDir: rtrim((string) getenv('RECORDINGS_DIR') ?: '/recordings', '/'),
            stateFile: (string) getenv('STATE_FILE') ?: '/app/data/state.json',
            tempDir: rtrim((string) getenv('TEMP_DIR') ?: '/tmp/call_clips', '/'),
            appTimezone: self::stringEnv('APP_TIMEZONE', 'Europe/Moscow'),
            telegramToken: self::requiredEnv('TELEGRAM_BOT_TOKEN'),
            telegramChatId: self::nullableEnv('TELEGRAM_CHAT_ID'),
            telegramApiBaseUrl: $telegramApiBaseUrl,
            telegramUploadApiBaseUrl: self::stringEnv('TELEGRAM_UPLOAD_API_BASE_URL', $telegramApiBaseUrl),
            telegramParticipantPresets: self::participantPresetsEnv('TELEGRAM_PARTICIPANT_PRESETS'),
            telegramUploadMaxBytes: self::intEnv('TELEGRAM_UPLOAD_MAX_BYTES', 49 * 1024 * 1024),
            pollIntervalSeconds: self::intEnv('POLL_INTERVAL_SECONDS', 30),
            fileMinAgeSeconds: self::intEnv('FILE_MIN_AGE_SECONDS', 60),
            stabilityWaitSeconds: self::intEnv('STABILITY_WAIT_SECONDS', 5),
            clipDurationSeconds: self::intEnv('CLIP_DURATION_SECONDS', 10),
            runOnce: self::boolEnv('RUN_ONCE', false),
            updatesTimeoutSeconds: self::intEnv('UPDATES_TIMEOUT_SECONDS', 2),
            openAiApiKey: self::nullableEnvAny(['AITUNNEL_API_KEY', 'OPENAI_API_KEY']),
            openAiBaseUrl: self::stringEnvAny(['AITUNNEL_BASE_URL', 'OPENAI_BASE_URL'], 'https://api.aitunnel.ru/v1/'),
            openAiTranscribeModel: self::stringEnvAny(['AITUNNEL_TRANSCRIBE_MODEL', 'OPENAI_TRANSCRIBE_MODEL'], 'whisper-1'),
            openAiSummaryModel: self::stringEnvAny(['AITUNNEL_SUMMARY_MODEL', 'OPENAI_SUMMARY_MODEL'], 'gpt-4o-mini'),
            openAiLanguage: self::nullableEnvAny(['AITUNNEL_TRANSCRIBE_LANGUAGE', 'OPENAI_TRANSCRIBE_LANGUAGE']),
            openAiAudioChunkSeconds: self::intEnv('OPENAI_AUDIO_CHUNK_SECONDS', 900),
            openAiSummaryChunkChars: self::intEnv('OPENAI_SUMMARY_CHUNK_CHARS', 30000),
            sendTranscriptFile: self::boolEnv('SEND_TRANSCRIPT_FILE', true),
            reminderBaseSeconds: self::intEnv('REMINDER_BASE_SECONDS', 300),
            reminderMaxSeconds: self::intEnv('REMINDER_MAX_SECONDS', 14400),
            reminderTimezone: self::stringEnv('REMINDER_TIMEZONE', 'Europe/Moscow'),
            reminderNightStartHour: self::hourEnv('REMINDER_NIGHT_START_HOUR', 23),
            reminderNightEndHour: self::hourEnv('REMINDER_NIGHT_END_HOUR', 9),
            platformApiBaseUrl: self::stringEnv('PLATFORM_API_BASE_URL', 'http://host.docker.internal:8083'),
            platformIdentityExchangeSecret: self::stringEnv('PLATFORM_IDENTITY_EXCHANGE_SECRET', 'dev-identity-exchange-secret'),
            platformIdentityExchangeIssuer: self::stringEnv('PLATFORM_IDENTITY_EXCHANGE_ISSUER', 'identity-service'),
            platformIdentityExchangeAudience: self::stringEnv('PLATFORM_IDENTITY_EXCHANGE_AUDIENCE', 'platform'),
            platformApiActorSubjectId: self::intEnv('PLATFORM_API_ACTOR_SUBJECT_ID', 1),
            platformApiTelegramUsername: self::stringEnv('PLATFORM_API_TELEGRAM_USERNAME', 'msavin_dev'),
            platformApiDisplayName: self::nullableEnv('PLATFORM_API_DISPLAY_NAME'),
            platformStudentsCacheSeconds: self::intEnv('PLATFORM_STUDENTS_CACHE_SECONDS', 300),
            platformDbHost: self::nullableEnv('PLATFORM_DB_HOST'),
            platformDbPort: self::intEnv('PLATFORM_DB_PORT', 5434),
            platformDbName: self::nullableEnv('PLATFORM_DB_NAME'),
            platformDbUser: self::nullableEnv('PLATFORM_DB_USER'),
            platformDbPassword: self::nullableEnv('PLATFORM_DB_PASSWORD'),
            platformSessionMatchWindowMinutes: self::intEnv('PLATFORM_SESSION_MATCH_WINDOW_MINUTES', 180),
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
            'PLATFORM_API_ACTOR_SUBJECT_ID',
            'PLATFORM_STUDENTS_CACHE_SECONDS',
            'PLATFORM_DB_PORT',
            'PLATFORM_SESSION_MATCH_WINDOW_MINUTES',
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

    /**
     * @param string[] $names
     */
    private static function nullableEnvAny(array $names): ?string
    {
        foreach ($names as $name) {
            $value = self::nullableEnv($name);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function stringEnv(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return trim((string) $value);
    }

    /**
     * @param string[] $names
     */
    private static function stringEnvAny(array $names, string $default): string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $default;
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

    /**
     * @return string[]
     */
    private static function participantPresetsEnv(string $name): array
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '') {
            return [];
        }

        $tokens = preg_split('/[\s,;]+/', (string) $value) ?: [];
        $result = [];
        foreach ($tokens as $token) {
            $username = trim((string) $token);
            if ($username === '') {
                continue;
            }

            if (!str_starts_with($username, '@')) {
                $username = '@' . $username;
            }

            if (preg_match('/^@[A-Za-z0-9_]{3,32}$/', $username) !== 1) {
                continue;
            }

            $result[] = $username;
        }

        return array_values(array_unique($result));
    }
}
