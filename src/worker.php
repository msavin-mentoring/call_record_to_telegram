<?php

declare(strict_types=1);

const TAG_PRESETS = [
    'mock' => 'мок',
    'summary' => 'резюме',
    'tasks' => 'задачи',
    'review' => 'ревью',
];

final class Config
{
    public function __construct(
        public readonly string $recordingsDir,
        public readonly string $stateFile,
        public readonly string $tempDir,
        public readonly string $telegramToken,
        public readonly ?string $telegramChatId,
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
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            recordingsDir: rtrim((string) getenv('RECORDINGS_DIR') ?: '/recordings', '/'),
            stateFile: (string) getenv('STATE_FILE') ?: '/app/data/state.json',
            tempDir: rtrim((string) getenv('TEMP_DIR') ?: '/tmp/call_clips', '/'),
            telegramToken: self::requiredEnv('TELEGRAM_BOT_TOKEN'),
            telegramChatId: self::nullableEnv('TELEGRAM_CHAT_ID'),
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
        );
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
}

final class StateStore
{
    private array $data = [
        'processed' => [],
        'pending' => null,
        'last_update_id' => 0,
        'chat_id' => null,
    ];

    public function __construct(private readonly string $stateFile)
    {
        $this->load();
    }

    public function isProcessed(string $key): bool
    {
        return isset($this->data['processed'][$key]);
    }

    public function markProcessed(string $key, array $payload): void
    {
        $this->data['processed'][$key] = $payload;
    }

    public function getPending(): ?array
    {
        $pending = $this->data['pending'] ?? null;
        return is_array($pending) ? $pending : null;
    }

    public function setPending(array $pending): void
    {
        $this->data['pending'] = $pending;
    }

    public function clearPending(): void
    {
        $this->data['pending'] = null;
    }

    public function getLastUpdateId(): int
    {
        return (int) ($this->data['last_update_id'] ?? 0);
    }

    public function setLastUpdateId(int $id): void
    {
        $this->data['last_update_id'] = max($id, $this->getLastUpdateId());
    }

    public function getChatId(): ?string
    {
        $chatId = $this->data['chat_id'] ?? null;
        return $chatId === null ? null : (string) $chatId;
    }

    public function setChatId(string $chatId): void
    {
        $this->data['chat_id'] = $chatId;
    }

    public function save(): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create state directory {$directory}");
        }

        $tmp = $this->stateFile . '.tmp';
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode state JSON.');
        }

        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary state file {$tmp}");
        }

        if (!rename($tmp, $this->stateFile)) {
            throw new RuntimeException("Failed to replace state file {$this->stateFile}");
        }
    }

    private function load(): void
    {
        if (!is_file($this->stateFile)) {
            return;
        }

        $raw = file_get_contents($this->stateFile);
        if ($raw === false || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            logMessage('State file is invalid JSON, starting with empty state.');
            return;
        }

        $this->data['processed'] = is_array($decoded['processed'] ?? null) ? $decoded['processed'] : [];
        $this->data['pending'] = is_array($decoded['pending'] ?? null) ? $decoded['pending'] : null;
        $this->data['last_update_id'] = (int) ($decoded['last_update_id'] ?? 0);
        $chatId = $decoded['chat_id'] ?? null;
        $this->data['chat_id'] = $chatId === null ? null : (string) $chatId;
    }
}

final class TelegramClient
{
    public function __construct(
        private readonly string $token,
        private ?string $chatId,
    ) {
    }

    public function getChatId(): ?string
    {
        return $this->chatId;
    }

    public function rememberChatId(string $chatId): void
    {
        if ($this->chatId === null) {
            $this->chatId = $chatId;
            logMessage('Detected TELEGRAM_CHAT_ID=' . $chatId . ' from updates.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout): array
    {
        $query = http_build_query([
            'offset' => $offset,
            'timeout' => max(0, $timeout),
            'allowed_updates' => json_encode(['message', 'callback_query'], JSON_UNESCAPED_SLASHES),
        ]);

        $url = $this->apiUrl('getUpdates') . '?' . $query;
        $result = runCommand('curl -sS --max-time 40 ' . escapeshellarg($url));
        if ($result['code'] !== 0) {
            logMessage('Telegram getUpdates failed: ' . trim($result['stderr'] ?: $result['stdout']));
            return [];
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            return [];
        }

        $updates = $decoded['result'] ?? [];
        return is_array($updates) ? $updates : [];
    }

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): ?array
    {
        $fields = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ];
        if ($replyMarkup !== null) {
            $fields['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->postUrlencoded('sendMessage', $fields);
    }

    public function sendVideo(string $chatId, string $filePath, string $caption, ?array $replyMarkup = null): ?array
    {
        $fields = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'supports_streaming' => 'true',
        ];
        if ($replyMarkup !== null) {
            $fields['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->postMultipart('sendVideo', $fields, 'video', $filePath, 'video/mp4');
    }

    public function sendDocument(
        string $chatId,
        string $filePath,
        string $caption,
        string $mimeType = 'application/octet-stream'
    ): ?array
    {
        $fields = [
            'chat_id' => $chatId,
            'caption' => $caption,
        ];

        return $this->postMultipart('sendDocument', $fields, 'document', $filePath, $mimeType);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        $fields = ['callback_query_id' => $callbackQueryId];
        if ($text !== '') {
            $fields['text'] = $text;
            $fields['show_alert'] = 'false';
        }

        $this->postUrlencoded('answerCallbackQuery', $fields);
    }

    public function editMessageReplyMarkup(string $chatId, int $messageId, array $replyMarkup): bool
    {
        $fields = [
            'chat_id' => $chatId,
            'message_id' => (string) $messageId,
            'reply_markup' => json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $result = $this->postUrlencoded('editMessageReplyMarkup', $fields);
        return $result !== null;
    }

    private function postUrlencoded(string $method, array $fields): ?array
    {
        $command = 'curl -sS --max-time 60 -X POST ' . escapeshellarg($this->apiUrl($method));
        foreach ($fields as $key => $value) {
            $command .= ' --data-urlencode ' . escapeshellarg($key . '=' . (string) $value);
        }

        return $this->executeJson($command, $method);
    }

    private function postMultipart(
        string $method,
        array $fields,
        string $fileField,
        string $filePath,
        string $mimeType
    ): ?array {
        if (!is_file($filePath)) {
            logMessage("Telegram {$method}: file does not exist: {$filePath}");
            return null;
        }

        $command = 'curl -sS --max-time 600 -X POST ' . escapeshellarg($this->apiUrl($method));
        foreach ($fields as $key => $value) {
            $command .= ' -F ' . escapeshellarg($key . '=' . (string) $value);
        }
        $command .= ' -F ' . escapeshellarg($fileField . '=@' . $filePath . ';type=' . $mimeType);

        return $this->executeJson($command, $method);
    }

    private function executeJson(string $command, string $method): ?array
    {
        $result = runCommand($command);
        if ($result['code'] !== 0) {
            logMessage("Telegram {$method} failed: " . trim($result['stderr'] ?: $result['stdout']));
            return null;
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? 'unknown error') : 'invalid JSON response';
            logMessage("Telegram {$method} returned error: {$description}");
            return null;
        }

        $resultData = $decoded['result'] ?? null;
        return is_array($resultData) ? $resultData : null;
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token . '/' . $method;
    }
}

final class VideoProcessor
{
    public function __construct(private readonly int $clipDurationSeconds)
    {
    }

    public function getDuration(string $filePath): ?float
    {
        $command = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($filePath);

        $result = runCommand($command);
        if ($result['code'] !== 0) {
            return null;
        }

        $duration = trim($result['stdout']);
        if ($duration === '' || !is_numeric($duration)) {
            return null;
        }

        return (float) $duration;
    }

    public function createMiddleClip(string $sourceFile, string $outputFile, float $duration): bool
    {
        $clipLength = min((float) $this->clipDurationSeconds, $duration);
        if ($clipLength <= 0.0) {
            return false;
        }

        $start = max(0.0, ($duration - $clipLength) / 2.0);
        $command = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -ss %.3f -i %s -t %.3f -c:v libx264 -preset veryfast -crf 27 -c:a aac -movflags +faststart %s',
            $start,
            escapeshellarg($sourceFile),
            $clipLength,
            escapeshellarg($outputFile)
        );

        $result = runCommand($command);
        if ($result['code'] !== 0) {
            logMessage('ffmpeg failed for ' . $sourceFile . ': ' . trim($result['stderr'] ?: $result['stdout']));
            return false;
        }

        return is_file($outputFile) && ((int) filesize($outputFile)) > 0;
    }
}

final class OpenAiClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $transcribeModel,
        private readonly string $summaryModel,
        private readonly ?string $language,
        private readonly int $audioChunkSeconds,
        private readonly int $summaryChunkChars,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * @return array{transcript:string,summary:string,chunks:int}|null
     */
    public function transcribeAndSummarize(string $videoFilePath, string $tempDir): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $chunks = $this->extractAudioChunks($videoFilePath, $tempDir);
        if ($chunks === []) {
            logMessage('OpenAI transcription skipped: no audio chunks produced.');
            return null;
        }

        $parts = [];
        foreach ($chunks as $index => $chunkPath) {
            $text = $this->transcribeChunk($chunkPath);
            @unlink($chunkPath);

            if ($text === null) {
                logMessage('OpenAI transcription failed on chunk #' . ($index + 1));
                return null;
            }

            if (trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        $transcript = trim(implode("\n\n", $parts));
        if ($transcript === '') {
            logMessage('OpenAI transcription returned empty text.');
            return null;
        }

        $summary = $this->summarizeTranscript($transcript);
        if ($summary === null || trim($summary) === '') {
            logMessage('OpenAI summary failed.');
            return null;
        }

        return [
            'transcript' => $transcript,
            'summary' => trim($summary),
            'chunks' => count($chunks),
        ];
    }

    /**
     * @return string[]
     */
    private function extractAudioChunks(string $videoFilePath, string $tempDir): array
    {
        if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            return [];
        }

        $hash = substr(sha1($videoFilePath), 0, 12);
        $pattern = $tempDir . DIRECTORY_SEPARATOR . 'audio_' . $hash . '_%03d.m4a';

        $command = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -i %s -vn -ac 1 -ar 16000 -c:a aac -b:a 48k -f segment -segment_time %d -reset_timestamps 1 %s',
            escapeshellarg($videoFilePath),
            max(60, $this->audioChunkSeconds),
            escapeshellarg($pattern)
        );

        $result = runCommand($command);
        if ($result['code'] !== 0) {
            logMessage('ffmpeg audio extraction failed: ' . trim($result['stderr'] ?: $result['stdout']));
            return [];
        }

        $globPattern = $tempDir . DIRECTORY_SEPARATOR . 'audio_' . $hash . '_*.m4a';
        $files = glob($globPattern) ?: [];
        sort($files);

        return array_values(array_filter($files, static fn(string $path): bool => is_file($path) && ((int) filesize($path)) > 0));
    }

    private function transcribeChunk(string $chunkPath): ?string
    {
        $command = 'curl -sS --max-time 600 -X POST ' . escapeshellarg('https://api.openai.com/v1/audio/transcriptions')
            . ' -H ' . escapeshellarg('Authorization: Bearer ' . $this->apiKey)
            . ' -F ' . escapeshellarg('model=' . $this->transcribeModel)
            . ' -F ' . escapeshellarg('response_format=json');

        if ($this->language !== null && $this->language !== '') {
            $command .= ' -F ' . escapeshellarg('language=' . $this->language);
        }

        $command .= ' -F ' . escapeshellarg('file=@' . $chunkPath . ';type=audio/mp4');

        $result = runCommand($command);
        if ($result['code'] !== 0) {
            logMessage('OpenAI transcription request failed: ' . trim($result['stderr'] ?: $result['stdout']));
            return null;
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            logMessage('OpenAI transcription returned non-JSON response.');
            return null;
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'unknown error') : (string) $decoded['error'];
            logMessage('OpenAI transcription error: ' . $message);
            return null;
        }

        $text = $decoded['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    private function summarizeTranscript(string $transcript): ?string
    {
        $chunks = splitTextByMaxLength($transcript, max(5000, $this->summaryChunkChars));
        if ($chunks === []) {
            return null;
        }

        if (count($chunks) === 1) {
            return $this->summarizeSingleText(
                $chunks[0],
                "Сделай короткое саммари созвона на русском.\n" .
                "Формат:\n" .
                "1) Краткое резюме (2-4 пункта)\n" .
                "2) Ключевые решения\n" .
                "3) Задачи/экшены (если есть)\n" .
                "4) Риски/блокеры (если есть)\n" .
                "Пиши только по фактам из транскрипта."
            );
        }

        $partialSummaries = [];
        foreach ($chunks as $index => $chunk) {
            $partial = $this->summarizeSingleText(
                $chunk,
                "Ниже часть транскрипта созвона (часть " . ($index + 1) . " из " . count($chunks) . ").\n" .
                "Сделай краткую выжимку: факты, решения, задачи, риски."
            );

            if ($partial !== null && trim($partial) !== '') {
                $partialSummaries[] = trim($partial);
            }
        }

        if ($partialSummaries === []) {
            return null;
        }

        return $this->summarizeSingleText(
            implode("\n\n---\n\n", $partialSummaries),
            "Объедини частичные выжимки созвона в одно итоговое саммари на русском.\n" .
            "Формат:\n" .
            "1) Краткое резюме (2-4 пункта)\n" .
            "2) Ключевые решения\n" .
            "3) Задачи/экшены\n" .
            "4) Риски/блокеры"
        );
    }

    private function summarizeSingleText(string $text, string $instruction): ?string
    {
        $payload = [
            'model' => $this->summaryModel,
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Ты помощник для пост-обработки созвонов. Пиши структурированно и кратко.',
                ],
                [
                    'role' => 'user',
                    'content' => $instruction . "\n\nТранскрипт:\n" . $text,
                ],
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return null;
        }

        $payloadFile = tempnam(sys_get_temp_dir(), 'openai_payload_');
        if ($payloadFile === false) {
            return null;
        }

        if (file_put_contents($payloadFile, $payloadJson) === false) {
            @unlink($payloadFile);
            return null;
        }

        $command = 'curl -sS --max-time 300 -X POST ' . escapeshellarg('https://api.openai.com/v1/chat/completions')
            . ' -H ' . escapeshellarg('Authorization: Bearer ' . $this->apiKey)
            . ' -H ' . escapeshellarg('Content-Type: application/json')
            . ' --data-binary @' . escapeshellarg($payloadFile);

        $result = runCommand($command);
        @unlink($payloadFile);

        if ($result['code'] !== 0) {
            logMessage('OpenAI summary request failed: ' . trim($result['stderr'] ?: $result['stdout']));
            return null;
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            logMessage('OpenAI summary returned non-JSON response.');
            return null;
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'unknown error') : (string) $decoded['error'];
            logMessage('OpenAI summary error: ' . $message);
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? trim($content) : null;
    }
}

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

        handleConversationUpdate($update, $state, $telegram);
    }

    $state->save();
}

function handleConversationUpdate(array $update, StateStore $state, TelegramClient $telegram): void
{
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
                handleTagCallback($callbackData, $callbackId, $pending, $state, $telegram);
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
            $telegram->sendMessage(
                $pendingChatId,
                'Не удалось распознать теги. Отправьте, например: #мок #резюме или выберите кнопками.'
            );
            return;
        }

        $pending['tags'] = $tags;
        $pending['stage'] = 'await_participants';
        $pending['participants_set'] = false;
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
            $telegram->sendMessage(
                $pendingChatId,
                'Не удалось распознать ники. Пришлите в формате: @user1 @user2 или отправьте "-" для пропуска.'
            );
            return;
        }

        $pending['participants'] = $participants;
        $pending['participants_set'] = true;
        unset($pending['next_retry_at'], $pending['retry_notice_sent']);
        $state->setPending($pending);
        $state->save();
    }
}

function handleTagCallback(
    string $callbackData,
    string $callbackId,
    array $pending,
    StateStore $state,
    TelegramClient $telegram
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
            'prompt_message_id' => $promptMessageId,
            'date' => detectRecordingDate($key, $file),
        ];

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

    if (($pending['stage'] ?? '') !== 'await_participants') {
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

    if ($openAi->isEnabled()) {
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
