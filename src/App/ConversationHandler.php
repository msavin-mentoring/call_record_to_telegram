<?php

declare(strict_types=1);

namespace App;

final class ConversationHandler
{
    private const TOGGLE_DEBOUNCE_MS = 1200;

    public function __construct(
        private readonly UserInputParser $parser,
        private readonly KeyboardFactory $keyboards,
        private readonly ReminderService $reminders,
    ) {
    }

    public function processUpdates(Config $config, StateStore $state, TelegramClient $telegram): void
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

            $chatId = $this->resolveChatIdFromUpdate($update);
            if ($chatId !== null) {
                if ($state->getChatId() === null) {
                    $state->setChatId($chatId);
                }
                if ($telegram->getChatId() === null) {
                    $telegram->rememberChatId($chatId);
                }
            }

            $this->handleConversationUpdate($update, $state, $telegram, $config);
        }

        $state->save();
    }

    private function resolveChatIdFromUpdate(array $update): ?string
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

    private function handleConversationUpdate(
        array $update,
        StateStore $state,
        TelegramClient $telegram,
        Config $config
    ): void {
        $openAiEnabled = $config->openAiApiKey !== null && trim($config->openAiApiKey) !== '';
        $pending = $state->getPending();
        if ($pending === null) {
            return;
        }

        $pendingChatId = (string) ($pending['chat_id'] ?? '');
        if ($pendingChatId === '') {
            return;
        }

        $chatId = $this->resolveChatIdFromUpdate($update);
        if ($chatId !== $pendingChatId) {
            return;
        }

        $stage = (string) ($pending['stage'] ?? '');

        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $updateId = (int) ($update['update_id'] ?? 0);
            $callbackId = (string) ($callback['id'] ?? '');
            $callbackData = (string) ($callback['data'] ?? '');
            $messageId = (int) ($callback['message']['message_id'] ?? 0);
            Logger::info('DBG callback received', [
                'update_id' => $updateId,
                'stage' => $stage,
                'callback_data' => $callbackData,
                'callback_message_id' => $messageId,
                'prompt_message_id' => (int) ($pending['prompt_message_id'] ?? 0),
                'participants_prompt_message_id' => (int) ($pending['participants_prompt_message_id'] ?? 0),
                'summary_prompt_message_id' => (int) ($pending['summary_prompt_message_id'] ?? 0),
            ]);
            if ($callbackId !== '') {
                // Ack callback immediately to avoid Telegram client spinner/timeouts.
                $telegram->answerCallbackQuery($callbackId);
            }

            if ($stage === 'await_tags') {
                $promptMessageId = (int) ($pending['prompt_message_id'] ?? 0);
                if ($messageId !== $promptMessageId) {
                    Logger::info('DBG callback ignored: tags prompt message mismatch', [
                        'callback_message_id' => $messageId,
                        'expected_message_id' => $promptMessageId,
                        'callback_data' => $callbackData,
                    ]);
                    return;
                }

                if (str_starts_with($callbackData, 'tag:')) {
                    $this->handleTagCallback($callbackData, $pending, $state, $telegram, $config);
                }
            } elseif ($stage === 'await_participants') {
                $participantsPromptMessageId = (int) ($pending['participants_prompt_message_id'] ?? 0);
                if ($participantsPromptMessageId > 0 && $messageId !== $participantsPromptMessageId) {
                    Logger::info('DBG callback ignored: participants prompt message mismatch', [
                        'callback_message_id' => $messageId,
                        'expected_message_id' => $participantsPromptMessageId,
                        'callback_data' => $callbackData,
                    ]);
                    return;
                }

                if (str_starts_with($callbackData, 'participant:')) {
                    $this->handleParticipantCallback(
                        $callbackData,
                        $pending,
                        $state,
                        $telegram,
                        $config,
                        $openAiEnabled
                    );
                }
            } elseif ($stage === 'await_summary_choice') {
                $summaryPromptMessageId = (int) ($pending['summary_prompt_message_id'] ?? 0);
                if ($messageId !== $summaryPromptMessageId) {
                    Logger::info('DBG callback ignored: summary prompt message mismatch', [
                        'callback_message_id' => $messageId,
                        'expected_message_id' => $summaryPromptMessageId,
                        'callback_data' => $callbackData,
                    ]);
                    return;
                }

                if ($callbackData === 'summary:back') {
                    $tags = is_array($pending['tags'] ?? null) ? array_values($pending['tags']) : [];
                    $this->moveToParticipantsStep(
                        $pending,
                        $tags,
                        $state,
                        $telegram,
                        $config,
                        $pendingChatId,
                        'Вернулись к шагу выбора участников.'
                    );
                    return;
                }

                if ($callbackData === 'summary:restart') {
                    $this->moveToTagsStep(
                        $pending,
                        $state,
                        $telegram,
                        $config,
                        $pendingChatId,
                        true,
                        'Начинаем сначала.'
                    );
                    return;
                }

                if ($callbackData === 'summary:yes' || $callbackData === 'summary:no') {
                    $choice = $callbackData === 'summary:yes';
                    $pending['summary_requested'] = $choice;
                    $pending['stage'] = 'ready_finalize';
                    $this->reminders->clearPendingReminder($pending);
                    $state->setPending($pending);
                    $state->save();
                    Logger::info('DBG summary choice saved', [
                        'choice' => $choice ? 'yes' : 'no',
                        'stage' => 'ready_finalize',
                    ]);
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

        $incomingMessageId = (int) ($message['message_id'] ?? 0);
        $minMessageId = (int) ($pending['prompt_message_id'] ?? 0);
        if ($stage === 'await_participants') {
            $minMessageId = (int) ($pending['participants_prompt_message_id'] ?? $minMessageId);
        } elseif ($stage === 'await_summary_choice') {
            $minMessageId = (int) ($pending['summary_prompt_message_id'] ?? $minMessageId);
        }

        if ($incomingMessageId > 0 && $minMessageId > 0 && $incomingMessageId <= $minMessageId) {
            return;
        }

        if ($stage === 'await_tags') {
            if ($this->parser->isRestartInput($text)) {
                $this->moveToTagsStep(
                    $pending,
                    $state,
                    $telegram,
                    $config,
                    $pendingChatId,
                    true,
                    'Начинаем сначала.'
                );
                return;
            }

            $tags = $this->parser->parseTagsFromText($text);
            $skipTags = $this->parser->isTagsSkipInput($text);
            if ($tags === [] && !$skipTags) {
                $this->reminders->resetPendingReminder($pending, $config);
                $state->setPending($pending);
                $state->save();
                $telegram->sendMessage(
                    $pendingChatId,
                    'Не удалось распознать теги. Отправьте, например: #мок #резюме, выберите кнопками или отправьте "-" для пропуска.'
                );
                return;
            }

            if ($skipTags) {
                $tags = [];
            }

            $this->moveToParticipantsStep(
                $pending,
                $tags,
                $state,
                $telegram,
                $config,
                $pendingChatId,
                'Теги сохранены: ' . $this->formatTagsForMessage($tags)
            );

            return;
        }

        if ($stage === 'await_participants') {
            if ($this->parser->isBackInput($text)) {
                $this->moveToTagsStep(
                    $pending,
                    $state,
                    $telegram,
                    $config,
                    $pendingChatId,
                    false,
                    'Вернулись к шагу выбора тегов.'
                );
                return;
            }

            if ($this->parser->isRestartInput($text)) {
                $this->moveToTagsStep(
                    $pending,
                    $state,
                    $telegram,
                    $config,
                    $pendingChatId,
                    true,
                    'Начинаем сначала.'
                );
                return;
            }

            $participants = $this->parser->parseParticipantsFromText($text);

            if ($participants === [] && !$this->parser->isParticipantsSkipInput($text)) {
                $this->reminders->resetPendingReminder($pending, $config);
                $state->setPending($pending);
                $state->save();
                $telegram->sendMessage(
                    $pendingChatId,
                    'Не удалось распознать ники. Пришлите в формате: @user1 @user2, выберите кнопками или отправьте "-" для пропуска.'
                );
                return;
            }

            $pending['participants'] = $participants;
            $pending['participants_set'] = true;
            unset($pending['next_retry_at'], $pending['retry_notice_sent']);
            $this->finalizeParticipantsStep($pending, $pendingChatId, $state, $telegram, $config, $openAiEnabled);
            return;
        }

        if ($stage === 'await_summary_choice') {
            if ($this->parser->isBackInput($text)) {
                $tags = is_array($pending['tags'] ?? null) ? array_values($pending['tags']) : [];
                $this->moveToParticipantsStep(
                    $pending,
                    $tags,
                    $state,
                    $telegram,
                    $config,
                    $pendingChatId,
                    'Вернулись к шагу выбора участников.'
                );
                return;
            }

            if ($this->parser->isRestartInput($text)) {
                $this->moveToTagsStep(
                    $pending,
                    $state,
                    $telegram,
                    $config,
                    $pendingChatId,
                    true,
                    'Начинаем сначала.'
                );
                return;
            }

            $choice = $this->parser->parseYesNoChoice($text);
            if ($choice === null) {
                $this->reminders->resetPendingReminder($pending, $config);
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
            $this->reminders->clearPendingReminder($pending);
            $state->setPending($pending);
            $state->save();
        }
    }

    private function handleTagCallback(
        string $callbackData,
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
        Logger::info('DBG tag callback start', [
            'callback_data' => $callbackData,
            'current_tags' => $tags,
        ]);

        if ($callbackData === 'tag:restart') {
            $this->moveToTagsStep(
                $pending,
                $state,
                $telegram,
                $config,
                $chatId,
                true,
                'Начинаем сначала.'
            );
            return;
        }

        if ($callbackData === 'tag:done' || $callbackData === 'tag:skip') {
            if ($callbackData === 'tag:skip') {
                $tags = [];
            }

            $this->moveToParticipantsStep(
                $pending,
                $tags,
                $state,
                $telegram,
                $config,
                $chatId,
                'Теги: ' . $this->formatTagsForMessage($tags)
            );

            return;
        }

        $slug = substr($callbackData, 4);
        $mappedTag = $this->keyboards->mapTagSlug($slug);
        if ($mappedTag === null) {
            Logger::info('DBG tag callback ignored: unknown slug', ['slug' => $slug]);
            return;
        }
        $toggleAction = 'tag:' . $mappedTag;
        if ($this->isRapidDuplicateToggle($pending, $toggleAction)) {
            Logger::info('DBG tag callback ignored: duplicate debounce', [
                'action' => $toggleAction,
            ]);
            return;
        }

        $set = array_fill_keys($tags, true);
        if (isset($set[$mappedTag])) {
            unset($set[$mappedTag]);
        } else {
            $set[$mappedTag] = true;
        }

        $newTags = array_keys($set);
        sort($newTags);
        $pending['tags'] = $newTags;
        $this->rememberToggleAction($pending, $toggleAction);
        $this->reminders->resetPendingReminder($pending, $config);
        Logger::info('DBG tags selection updated', [
            'toggle_action' => $toggleAction,
            'new_tags' => $newTags,
        ]);

        $messageId = (int) ($pending['prompt_message_id'] ?? 0);
        $retryAt = (int) ($pending['tags_markup_retry_at'] ?? 0);
        if ($messageId <= 0) {
            Logger::info('DBG tags markup edit skipped: empty prompt message id');
        }
        if ($messageId > 0 && time() < $retryAt) {
            Logger::info('DBG tags markup edit postponed by retry cooldown', [
                'retry_at' => $retryAt,
                'retry_in_seconds' => max(0, $retryAt - time()),
            ]);
        }
        if ($messageId > 0 && time() >= $retryAt) {
            $updated = $telegram->editMessageReplyMarkup(
                $chatId,
                $messageId,
                $this->keyboards->buildTagsKeyboard($newTags)
            );
            if ($updated) {
                unset($pending['tags_markup_retry_at']);
                Logger::info('DBG tags markup edited successfully', [
                    'message_id' => $messageId,
                    'selected_tags' => $newTags,
                ]);
            } elseif ($telegram->getLastErrorCode() === 429) {
                $retryAfter = max(1, $telegram->getLastRetryAfterSeconds() ?? 1);
                $pending['tags_markup_retry_at'] = time() + $retryAfter;
                Logger::warning(
                    'Rate limited while updating tags keyboard, deferring markup refresh.',
                    ['retry_after' => $retryAfter]
                );
            } else {
                Logger::warning('DBG tags markup edit failed', [
                    'error_code' => $telegram->getLastErrorCode(),
                    'error_description' => $telegram->getLastErrorDescription(),
                ]);
            }
        }

        $state->setPending($pending);
        $state->save();
    }

    private function handleParticipantCallback(
        string $callbackData,
        array $pending,
        StateStore $state,
        TelegramClient $telegram,
        Config $config,
        bool $openAiEnabled
    ): void {
        $chatId = (string) ($pending['chat_id'] ?? '');
        if ($chatId === '') {
            return;
        }
        Logger::info('DBG participant callback start', [
            'callback_data' => $callbackData,
            'current_stage' => (string) ($pending['stage'] ?? ''),
            'current_participants' => is_array($pending['participants'] ?? null) ? array_values(array_unique($pending['participants'])) : [],
        ]);

        if ($callbackData === 'participant:back') {
            $this->moveToTagsStep(
                $pending,
                $state,
                $telegram,
                $config,
                $chatId,
                false,
                'Вернулись к шагу выбора тегов.'
            );
            return;
        }

        if ($callbackData === 'participant:restart') {
            $this->moveToTagsStep(
                $pending,
                $state,
                $telegram,
                $config,
                $chatId,
                true,
                'Начинаем сначала.'
            );
            return;
        }

        $participants = is_array($pending['participants'] ?? null) ? array_values(array_unique($pending['participants'])) : [];

        if ($callbackData === 'participant:skip') {
            $pending['participants'] = [];
            $pending['participants_set'] = true;
            unset($pending['next_retry_at'], $pending['retry_notice_sent']);
            $this->finalizeParticipantsStep($pending, $chatId, $state, $telegram, $config, $openAiEnabled);
            return;
        }

        if ($callbackData === 'participant:done') {
            $pending['participants'] = $participants;
            $pending['participants_set'] = true;
            unset($pending['next_retry_at'], $pending['retry_notice_sent']);
            $this->finalizeParticipantsStep($pending, $chatId, $state, $telegram, $config, $openAiEnabled);
            return;
        }

        if (!str_starts_with($callbackData, 'participant:toggle:')) {
            return;
        }

        $username = trim(substr($callbackData, strlen('participant:toggle:')));
        if ($username === '' || preg_match('/^@[A-Za-z0-9_]{3,32}$/', $username) !== 1) {
            Logger::info('DBG participant callback ignored: invalid username in payload', [
                'username' => $username,
                'callback_data' => $callbackData,
            ]);
            return;
        }
        $toggleAction = 'participant:' . $username;
        if ($this->isRapidDuplicateToggle($pending, $toggleAction)) {
            Logger::info('DBG participant callback ignored: duplicate debounce', [
                'action' => $toggleAction,
            ]);
            return;
        }

        $set = array_fill_keys($participants, true);
        if (isset($set[$username])) {
            unset($set[$username]);
        } else {
            $set[$username] = true;
        }

        $participants = array_keys($set);
        sort($participants);
        $pending['participants'] = $participants;
        $pending['participants_set'] = false;
        $this->rememberToggleAction($pending, $toggleAction);
        $this->reminders->resetPendingReminder($pending, $config);
        Logger::info('DBG participants selection updated', [
            'toggle_action' => $toggleAction,
            'new_participants' => $participants,
        ]);

        $messageId = (int) ($pending['participants_prompt_message_id'] ?? 0);
        $retryAt = (int) ($pending['participants_markup_retry_at'] ?? 0);
        if ($messageId <= 0) {
            Logger::info('DBG participants markup edit skipped: empty participants prompt message id');
        }
        if ($messageId > 0 && time() < $retryAt) {
            Logger::info('DBG participants markup edit postponed by retry cooldown', [
                'retry_at' => $retryAt,
                'retry_in_seconds' => max(0, $retryAt - time()),
            ]);
        }
        if ($messageId > 0 && time() >= $retryAt) {
            $updated = $telegram->editMessageReplyMarkup(
                $chatId,
                $messageId,
                $this->keyboards->buildParticipantsKeyboard($config->telegramParticipantPresets, $participants)
            );
            if ($updated) {
                unset($pending['participants_markup_retry_at']);
                Logger::info('DBG participants markup edited successfully', [
                    'message_id' => $messageId,
                    'selected_participants' => $participants,
                ]);
            } elseif ($telegram->getLastErrorCode() === 429) {
                $retryAfter = max(1, $telegram->getLastRetryAfterSeconds() ?? 1);
                $pending['participants_markup_retry_at'] = time() + $retryAfter;
                Logger::warning(
                    'Rate limited while updating participants keyboard, deferring markup refresh.',
                    ['retry_after' => $retryAfter]
                );
            } else {
                Logger::warning('DBG participants markup edit failed', [
                    'error_code' => $telegram->getLastErrorCode(),
                    'error_description' => $telegram->getLastErrorDescription(),
                ]);
            }
        }

        $state->setPending($pending);
        $state->save();
    }

    private function sendTagsPrompt(
        array &$pending,
        StateStore $state,
        TelegramClient $telegram,
        string $chatId,
        string $text
    ): void {
        $selected = is_array($pending['tags'] ?? null) ? array_values(array_unique($pending['tags'])) : [];
        $tagsPrompt = $telegram->sendMessage($chatId, $text, $this->keyboards->buildTagsKeyboard($selected));
        if ($tagsPrompt !== null) {
            $pending['prompt_message_id'] = (int) ($tagsPrompt['message_id'] ?? 0);
            $state->setPending($pending);
            $state->save();
            Logger::info('DBG tags prompt sent', [
                'message_id' => (int) ($pending['prompt_message_id'] ?? 0),
                'selected_tags' => $selected,
            ]);
        } else {
            Logger::warning('DBG tags prompt send failed');
        }
    }

    private function sendParticipantsPrompt(
        array &$pending,
        StateStore $state,
        TelegramClient $telegram,
        Config $config,
        string $chatId,
        string $text
    ): void {
        $selected = is_array($pending['participants'] ?? null) ? array_values(array_unique($pending['participants'])) : [];
        $replyMarkup = $config->telegramParticipantPresets === []
            ? null
            : $this->keyboards->buildParticipantsKeyboard($config->telegramParticipantPresets, $selected);

        $participantsPrompt = $telegram->sendMessage($chatId, $text, $replyMarkup);
        if ($participantsPrompt !== null) {
            $pending['participants_prompt_message_id'] = (int) ($participantsPrompt['message_id'] ?? 0);
            $state->setPending($pending);
            $state->save();
            Logger::info('DBG participants prompt sent', [
                'message_id' => (int) ($pending['participants_prompt_message_id'] ?? 0),
                'selected_participants' => $selected,
            ]);
        } else {
            Logger::warning('DBG participants prompt send failed');
        }
    }

    private function buildTagsPromptText(string $header): string
    {
        return $header .
            "\nВыберите теги кнопками ниже или отправьте их вручную." .
            "\nПосле выбора нажмите «Готово»." .
            "\nЕсли теги не нужны, нажмите «Без тега» или отправьте «-».";
    }

    private function finalizeParticipantsStep(
        array &$pending,
        string $chatId,
        StateStore $state,
        TelegramClient $telegram,
        Config $config,
        bool $openAiEnabled
    ): void {
        unset(
            $pending['participants_markup_retry_at'],
            $pending['last_toggle_action'],
            $pending['last_toggle_at_ms']
        );

        if ($openAiEnabled) {
            $summaryPrompt = $telegram->sendMessage(
                $chatId,
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
        } else {
            $pending['summary_requested'] = false;
            $pending['stage'] = 'ready_finalize';
            $this->reminders->clearPendingReminder($pending);
        }

        $state->setPending($pending);
        $state->save();
    }

    private function buildParticipantsPromptText(Config $config, string $header): string
    {
        if ($config->telegramParticipantPresets !== []) {
            return $header .
                "\nВыберите участников кнопками ниже или пришлите вручную (например: @msavin_dev @asdfasdf)." .
                "\nЕсли не хотите указывать, отправьте: -";
        }

        return $header .
            "\nПришлите участников (ники), например: @msavin_dev @asdfasdf" .
            "\nЕсли не хотите указывать, отправьте: -";
    }

    private function moveToParticipantsStep(
        array &$pending,
        array $tags,
        StateStore $state,
        TelegramClient $telegram,
        Config $config,
        string $chatId,
        string $header
    ): void {
        $pending['tags'] = array_values(array_unique(array_map('strval', $tags)));
        $pending['stage'] = 'await_participants';
        $pending['participants_set'] = false;
        $pending['summary_requested'] = null;
        unset(
            $pending['summary_prompt_message_id'],
            $pending['tags_markup_retry_at'],
            $pending['participants_markup_retry_at'],
            $pending['last_toggle_action'],
            $pending['last_toggle_at_ms']
        );
        $this->reminders->resetPendingReminder($pending, $config);
        $state->setPending($pending);
        $state->save();
        Logger::info('DBG transition to participants step', [
            'tags' => $pending['tags'],
            'header' => $header,
        ]);

        $this->sendParticipantsPrompt(
            $pending,
            $state,
            $telegram,
            $config,
            $chatId,
            $this->buildParticipantsPromptText($config, $header)
        );
    }

    private function moveToTagsStep(
        array &$pending,
        StateStore $state,
        TelegramClient $telegram,
        Config $config,
        string $chatId,
        bool $resetSelection,
        string $header
    ): void {
        if ($resetSelection) {
            $pending['tags'] = [];
            $pending['participants'] = [];
        } else {
            $pending['tags'] = array_values(array_unique(array_map('strval', is_array($pending['tags'] ?? null) ? $pending['tags'] : [])));
            $pending['participants'] = array_values(array_unique(array_map('strval', is_array($pending['participants'] ?? null) ? $pending['participants'] : [])));
        }

        $pending['stage'] = 'await_tags';
        $pending['participants_set'] = false;
        $pending['summary_requested'] = null;
        unset(
            $pending['participants_prompt_message_id'],
            $pending['summary_prompt_message_id'],
            $pending['tags_markup_retry_at'],
            $pending['participants_markup_retry_at'],
            $pending['last_toggle_action'],
            $pending['last_toggle_at_ms'],
            $pending['next_retry_at'],
            $pending['retry_notice_sent']
        );
        $this->reminders->resetPendingReminder($pending, $config);
        $state->setPending($pending);
        $state->save();
        Logger::info('DBG transition to tags step', [
            'reset_selection' => $resetSelection,
            'tags' => $pending['tags'],
            'participants' => $pending['participants'],
            'header' => $header,
        ]);

        $this->sendTagsPrompt(
            $pending,
            $state,
            $telegram,
            $chatId,
            $this->buildTagsPromptText($header)
        );
    }

    /**
     * @param string[] $tags
     */
    private function formatTagsForMessage(array $tags): string
    {
        if ($tags === []) {
            return '—';
        }

        return implode(', ', array_map(static fn(string $tag): string => '#' . $tag, $tags));
    }

    private function isRapidDuplicateToggle(array $pending, string $action): bool
    {
        $lastAction = (string) ($pending['last_toggle_action'] ?? '');
        $lastAtMs = (int) ($pending['last_toggle_at_ms'] ?? 0);
        if ($lastAction === '' || $lastAtMs <= 0) {
            return false;
        }

        $delta = $this->nowMs() - $lastAtMs;
        return $lastAction === $action && $delta >= 0 && $delta < self::TOGGLE_DEBOUNCE_MS;
    }

    private function rememberToggleAction(array &$pending, string $action): void
    {
        $pending['last_toggle_action'] = $action;
        $pending['last_toggle_at_ms'] = $this->nowMs();
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
