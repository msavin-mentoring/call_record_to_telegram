<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Throwable;

final class TelegramClient
{
    private Client $http;

    public function __construct(
        private readonly string $token,
        private ?string $chatId,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://api.telegram.org/bot' . $this->token . '/',
            'timeout' => 60,
            'connect_timeout' => 10,
            'http_errors' => true,
        ]);
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
        $result = $this->callApi('GET', 'getUpdates', [
            'query' => [
                'offset' => $offset,
                'timeout' => max(0, $timeout),
                'allowed_updates' => json_encode(['message', 'callback_query'], JSON_UNESCAPED_SLASHES),
            ],
            'timeout' => 40,
        ]);

        return $result === null ? [] : $result;
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

        return $this->postForm('sendMessage', $fields);
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

        $this->postForm('answerCallbackQuery', $fields);
    }

    public function editMessageReplyMarkup(string $chatId, int $messageId, array $replyMarkup): bool
    {
        $fields = [
            'chat_id' => $chatId,
            'message_id' => (string) $messageId,
            'reply_markup' => json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $result = $this->postForm('editMessageReplyMarkup', $fields);
        return $result !== null;
    }

    private function postForm(string $method, array $fields): ?array
    {
        return $this->callApi('POST', $method, ['form_params' => $fields]);
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

        $fileResource = fopen($filePath, 'rb');
        if ($fileResource === false) {
            \logMessage("Telegram {$method}: failed to open file: {$filePath}");
            return null;
        }

        $multipart = [];
        foreach ($fields as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => (string) $value,
            ];
        }

        $multipart[] = [
            'name' => $fileField,
            'contents' => $fileResource,
            'filename' => basename($filePath),
            'headers' => [
                'Content-Type' => $mimeType,
            ],
        ];

        try {
            return $this->callApi('POST', $method, [
                'multipart' => $multipart,
                'timeout' => 600,
            ]);
        } finally {
            fclose($fileResource);
        }
    }

    private function callApi(string $httpMethod, string $method, array $options): ?array
    {
        try {
            $response = $this->http->request($httpMethod, $method, $options);
            $decoded = json_decode((string) $response->getBody(), true);
            if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
                $description = is_array($decoded) ? (string) ($decoded['description'] ?? 'unknown error') : 'invalid JSON response';
                \logMessage("Telegram {$method} returned error: {$description}");
                return null;
            }

            $result = $decoded['result'] ?? null;
            return is_array($result) ? $result : [];
        } catch (Throwable $e) {
            $details = '';
            if ($e instanceof RequestException && $e->hasResponse()) {
                $details = trim((string) $e->getResponse()->getBody());
            }
            \logMessage("Telegram {$method} failed: " . $e->getMessage() . ($details !== '' ? ' | ' . $details : ''));
            return null;
        }
    }
}
