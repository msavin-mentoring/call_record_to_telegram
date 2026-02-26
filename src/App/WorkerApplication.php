<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class WorkerApplication
{
    public function run(): void
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

        $recordings = new RecordingFileService();
        $keyboards = new KeyboardFactory();
        $parser = new UserInputParser();
        $textFormatter = new TextFormatter();
        $reminders = new ReminderService();

        $conversation = new ConversationHandler($parser, $keyboards, $reminders);
        $workflow = new RecordingWorkflow($recordings, $keyboards, $reminders, $textFormatter);

        Logger::info('Worker started. Watching directory: ' . $config->recordingsDir);

        $nextScanAt = 0;
        while (true) {
            try {
                $conversation->processUpdates($config, $state, $telegram);
                $workflow->maybeFinalizePending($config, $state, $telegram, $openAi);
                $reminders->maybeSendPendingReminder($config, $state, $telegram);

                if (time() >= $nextScanAt) {
                    $workflow->maybeStartNextRecording($config, $state, $videoProcessor, $telegram);
                    $nextScanAt = time() + $config->pollIntervalSeconds;
                }
            } catch (Throwable $e) {
                Logger::info('Loop error: ' . $e->getMessage());
            }

            if ($config->runOnce) {
                Logger::info('RUN_ONCE=true, exiting after one iteration.');
                break;
            }
        }
    }
}
