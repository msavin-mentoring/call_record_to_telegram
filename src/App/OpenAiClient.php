<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Throwable;

final class OpenAiClient
{
    private Client $http;
    private TextFormatter $textFormatter;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $transcribeModel,
        private readonly string $summaryModel,
        private readonly ?string $language,
        private readonly int $audioChunkSeconds,
        private readonly int $summaryChunkChars,
        ?Client $http = null,
        ?TextFormatter $textFormatter = null,
    ) {
        $headers = [];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $this->http = $http ?? new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 300,
            'connect_timeout' => 10,
            'http_errors' => true,
            'headers' => $headers,
        ]);
        $this->textFormatter = $textFormatter ?? new TextFormatter();
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
            Logger::info('OpenAI transcription skipped: no audio chunks produced.');
            return null;
        }

        $parts = [];
        foreach ($chunks as $index => $chunkPath) {
            $text = $this->transcribeChunk($chunkPath);
            @unlink($chunkPath);

            if ($text === null) {
                Logger::info('OpenAI transcription failed on chunk #' . ($index + 1));
                return null;
            }

            if (trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        $transcript = trim(implode("\n\n", $parts));
        if ($transcript === '') {
            Logger::info('OpenAI transcription returned empty text.');
            return null;
        }

        $summary = $this->summarizeTranscript($transcript);
        if ($summary === null || trim($summary) === '') {
            Logger::info('OpenAI summary failed.');
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

        $result = CommandRunner::run($command);
        if ($result['code'] !== 0) {
            Logger::info('ffmpeg audio extraction failed: ' . trim($result['stderr'] ?: $result['stdout']));
            return [];
        }

        $globPattern = $tempDir . DIRECTORY_SEPARATOR . 'audio_' . $hash . '_*.m4a';
        $files = glob($globPattern) ?: [];
        sort($files);

        return array_values(array_filter($files, static fn(string $path): bool => is_file($path) && ((int) filesize($path)) > 0));
    }

    private function transcribeChunk(string $chunkPath): ?string
    {
        $resource = fopen($chunkPath, 'rb');
        if ($resource === false) {
            Logger::info('OpenAI transcription: failed to open chunk file: ' . $chunkPath);
            return null;
        }

        $multipart = [
            ['name' => 'model', 'contents' => $this->transcribeModel],
            ['name' => 'response_format', 'contents' => 'json'],
            [
                'name' => 'file',
                'contents' => $resource,
                'filename' => basename($chunkPath),
                'headers' => ['Content-Type' => 'audio/mp4'],
            ],
        ];

        if ($this->language !== null && $this->language !== '') {
            $multipart[] = ['name' => 'language', 'contents' => $this->language];
        }

        try {
            $decoded = $this->requestJson(
                'POST',
                'audio/transcriptions',
                ['multipart' => $multipart, 'timeout' => 600],
                'OpenAI transcription'
            );
        } finally {
            fclose($resource);
        }

        if ($decoded === null) {
            return null;
        }

        $text = $decoded['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    private function summarizeTranscript(string $transcript): ?string
    {
        $chunks = $this->textFormatter->splitTextByMaxLength($transcript, max(5000, $this->summaryChunkChars));
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

        $decoded = $this->requestJson(
            'POST',
            'chat/completions',
            ['json' => $payload, 'timeout' => 300],
            'OpenAI summary'
        );

        if ($decoded === null) {
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? trim($content) : null;
    }

    private function requestJson(string $method, string $uri, array $options, string $context): ?array
    {
        try {
            $response = $this->http->request($method, $uri, $options);
            $decoded = json_decode((string) $response->getBody(), true);
            if (!is_array($decoded)) {
                Logger::info($context . ' returned non-JSON response.');
                return null;
            }

            if (isset($decoded['error'])) {
                $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'unknown error') : (string) $decoded['error'];
                Logger::info($context . ' error: ' . $message);
                return null;
            }

            return $decoded;
        } catch (Throwable $e) {
            $details = '';
            if ($e instanceof RequestException && $e->hasResponse()) {
                $details = trim((string) $e->getResponse()->getBody());
            }
            Logger::info($context . ' request failed: ' . $e->getMessage() . ($details !== '' ? ' | ' . $details : ''));
            return null;
        }
    }
}
