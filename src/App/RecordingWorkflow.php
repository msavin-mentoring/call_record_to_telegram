<?php

declare(strict_types=1);

namespace App;

final class RecordingWorkflow
{
    public function __construct(
        private readonly RecordingFileService $files,
        private readonly KeyboardFactory $keyboards,
        private readonly ReminderService $reminders,
        private readonly TextFormatter $textFormatter,
    ) {
    }

    public function maybeStartNextRecording(
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
            Logger::info('Chat id not known yet. Send any message to bot or set TELEGRAM_CHAT_ID.');
            return;
        }

        if ($state->getChatId() === null) {
            $state->setChatId($chatId);
            $state->save();
        }

        $fileList = $this->files->findVideoFiles($config->recordingsDir);
        foreach ($fileList as $filePath) {
            $key = $this->files->relativePath($config->recordingsDir, $filePath);
            if ($state->isProcessed($key)) {
                continue;
            }

            Logger::info('Found unprocessed video: ' . $key);

            if (!$this->files->isFileStable($filePath, $config->fileMinAgeSeconds, $config->stabilityWaitSeconds)) {
                Logger::info('File is still changing or too fresh, will retry later: ' . $key);
                continue;
            }

            $duration = $videoProcessor->getDuration($filePath);
            if ($duration === null) {
                Logger::info('Failed to read duration with ffprobe: ' . $key);
                continue;
            }

            $recordedAt = $this->files->detectRecordingDateTime($key, $filePath);
            $durationText = $this->formatDuration($duration);

            $clipPath = $this->files->clipTempPath($config->tempDir, $key);
            if (!$videoProcessor->createMiddleClip($filePath, $clipPath, $duration)) {
                Logger::info('Failed to build clip: ' . $key);
                continue;
            }

            $caption = "Новый созвон\n" .
                "дата: {$recordedAt['date']}\n" .
                "время: {$recordedAt['time']}\n" .
                "длительность: {$durationText}\n" .
                "\nВыберите теги кнопками или отправьте их вручную." .
                "\nПосле выбора нажмите «Готово».";

            $sent = $telegram->sendVideo(
                $chatId,
                $clipPath,
                $caption,
                $this->keyboards->buildTagsKeyboard([])
            );
            @unlink($clipPath);

            if ($sent === null) {
                Logger::info('Failed to send preview clip to Telegram: ' . $key);
                continue;
            }

            $pending = [
                'key' => $key,
                'chat_id' => $chatId,
                'stage' => 'await_tags',
                'tags' => [],
                'participants' => [],
                'participants_set' => false,
                'summary_requested' => null,
                'prompt_message_id' => (int) ($sent['message_id'] ?? 0),
                'date' => $recordedAt['date'],
            ];
            $this->reminders->resetPendingReminder($pending, $config);

            $state->setPending($pending);
            $state->save();

            Logger::info('Waiting for tags from user for: ' . $key);
            return;
        }
    }

    public function maybeFinalizePending(
        Config $config,
        StateStore $state,
        TelegramClient $telegram,
        OpenAiClient $openAi,
        VideoProcessor $videoProcessor
    ): void {
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
                    'Нужно сделать саммари по созвону?',
                    $this->keyboards->buildSummaryChoiceKeyboard()
                );

                $pending['stage'] = 'await_summary_choice';
                $pending['summary_requested'] = null;
                if ($summaryPrompt !== null) {
                    $pending['summary_prompt_message_id'] = (int) ($summaryPrompt['message_id'] ?? 0);
                    $this->reminders->resetPendingReminder($pending, $config);
                } else {
                    $pending['summary_requested'] = false;
                    $pending['stage'] = 'ready_finalize';
                    $this->reminders->clearPendingReminder($pending);
                }
                $state->setPending($pending);
                $state->save();
                return;
            }

            $pending['summary_requested'] = false;
            $pending['stage'] = 'ready_finalize';
            $this->reminders->clearPendingReminder($pending);
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
            Logger::info('Original file missing for pending item: ' . $key);
            $telegram->sendMessage($chatId, 'Не нашел исходный файл: ' . $key);
            $state->clearPending();
            $state->save();
            return;
        }

        $tags = array_values(array_unique(array_map('strval', $pending['tags'])));
        $participants = array_values(array_unique(array_map('strval', $pending['participants'])));
        $date = (string) ($pending['date'] ?? $this->files->detectRecordingDate($key, $filePath));
        $size = filesize($filePath);
        $mtime = filemtime($filePath);

        $caption = $this->textFormatter->buildFinalCaption($tags, $participants, $date);

        $sent = $telegram->sendVideo($chatId, $filePath, $caption);
        if ($sent === null) {
            $sent = $telegram->sendDocument($chatId, $filePath, $caption, 'video/mp4');
        }

        $sentByParts = false;
        if ($sent === null) {
            $telegramErrorCode = $telegram->getLastErrorCode();
            if ($telegramErrorCode === 413) {
                $sizeText = $this->formatFileSize($size === false ? 0 : (int) $size);
                $telegram->sendMessage(
                    $chatId,
                    "Полный файл слишком большой для отправки одним сообщением ({$sizeText}). " .
                    "Пробую отправить частями."
                );
                $sentByParts = $this->sendRecordingInParts(
                    $videoProcessor,
                    $config,
                    $telegram,
                    $chatId,
                    $filePath,
                    $caption,
                    $key
                );
                if ($sentByParts) {
                    Logger::info('Full recording sent in parts after Telegram 413: ' . $key);
                } else {
                    Logger::info('Failed to send recording in parts after Telegram 413: ' . $key);
                }
            }
        }

        if ($sent === null && !$sentByParts) {
            Logger::info('Failed to send full recording, waiting for retry message from user.');
            if (!(bool) ($pending['retry_notice_sent'] ?? false)) {
                $telegram->sendMessage($chatId, 'Не удалось отправить полный файл. Повторю автоматически через минуту.');
                $pending['retry_notice_sent'] = true;
            }
            $pending['next_retry_at'] = time() + 60;
            $state->setPending($pending);
            $state->save();
            return;
        }

        $transcript = null;
        $summary = null;
        $transcriptionStatus = 'disabled';
        $summaryRequested = (bool) ($pending['summary_requested'] ?? false);

        if ($openAi->isEnabled() && $summaryRequested) {
            Logger::info('Starting OpenAI transcription and summary for: ' . $key);
            $transcriptionStatus = 'failed';
            $openAiResult = $openAi->transcribeAndSummarize($filePath, $config->tempDir);

            if ($openAiResult !== null) {
                $transcriptionStatus = 'ok';
                $transcript = $openAiResult['transcript'];
                $summary = $openAiResult['summary'];

                $summaryMessage = "саммари:\n" . $this->textFormatter->truncateForTelegram($summary, 3800);
                $telegram->sendMessage($chatId, $summaryMessage);

                if ($config->sendTranscriptFile && $transcript !== null && trim($transcript) !== '') {
                    $transcriptFile = $this->files->writeTranscriptToTempFile($config->tempDir, $key, $transcript);
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

        $transcriptPreview = $transcript === null ? null : $this->textFormatter->truncateForTelegram($transcript, 2000);
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
        Logger::info('Processed and sent full recording: ' . $key);
    }

    private function formatDuration(float $durationSeconds): string
    {
        $seconds = max(0, (int) round($durationSeconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    private function formatFileSize(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%.2f GB', $bytes / (1024 * 1024 * 1024));
    }

    private function sendRecordingInParts(
        VideoProcessor $videoProcessor,
        Config $config,
        TelegramClient $telegram,
        string $chatId,
        string $filePath,
        string $caption,
        string $key
    ): bool {
        $size = filesize($filePath);
        $duration = $videoProcessor->getDuration($filePath);
        if ($size === false || $duration === null || $duration <= 0.0) {
            return false;
        }

        $targetBytes = max(5 * 1024 * 1024, $config->telegramUploadMaxBytes);
        $segmentSeconds = (int) floor($duration * ($targetBytes / max(1, (int) $size)) * 0.98);
        $segmentSeconds = max(90, min((int) ceil($duration), $segmentSeconds));

        $notified = false;
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $prefix = substr(sha1($key), 0, 12) . '_full_part_' . $attempt;
            $parts = $videoProcessor->splitIntoSegments($filePath, $config->tempDir, $prefix, $segmentSeconds);
            if ($parts === []) {
                return false;
            }

            $maxPartSize = 0;
            foreach ($parts as $partPath) {
                $partSize = filesize($partPath);
                if ($partSize !== false) {
                    $maxPartSize = max($maxPartSize, (int) $partSize);
                }
            }

            // If segment estimation overshot too much, shrink before upload attempts.
            if ($maxPartSize > (int) ($targetBytes * 1.08)) {
                $this->cleanupTempFiles($parts);
                $segmentSeconds = max(60, (int) floor($segmentSeconds * 0.85));
                continue;
            }

            if (!$notified) {
                $telegram->sendMessage(
                    $chatId,
                    'Отправляю запись частями: ' . count($parts) . ' шт.'
                );
                $notified = true;
            }

            $allSent = true;
            $lastErrorCode = null;
            $total = count($parts);
            foreach ($parts as $index => $partPath) {
                $partCaption = $caption . "\nчасть " . ($index + 1) . '/' . $total;
                $sent = $telegram->sendDocument($chatId, $partPath, $partCaption, 'video/mp4');
                if ($sent === null) {
                    $allSent = false;
                    $lastErrorCode = $telegram->getLastErrorCode();
                    break;
                }
            }

            $this->cleanupTempFiles($parts);
            if ($allSent) {
                return true;
            }

            if ($lastErrorCode === 413) {
                $segmentSeconds = max(60, (int) floor($segmentSeconds * 0.85));
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * @param string[] $paths
     */
    private function cleanupTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
