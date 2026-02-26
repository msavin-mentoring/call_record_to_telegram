<?php

declare(strict_types=1);

namespace App;

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
            \logMessage('OpenAI transcription skipped: no audio chunks produced.');
            return null;
        }

        $parts = [];
        foreach ($chunks as $index => $chunkPath) {
            $text = $this->transcribeChunk($chunkPath);
            @unlink($chunkPath);

            if ($text === null) {
                \logMessage('OpenAI transcription failed on chunk #' . ($index + 1));
                return null;
            }

            if (trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        $transcript = trim(implode("\n\n", $parts));
        if ($transcript === '') {
            \logMessage('OpenAI transcription returned empty text.');
            return null;
        }

        $summary = $this->summarizeTranscript($transcript);
        if ($summary === null || trim($summary) === '') {
            \logMessage('OpenAI summary failed.');
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

        $result = \runCommand($command);
        if ($result['code'] !== 0) {
            \logMessage('ffmpeg audio extraction failed: ' . trim($result['stderr'] ?: $result['stdout']));
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

        $result = \runCommand($command);
        if ($result['code'] !== 0) {
            \logMessage('OpenAI transcription request failed: ' . trim($result['stderr'] ?: $result['stdout']));
            return null;
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            \logMessage('OpenAI transcription returned non-JSON response.');
            return null;
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'unknown error') : (string) $decoded['error'];
            \logMessage('OpenAI transcription error: ' . $message);
            return null;
        }

        $text = $decoded['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    private function summarizeTranscript(string $transcript): ?string
    {
        $chunks = \splitTextByMaxLength($transcript, max(5000, $this->summaryChunkChars));
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

        $result = \runCommand($command);
        @unlink($payloadFile);

        if ($result['code'] !== 0) {
            \logMessage('OpenAI summary request failed: ' . trim($result['stderr'] ?: $result['stdout']));
            return null;
        }

        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            \logMessage('OpenAI summary returned non-JSON response.');
            return null;
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'unknown error') : (string) $decoded['error'];
            \logMessage('OpenAI summary error: ' . $message);
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? trim($content) : null;
    }
}
