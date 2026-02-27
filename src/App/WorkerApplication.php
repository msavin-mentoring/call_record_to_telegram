<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class WorkerApplication
{
    /** @var resource|null */
    private $instanceLock = null;

    public function run(): void
    {
        $config = Config::fromEnv();
        $this->acquireInstanceLock($config->stateFile);

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

        $telegram = new TelegramClient($config->telegramToken, $initialChatId, $config->telegramApiBaseUrl);
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
                $workflow->maybeFinalizePending($config, $state, $telegram, $openAi, $videoProcessor);
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

    private function acquireInstanceLock(string $stateFile): void
    {
        $lockDir = dirname($stateFile);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Failed to create lock directory: ' . $lockDir);
        }

        $lockPath = $lockDir . DIRECTORY_SEPARATOR . 'worker.lock';
        $handle = fopen($lockPath, 'cb+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open lock file: ' . $lockPath);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another worker instance is already running.');
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $this->instanceLock = $handle;
    }
}
