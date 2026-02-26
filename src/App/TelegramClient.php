<?php

declare(strict_types=1);

namespace App;

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
            \logMessage('Detected TELEGRAM_CHAT_ID=' . $chatId . ' from updates.');
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
        $result = \runCommand('curl -sS --max-time 40 ' . escapeshellarg($url));
        if ($result['code'] !== 0) {
            \logMessage('Telegram getUpdates failed: ' . trim($result['stderr'] ?: $result['stdout']));
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
    ): ?array {
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
            \logMessage("Telegram {$method}: file does not exist: {$filePath}");
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
        $result = \runCommand($command);
        if ($result['code'] !== 0) {
            \logMessage("Telegram {$method} failed: " . trim($result['stderr'] ?: $result['stdout']));
            return null;
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? 'unknown error') : 'invalid JSON response';
            \logMessage("Telegram {$method} returned error: {$description}");
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
