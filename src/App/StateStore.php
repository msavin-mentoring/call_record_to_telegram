<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

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
            \logMessage('State file is invalid JSON, starting with empty state.');
            return;
        }

        $this->data['processed'] = is_array($decoded['processed'] ?? null) ? $decoded['processed'] : [];
        $this->data['pending'] = is_array($decoded['pending'] ?? null) ? $decoded['pending'] : null;
        $this->data['last_update_id'] = (int) ($decoded['last_update_id'] ?? 0);
        $chatId = $decoded['chat_id'] ?? null;
        $this->data['chat_id'] = $chatId === null ? null : (string) $chatId;
    }
}
