<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\OpenAiClient;
use App\StateStore;
use App\TelegramClient;
use App\VideoProcessor;

const TAG_PRESETS = [
    'mock' => 'мок',
    'summary' => 'резюме',
    'tasks' => 'задачи',
    'review' => 'ревью',
];

function logMessage(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/**
 * @return array{code:int,stdout:string,stderr:string}
 */
function runCommand(string $command): array
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'Failed to start process'];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $code = proc_close($process);

    return [
        'code' => $code,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/**
 * @return string[]
 */
function findVideoFiles(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        if (strtolower((string) pathinfo($item->getFilename(), PATHINFO_EXTENSION)) === 'mp4') {
            $files[] = $item->getPathname();
        }
    }

    sort($files);
    return $files;
}

function relativePath(string $baseDir, string $filePath): string
{
    $base = rtrim(realpath($baseDir) ?: $baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $path = realpath($filePath) ?: $filePath;

    if (str_starts_with($path, $base)) {
        return substr($path, strlen($base));
    }

    return $path;
}

function isFileStable(string $filePath, int $minAgeSeconds, int $stabilityWaitSeconds): bool
{
    clearstatcache(true, $filePath);

    $firstMtime = filemtime($filePath);
    $firstSize = filesize($filePath);

    if ($firstMtime === false || $firstSize === false) {
        return false;
    }

    if ((time() - $firstMtime) < $minAgeSeconds) {
        return false;
    }

    if ($stabilityWaitSeconds > 0) {
        sleep($stabilityWaitSeconds);
        clearstatcache(true, $filePath);

        $secondMtime = filemtime($filePath);
        $secondSize = filesize($filePath);

        if ($secondMtime === false || $secondSize === false) {
            return false;
        }

        return $firstMtime === $secondMtime && $firstSize === $secondSize;
    }

    return true;
}

function clipTempPath(string $tempDir, string $relativePath): string
{
    $hash = substr(sha1($relativePath), 0, 12);
    $name = pathinfo($relativePath, PATHINFO_FILENAME);
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    if (!is_string($safeName) || $safeName === '') {
        $safeName = 'clip';
    }

    return $tempDir . DIRECTORY_SEPARATOR . $safeName . '_' . $hash . '.mp4';
}

function detectRecordingDate(string $relativeKey, string $filePath): string
{
    if (preg_match('/_(\d{4}-\d{2}-\d{2})-\d{2}-\d{2}-\d{2}\.mp4$/', basename($relativeKey), $matches) === 1) {
        return $matches[1];
    }

    $mtime = filemtime($filePath);
    if ($mtime !== false) {
        return date('Y-m-d', $mtime);
    }

    return date('Y-m-d');
}

function lowerCase(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value);
    }

    return strtolower($value);
}

/**
 * @return string[]
 */
function parseTagsFromText(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $tags = [];
    if (preg_match_all('/#([\p{L}\p{N}_-]+)/u', $text, $matches) > 0) {
        $tags = $matches[1];
    } else {
        $tags = preg_split('/[\s,;]+/u', $text) ?: [];
    }

    $normalized = [];
    foreach ($tags as $tag) {
        $clean = trim((string) $tag);
        if ($clean === '') {
            continue;
        }

        $lower = lowerCase(ltrim($clean, '#'));
        $mapped = match ($lower) {
            'mock', 'мок' => 'мок',
            'summary', 'резюме' => 'резюме',
            'tasks', 'задачи' => 'задачи',
            'review', 'ревью' => 'ревью',
            default => $lower,
        };

        $mapped = preg_replace('/[^\p{L}\p{N}_]+/u', '', $mapped);
        if (!is_string($mapped) || $mapped === '') {
            continue;
        }

        $normalized[] = $mapped;
    }

    return array_values(array_unique($normalized));
}

/**
 * @return string[]
 */
function parseParticipantsFromText(string $text): array
{
    $text = trim($text);
    $normalizedSkip = lowerCase($text);
    if ($normalizedSkip === '-' || $normalizedSkip === 'skip' || $normalizedSkip === 'пропустить') {
        return [];
    }

    $participants = [];
    if (preg_match_all('/@([A-Za-z0-9_]{3,32})/', $text, $matches) > 0) {
        foreach ($matches[1] as $username) {
            $participants[] = '@' . $username;
        }

        return array_values(array_unique($participants));
    }

    $tokens = preg_split('/[\s,;]+/', $text) ?: [];
    foreach ($tokens as $token) {
        $clean = ltrim(trim($token), '@');
        if ($clean === '') {
            continue;
        }

        if (preg_match('/^[A-Za-z0-9_]{3,32}$/', $clean) !== 1) {
            continue;
        }

        $participants[] = '@' . $clean;
    }

    return array_values(array_unique($participants));
}

/**
 * @return string[]
 */
function splitTextByMaxLength(string $text, int $maxLength): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $maxLength = max(1000, $maxLength);
    if (strlen($text) <= $maxLength) {
        return [$text];
    }

    $parts = [];
    $current = '';
    $paragraphs = preg_split("/\n{2,}/", $text) ?: [];

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }

        if ($current === '') {
            if (strlen($paragraph) <= $maxLength) {
                $current = $paragraph;
                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }

                if ($current === '') {
                    $current = $sentence;
                    continue;
                }

                if (strlen($current . ' ' . $sentence) <= $maxLength) {
                    $current .= ' ' . $sentence;
                } else {
                    $parts[] = $current;
                    $current = $sentence;
                }
            }

            continue;
        }

        if (strlen($current . "\n\n" . $paragraph) <= $maxLength) {
            $current .= "\n\n" . $paragraph;
            continue;
        }

        $parts[] = $current;
        if (strlen($paragraph) <= $maxLength) {
            $current = $paragraph;
            continue;
        }

        $current = '';
        $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            if ($current === '') {
                $current = $sentence;
                continue;
            }

            if (strlen($current . ' ' . $sentence) <= $maxLength) {
                $current .= ' ' . $sentence;
            } else {
                $parts[] = $current;
                $current = $sentence;
            }
        }
    }

    if ($current !== '') {
        $parts[] = $current;
    }

    return $parts;
}

function truncateForTelegram(string $text, int $maxChars = 3800): string
{
    $text = trim($text);
    if ($text === '') {
        return '—';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, max(100, $maxChars - 20)) . "\n...\n[обрезано]";
    }

    if (strlen($text) <= $maxChars) {
        return $text;
    }

    return substr($text, 0, max(100, $maxChars - 20)) . "\n...\n[obrezano]";
}

function writeTranscriptToTempFile(string $tempDir, string $key, string $transcript): ?string
{
    if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        return null;
    }

    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($key, PATHINFO_FILENAME));
    if (!is_string($safeBase) || $safeBase === '') {
        $safeBase = 'transcript';
    }

    $path = $tempDir . DIRECTORY_SEPARATOR . $safeBase . '_transcript.txt';
    $content = "Транскрипт для: {$key}\n\n" . trim($transcript) . "\n";
    if (file_put_contents($path, $content) === false) {
        return null;
    }

    return $path;
}

/**
 * @return array{inline_keyboard:array<int,array<int,array{ text:string, callback_data:string }>>}
 */
function buildTagsKeyboard(array $selectedTags): array
{
    $selected = array_fill_keys($selectedTags, true);

    $mk = TAG_PRESETS['mock'];
    $sm = TAG_PRESETS['summary'];
    $ts = TAG_PRESETS['tasks'];
    $rv = TAG_PRESETS['review'];

    return [
        'inline_keyboard' => [
            [
                ['text' => (isset($selected[$mk]) ? '✅ ' : '') . $mk, 'callback_data' => 'tag:mock'],
                ['text' => (isset($selected[$sm]) ? '✅ ' : '') . $sm, 'callback_data' => 'tag:summary'],
            ],
            [
                ['text' => (isset($selected[$ts]) ? '✅ ' : '') . $ts, 'callback_data' => 'tag:tasks'],
                ['text' => (isset($selected[$rv]) ? '✅ ' : '') . $rv, 'callback_data' => 'tag:review'],
            ],
            [
                ['text' => 'Готово', 'callback_data' => 'tag:done'],
            ],
        ],
    ];
}

/**
 * @return array{inline_keyboard:array<int,array<int,array{ text:string, callback_data:string }>>}
 */
function buildSummaryChoiceKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'Да', 'callback_data' => 'summary:yes'],
                ['text' => 'Нет', 'callback_data' => 'summary:no'],
            ],
        ],
    ];
}

function resolveChatIdFromUpdate(array $update): ?string
{
    $messageChat = $update['message']['chat']['id'] ?? null;
    if ($messageChat !== null) {
        return (string) $messageChat;
    }

    $callbackChat = $update['callback_query']['message']['chat']['id'] ?? null;
    if ($callbackChat !== null) {
        return (string) $callbackChat;
    }

    return null;
}

function processUpdates(Config $config, StateStore $state, TelegramClient $telegram): void
{
    $updates = $telegram->getUpdates($state->getLastUpdateId() + 1, $config->updatesTimeoutSeconds);
    if ($updates === []) {
        return;
    }

    foreach ($updates as $update) {
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($updateId > 0) {
            $state->setLastUpdateId($updateId);
        }

        $chatId = resolveChatIdFromUpdate($update);
        if ($chatId !== null) {
            if ($state->getChatId() === null) {
                $state->setChatId($chatId);
            }
            if ($telegram->getChatId() === null) {
                $telegram->rememberChatId($chatId);
            }
        }

        handleConversationUpdate($update, $state, $telegram, $config);
    }

    $state->save();
}

function handleConversationUpdate(
    array $update,
    StateStore $state,
    TelegramClient $telegram,
    Config $config
): void
{
    $openAiEnabled = $config->openAiApiKey !== null && trim($config->openAiApiKey) !== '';
    $pending = $state->getPending();
    if ($pending === null) {
        return;
    }

    $pendingChatId = (string) ($pending['chat_id'] ?? '');
    if ($pendingChatId === '') {
        return;
    }

    $chatId = resolveChatIdFromUpdate($update);
    if ($chatId !== $pendingChatId) {
        return;
    }

    $stage = (string) ($pending['stage'] ?? '');

    $callback = $update['callback_query'] ?? null;
    if (is_array($callback)) {
        $callbackId = (string) ($callback['id'] ?? '');
        $callbackData = (string) ($callback['data'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);

        if ($stage === 'await_tags') {
            $promptMessageId = (int) ($pending['prompt_message_id'] ?? 0);
            if ($messageId !== $promptMessageId) {
                if ($callbackId !== '') {
                    $telegram->answerCallbackQuery($callbackId, 'Эта кнопка уже неактуальна');
                }
                return;
            }

            if (str_starts_with($callbackData, 'tag:')) {
                handleTagCallback($callbackData, $callbackId, $pending, $state, $telegram, $config);
            }
        } elseif ($stage === 'await_summary_choice') {
            $summaryPromptMessageId = (int) ($pending['summary_prompt_message_id'] ?? 0);
            if ($messageId !== $summaryPromptMessageId) {
                if ($callbackId !== '') {
                    $telegram->answerCallbackQuery($callbackId, 'Эта кнопка уже неактуальна');
                }
                return;
            }

            if ($callbackData === 'summary:yes' || $callbackData === 'summary:no') {
                $choice = $callbackData === 'summary:yes';
                $pending['summary_requested'] = $choice;
                $pending['stage'] = 'ready_finalize';
                clearPendingReminder($pending);
                $state->setPending($pending);
                $state->save();

                if ($callbackId !== '') {
                    $telegram->answerCallbackQuery($callbackId, $choice ? 'Сделаем саммари' : 'Саммари пропускаем');
                }
            } elseif ($callbackId !== '') {
                $telegram->answerCallbackQuery($callbackId, 'Выберите Да или Нет');
            }
        } else {
            if ($callbackId !== '') {
                $telegram->answerCallbackQuery($callbackId, 'Отправьте участников текстом');
            }
        }

        return;
    }

    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        return;
    }

    $text = trim((string) ($message['text'] ?? ''));
    if ($text === '') {
        return;
    }

    if ($stage === 'await_tags') {
        $tags = parseTagsFromText($text);
        if ($tags === []) {
            resetPendingReminder($pending, $config);
            $state->setPending($pending);
            $state->save();
            $telegram->sendMessage(
                $pendingChatId,
                'Не удалось распознать теги. Отправьте, например: #мок #резюме или выберите кнопками.'
            );
            return;
        }

        $pending['tags'] = $tags;
        $pending['stage'] = 'await_participants';
        $pending['participants_set'] = false;
        $pending['summary_requested'] = null;
        unset($pending['summary_prompt_message_id']);
        resetPendingReminder($pending, $config);
        $state->setPending($pending);
        $state->save();

        $telegram->sendMessage(
            $pendingChatId,
            "Теги сохранены: " . implode(', ', array_map(static fn(string $tag): string => '#' . $tag, $tags)) .
            "\nТеперь пришлите участников (ники), например: @msavin_dev @asdfasdf\nЕсли не хотите указывать, отправьте: -"
        );

        return;
    }

    if ($stage === 'await_participants') {
        $participants = parseParticipantsFromText($text);

        if ($participants === [] && !in_array(lowerCase($text), ['-', 'skip', 'пропустить'], true)) {
            resetPendingReminder($pending, $config);
            $state->setPending($pending);
            $state->save();
            $telegram->sendMessage(
                $pendingChatId,
                'Не удалось распознать ники. Пришлите в формате: @user1 @user2 или отправьте "-" для пропуска.'
            );
            return;
        }

        $pending['participants'] = $participants;
        $pending['participants_set'] = true;
        unset($pending['next_retry_at'], $pending['retry_notice_sent']);

        if ($openAiEnabled) {
            $summaryPrompt = $telegram->sendMessage(
                $pendingChatId,
                "Нужно сделать саммари по созвону?",
                buildSummaryChoiceKeyboard()
            );
            $pending['stage'] = 'await_summary_choice';
            $pending['summary_requested'] = null;
            if ($summaryPrompt !== null) {
                $pending['summary_prompt_message_id'] = (int) ($summaryPrompt['message_id'] ?? 0);
                resetPendingReminder($pending, $config);
            } else {
                $pending['summary_requested'] = false;
                $pending['stage'] = 'ready_finalize';
                clearPendingReminder($pending);
            }
        } else {
            $pending['summary_requested'] = false;
            $pending['stage'] = 'ready_finalize';
            clearPendingReminder($pending);
        }

        $state->setPending($pending);
        $state->save();
        return;
    }

    if ($stage === 'await_summary_choice') {
        $choice = parseYesNoChoice($text);
        if ($choice === null) {
            resetPendingReminder($pending, $config);
            $state->setPending($pending);
            $state->save();
            $telegram->sendMessage(
                $pendingChatId,
                'Ответьте "да" или "нет" (или используйте кнопки).'
            );
            return;
        }

        $pending['summary_requested'] = $choice;
        $pending['stage'] = 'ready_finalize';
        clearPendingReminder($pending);
        $state->setPending($pending);
        $state->save();
    }
}

function parseYesNoChoice(string $text): ?bool
{
    $value = lowerCase(trim($text));
    return match ($value) {
        'да', 'yes', 'y', '+', 'ok', 'ага' => true,
        'нет', 'no', 'n', '-', 'не', 'nope' => false,
        default => null,
    };
}

function resetPendingReminder(array &$pending, Config $config): void
{
    $base = max(30, $config->reminderBaseSeconds);
    $pending['reminder_attempt'] = 0;
    $pending['next_reminder_at'] = time() + $base;
    unset($pending['last_reminder_at']);
}

function clearPendingReminder(array &$pending): void
{
    unset($pending['next_reminder_at'], $pending['reminder_attempt'], $pending['last_reminder_at']);
}

function reminderIntervalForAttempt(Config $config, int $attempt): int
{
    $base = max(30, $config->reminderBaseSeconds);
    $max = max($base, $config->reminderMaxSeconds);
    $attempt = max(0, $attempt);

    $interval = $base;
    for ($i = 0; $i < $attempt; $i++) {
        $interval = min($max, $interval * 2);
        if ($interval >= $max) {
            break;
        }
    }

    return $interval;
}

function isNightHour(int $hour, int $nightStartHour, int $nightEndHour): bool
{
    if ($nightStartHour === $nightEndHour) {
        return false;
    }

    if ($nightStartHour < $nightEndHour) {
        return $hour >= $nightStartHour && $hour < $nightEndHour;
    }

    return $hour >= $nightStartHour || $hour < $nightEndHour;
}

/**
 * @return array{now:int,night:bool,next_daytime_at:int}
 */
function moscowQuietHoursInfo(Config $config): array
{
    try {
        $tz = new DateTimeZone($config->reminderTimezone);
    } catch (Throwable) {
        $tz = new DateTimeZone('Europe/Moscow');
    }

    $nowDt = new DateTimeImmutable('now', $tz);
    $hour = (int) $nowDt->format('G');
    $isNight = isNightHour($hour, $config->reminderNightStartHour, $config->reminderNightEndHour);

    if (!$isNight) {
        return [
            'now' => $nowDt->getTimestamp(),
            'night' => false,
            'next_daytime_at' => $nowDt->getTimestamp(),
        ];
    }

    $target = $nowDt->setTime($config->reminderNightEndHour, 0, 0);
    if ($target <= $nowDt) {
        $target = $target->modify('+1 day');
    }

    return [
        'now' => $nowDt->getTimestamp(),
        'night' => true,
        'next_daytime_at' => $target->getTimestamp(),
    ];
}

function pendingNeedsUserReply(array $pending): bool
{
    $stage = (string) ($pending['stage'] ?? '');
    if ($stage === 'await_tags' || $stage === 'await_summary_choice') {
        return true;
    }

    if ($stage === 'await_participants') {
        return !(bool) ($pending['participants_set'] ?? false);
    }

    return false;
}

function buildReminderText(array $pending): string
{
    $stage = (string) ($pending['stage'] ?? '');
    return match ($stage) {
        'await_tags' => "Напоминание: пришлите теги для созвона (или нажмите кнопки в сообщении с клипом).",
        'await_participants' => "Напоминание: пришлите участников в формате @user1 @user2 (или '-' для пропуска).",
        'await_summary_choice' => "Напоминание: нужно ли саммари? Ответьте «да» или «нет» (или нажмите кнопку).",
        default => "Напоминание: ожидаю ваш ответ по текущему созвону.",
    };
}

function maybeSendPendingReminder(Config $config, StateStore $state, TelegramClient $telegram): void
{
    $pending = $state->getPending();
    if ($pending === null || !pendingNeedsUserReply($pending)) {
        return;
    }

    $chatId = (string) ($pending['chat_id'] ?? '');
    if ($chatId === '') {
        return;
    }

    if (!array_key_exists('next_reminder_at', $pending)) {
        resetPendingReminder($pending, $config);
        $state->setPending($pending);
        $state->save();
        return;
    }

    $nextReminderAt = (int) ($pending['next_reminder_at'] ?? 0);
    if (time() < $nextReminderAt) {
        return;
    }

    $quietInfo = moscowQuietHoursInfo($config);
    if ($quietInfo['night']) {
        $pending['next_reminder_at'] = $quietInfo['next_daytime_at'];
        $state->setPending($pending);
        $state->save();
        return;
    }

    $telegram->sendMessage($chatId, buildReminderText($pending));

    $attempt = (int) ($pending['reminder_attempt'] ?? 0) + 1;
    $pending['reminder_attempt'] = $attempt;
    $pending['last_reminder_at'] = time();
    $pending['next_reminder_at'] = time() + reminderIntervalForAttempt($config, $attempt);

    $state->setPending($pending);
    $state->save();
}

function handleTagCallback(
    string $callbackData,
    string $callbackId,
    array $pending,
    StateStore $state,
    TelegramClient $telegram,
    Config $config
): void {
    $chatId = (string) ($pending['chat_id'] ?? '');
    if ($chatId === '') {
        return;
    }

    $tags = is_array($pending['tags'] ?? null) ? array_values($pending['tags']) : [];

    if ($callbackData === 'tag:done') {
        if ($tags === []) {
            if ($callbackId !== '') {
                $telegram->answerCallbackQuery($callbackId, 'Выберите хотя бы один тег');
            }
            return;
        }

        $pending['stage'] = 'await_participants';
        $pending['participants_set'] = false;
        $pending['summary_requested'] = null;
        unset($pending['summary_prompt_message_id']);
        resetPendingReminder($pending, $config);
        $state->setPending($pending);
        $state->save();

        if ($callbackId !== '') {
            $telegram->answerCallbackQuery($callbackId, 'Теги сохранены');
        }

        $telegram->sendMessage(
            $chatId,
            "Теги: " . implode(', ', array_map(static fn(string $tag): string => '#' . $tag, $tags)) .
            "\nПришлите участников (ники), например: @msavin_dev @asdfasdf\nЕсли не хотите указывать, отправьте: -"
        );

        return;
    }

    $slug = substr($callbackData, 4);
    $mappedTag = TAG_PRESETS[$slug] ?? null;
    if ($mappedTag === null) {
        if ($callbackId !== '') {
            $telegram->answerCallbackQuery($callbackId, 'Неизвестный тег');
        }
        return;
    }

    $set = array_fill_keys($tags, true);
    if (isset($set[$mappedTag])) {
        unset($set[$mappedTag]);
        if ($callbackId !== '') {
            $telegram->answerCallbackQuery($callbackId, 'Удалено: #' . $mappedTag);
        }
    } else {
        $set[$mappedTag] = true;
        if ($callbackId !== '') {
            $telegram->answerCallbackQuery($callbackId, 'Добавлено: #' . $mappedTag);
        }
    }

    $newTags = array_keys($set);
    sort($newTags);
    $pending['tags'] = $newTags;
    resetPendingReminder($pending, $config);

    $state->setPending($pending);
    $state->save();

    $messageId = (int) ($pending['prompt_message_id'] ?? 0);
    if ($messageId > 0) {
        $telegram->editMessageReplyMarkup($chatId, $messageId, buildTagsKeyboard($newTags));
    }
}

function maybeStartNextRecording(
    Config $config,
    StateStore $state,
    VideoProcessor $videoProcessor,
    TelegramClient $telegram
): void {
    if ($state->getPending() !== null) {
        return;
    }

    $chatId = $telegram->getChatId() ?? $config->telegramChatId ?? $state->getChatId();
    if ($chatId === null || $chatId === '') {
        logMessage('Chat id not known yet. Send any message to bot or set TELEGRAM_CHAT_ID.');
        return;
    }

    if ($state->getChatId() === null) {
        $state->setChatId($chatId);
        $state->save();
    }

    $files = findVideoFiles($config->recordingsDir);
    foreach ($files as $file) {
        $key = relativePath($config->recordingsDir, $file);
        if ($state->isProcessed($key)) {
            continue;
        }

        logMessage('Found unprocessed video: ' . $key);

        if (!isFileStable($file, $config->fileMinAgeSeconds, $config->stabilityWaitSeconds)) {
            logMessage('File is still changing or too fresh, will retry later: ' . $key);
            continue;
        }

        $duration = $videoProcessor->getDuration($file);
        if ($duration === null) {
            logMessage('Failed to read duration with ffprobe: ' . $key);
            continue;
        }

        $clipPath = clipTempPath($config->tempDir, $key);
        if (!$videoProcessor->createMiddleClip($file, $clipPath, $duration)) {
            logMessage('Failed to build clip: ' . $key);
            continue;
        }

        $keyboard = buildTagsKeyboard([]);
        $caption = "Новый созвон: " . basename($file) .
            "\nВыберите теги кнопками или отправьте их вручную." .
            "\nПосле выбора нажмите «Готово».";

        $sent = $telegram->sendVideo($chatId, $clipPath, $caption, $keyboard);
        @unlink($clipPath);

        if ($sent === null) {
            logMessage('Failed to send preview clip to Telegram: ' . $key);
            continue;
        }

        $promptMessageId = (int) ($sent['message_id'] ?? 0);

        $pending = [
            'key' => $key,
            'chat_id' => $chatId,
            'stage' => 'await_tags',
            'tags' => [],
            'participants' => [],
            'participants_set' => false,
            'summary_requested' => null,
            'prompt_message_id' => $promptMessageId,
            'date' => detectRecordingDate($key, $file),
        ];
        resetPendingReminder($pending, $config);

        $state->setPending($pending);
        $state->save();

        logMessage('Waiting for tags from user for: ' . $key);
        return;
    }
}

function maybeFinalizePending(
    Config $config,
    StateStore $state,
    TelegramClient $telegram,
    OpenAiClient $openAi
): void
{
    $pending = $state->getPending();
    if ($pending === null) {
        return;
    }

    $stage = (string) ($pending['stage'] ?? '');
    if ($stage === 'await_participants' && (bool) ($pending['participants_set'] ?? false)) {
        $chatIdForPrompt = (string) ($pending['chat_id'] ?? '');
        if ($chatIdForPrompt === '') {
            return;
        }

        if ($openAi->isEnabled()) {
            $summaryPrompt = $telegram->sendMessage(
                $chatIdForPrompt,
                "Нужно сделать саммари по созвону?",
                buildSummaryChoiceKeyboard()
            );

            $pending['stage'] = 'await_summary_choice';
            $pending['summary_requested'] = null;
            if ($summaryPrompt !== null) {
                $pending['summary_prompt_message_id'] = (int) ($summaryPrompt['message_id'] ?? 0);
                resetPendingReminder($pending, $config);
            } else {
                $pending['summary_requested'] = false;
                $pending['stage'] = 'ready_finalize';
                clearPendingReminder($pending);
            }
            $state->setPending($pending);
            $state->save();
            return;
        }

        $pending['summary_requested'] = false;
        $pending['stage'] = 'ready_finalize';
        clearPendingReminder($pending);
        $state->setPending($pending);
        $state->save();
        $stage = 'ready_finalize';
    }

    if ($stage !== 'ready_finalize') {
        return;
    }

    if (!array_key_exists('participants', $pending) || !is_array($pending['participants'])) {
        return;
    }

    if (!(bool) ($pending['participants_set'] ?? false)) {
        return;
    }

    if (!array_key_exists('tags', $pending) || !is_array($pending['tags']) || $pending['tags'] === []) {
        return;
    }

    $nextRetryAt = (int) ($pending['next_retry_at'] ?? 0);
    if ($nextRetryAt > time()) {
        return;
    }

    $chatId = (string) ($pending['chat_id'] ?? '');
    $key = (string) ($pending['key'] ?? '');
    if ($chatId === '' || $key === '') {
        return;
    }

    $filePath = $config->recordingsDir . DIRECTORY_SEPARATOR . $key;
    if (!is_file($filePath)) {
        logMessage('Original file missing for pending item: ' . $key);
        $telegram->sendMessage($chatId, 'Не нашел исходный файл: ' . $key);
        $state->clearPending();
        $state->save();
        return;
    }

    $tags = array_values(array_unique(array_map('strval', $pending['tags'])));
    $participants = array_values(array_unique(array_map('strval', $pending['participants'])));
    $date = (string) ($pending['date'] ?? detectRecordingDate($key, $filePath));

    $caption = buildFinalCaption($tags, $participants, $date);

    $sent = $telegram->sendVideo($chatId, $filePath, $caption);
    if ($sent === null) {
        $sent = $telegram->sendDocument($chatId, $filePath, $caption, 'video/mp4');
    }

    if ($sent === null) {
        logMessage('Failed to send full recording, waiting for retry message from user.');
        if (!(bool) ($pending['retry_notice_sent'] ?? false)) {
            $telegram->sendMessage($chatId, 'Не удалось отправить полный файл. Повторю автоматически через минуту.');
            $pending['retry_notice_sent'] = true;
        }
        $pending['next_retry_at'] = time() + 60;
        $state->setPending($pending);
        $state->save();
        return;
    }

    $size = filesize($filePath);
    $mtime = filemtime($filePath);

    $transcript = null;
    $summary = null;
    $transcriptionStatus = 'disabled';
    $summaryRequested = (bool) ($pending['summary_requested'] ?? false);

    if ($openAi->isEnabled() && $summaryRequested) {
        logMessage('Starting OpenAI transcription and summary for: ' . $key);
        $transcriptionStatus = 'failed';
        $openAiResult = $openAi->transcribeAndSummarize($filePath, $config->tempDir);

        if ($openAiResult !== null) {
            $transcriptionStatus = 'ok';
            $transcript = $openAiResult['transcript'];
            $summary = $openAiResult['summary'];

            $summaryMessage = "саммари:\n" . truncateForTelegram($summary, 3800);
            $telegram->sendMessage($chatId, $summaryMessage);

            if ($config->sendTranscriptFile && $transcript !== null && trim($transcript) !== '') {
                $transcriptFile = writeTranscriptToTempFile($config->tempDir, $key, $transcript);
                if ($transcriptFile !== null) {
                    $telegram->sendDocument(
                        $chatId,
                        $transcriptFile,
                        'Транскрипт: ' . basename($filePath),
                        'text/plain'
                    );
                    @unlink($transcriptFile);
                }
            }
        } else {
            $telegram->sendMessage($chatId, 'Не удалось получить транскрипт/саммари через OpenAI.');
        }
    } elseif ($openAi->isEnabled()) {
        $transcriptionStatus = 'skipped_by_user';
    }

    $transcriptPreview = $transcript === null ? null : truncateForTelegram($transcript, 2000);
    $transcriptChars = $transcript === null ? 0 : strlen($transcript);

    $state->markProcessed($key, [
        'processed_at' => date(DATE_ATOM),
        'size' => $size === false ? null : (int) $size,
        'mtime' => $mtime === false ? null : (int) $mtime,
        'tags' => $tags,
        'participants' => $participants,
        'date' => $date,
        'summary_requested' => $summaryRequested,
        'transcription_status' => $transcriptionStatus,
        'transcript_preview' => $transcriptPreview,
        'transcript_chars' => $transcriptChars,
        'summary' => $summary,
    ]);

    $state->clearPending();
    $state->save();

    $telegram->sendMessage($chatId, 'Сохранено и отправлено: ' . basename($filePath));
    logMessage('Processed and sent full recording: ' . $key);
}

function buildFinalCaption(array $tags, array $participants, string $date): string
{
    $tagText = $tags === []
        ? '—'
        : implode(', ', array_map(static fn(string $tag): string => '#' . $tag, $tags));

    $participantsText = $participants === []
        ? '—'
        : implode(', ', $participants);

    return "теги: {$tagText}\nучастники: {$participantsText}\nдата: {$date}";
}

function run(): void
{
    $config = Config::fromEnv();

    if (!is_dir($config->recordingsDir)) {
        throw new RuntimeException('RECORDINGS_DIR does not exist: ' . $config->recordingsDir);
    }

    if (!is_dir($config->tempDir) && !mkdir($config->tempDir, 0777, true) && !is_dir($config->tempDir)) {
        throw new RuntimeException('Failed to create temp directory: ' . $config->tempDir);
    }

    $state = new StateStore($config->stateFile);

    $initialChatId = $config->telegramChatId;
    if ($initialChatId === null) {
        $initialChatId = $state->getChatId();
    }

    $telegram = new TelegramClient($config->telegramToken, $initialChatId);
    $videoProcessor = new VideoProcessor($config->clipDurationSeconds);
    $openAi = new OpenAiClient(
        $config->openAiApiKey,
        $config->openAiTranscribeModel,
        $config->openAiSummaryModel,
        $config->openAiLanguage,
        $config->openAiAudioChunkSeconds,
        $config->openAiSummaryChunkChars
    );

    logMessage('Worker started. Watching directory: ' . $config->recordingsDir);

    $nextScanAt = 0;
    while (true) {
        try {
            processUpdates($config, $state, $telegram);
            maybeFinalizePending($config, $state, $telegram, $openAi);
            maybeSendPendingReminder($config, $state, $telegram);

            if (time() >= $nextScanAt) {
                maybeStartNextRecording($config, $state, $videoProcessor, $telegram);
                $nextScanAt = time() + $config->pollIntervalSeconds;
            }
        } catch (Throwable $e) {
            logMessage('Loop error: ' . $e->getMessage());
        }

        if ($config->runOnce) {
            logMessage('RUN_ONCE=true, exiting after one iteration.');
            break;
        }
    }
}

try {
    run();
} catch (Throwable $e) {
    logMessage('Fatal error: ' . $e->getMessage());
    exit(1);
}
