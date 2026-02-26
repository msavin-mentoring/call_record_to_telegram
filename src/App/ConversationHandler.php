<?php

declare(strict_types=1);

namespace App;

final class ConversationHandler
{
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
                    $this->handleTagCallback($callbackData, $callbackId, $pending, $state, $telegram, $config);
                }
            } elseif ($stage === 'await_summary_choice') {
                $summaryPromptMessageId = (int) ($pending['summary_prompt_message_id'] ?? 0);
                if ($messageId !== $summaryPromptMessageId) {
                    if ($callbackId !== '') {
                        $telegram->answerCallbackQuery($callbackId, 'Эта кнопка уже неактуальна');
                    }
                    return;
                }

                if ($callbackData === 'summary:yes' || $callbackData === 'summary:no') {
                    $choice = $callbackData === 'summary:yes';
                    $pending['summary_requested'] = $choice;
                    $pending['stage'] = 'ready_finalize';
                    $this->reminders->clearPendingReminder($pending);
                    $state->setPending($pending);
                    $state->save();

                    if ($callbackId !== '') {
                        $telegram->answerCallbackQuery($callbackId, $choice ? 'Сделаем саммари' : 'Саммари пропускаем');
                    }
                } elseif ($callbackId !== '') {
                    $telegram->answerCallbackQuery($callbackId, 'Выберите Да или Нет');
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
            $tags = $this->parser->parseTagsFromText($text);
            if ($tags === []) {
                $this->reminders->resetPendingReminder($pending, $config);
                $state->setPending($pending);
                $state->save();
                $telegram->sendMessage(
                    $pendingChatId,
                    'Не удалось распознать теги. Отправьте, например: #мок #резюме или выберите кнопками.'
                );
                return;
            }

            $pending['tags'] = $tags;
            $pending['stage'] = 'await_participants';
            $pending['participants_set'] = false;
            $pending['summary_requested'] = null;
            unset($pending['summary_prompt_message_id']);
            $this->reminders->resetPendingReminder($pending, $config);
            $state->setPending($pending);
            $state->save();

            $participantsPrompt = $telegram->sendMessage(
                $pendingChatId,
                "Теги сохранены: " . implode(', ', array_map(static fn(string $tag): string => '#' . $tag, $tags)) .
                "\nТеперь пришлите участников (ники), например: @msavin_dev @asdfasdf\nЕсли не хотите указывать, отправьте: -"
            );
            if ($participantsPrompt !== null) {
                $pending['participants_prompt_message_id'] = (int) ($participantsPrompt['message_id'] ?? 0);
                $state->setPending($pending);
                $state->save();
            }

            return;
        }

        if ($stage === 'await_participants') {
            $participants = $this->parser->parseParticipantsFromText($text);

            if ($participants === [] && !$this->parser->isParticipantsSkipInput($text)) {
                $this->reminders->resetPendingReminder($pending, $config);
                $state->setPending($pending);
                $state->save();
                $telegram->sendMessage(
                    $pendingChatId,
                    'Не удалось распознать ники. Пришлите в формате: @user1 @user2 или отправьте "-" для пропуска.'
                );
                return;
            }

            $pending['participants'] = $participants;
            $pending['participants_set'] = true;
            unset($pending['next_retry_at'], $pending['retry_notice_sent']);

            if ($openAiEnabled) {
                $summaryPrompt = $telegram->sendMessage(
                    $pendingChatId,
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
            return;
        }

        if ($stage === 'await_summary_choice') {
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
        string $callbackId,
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

        if ($callbackData === 'tag:done') {
            if ($tags === []) {
                if ($callbackId !== '') {
                    $telegram->answerCallbackQuery($callbackId, 'Выберите хотя бы один тег');
                }
                return;
            }

            $pending['stage'] = 'await_participants';
            $pending['participants_set'] = false;
            $pending['summary_requested'] = null;
            unset($pending['summary_prompt_message_id']);
            $this->reminders->resetPendingReminder($pending, $config);
            $state->setPending($pending);
            $state->save();

            if ($callbackId !== '') {
                $telegram->answerCallbackQuery($callbackId, 'Теги сохранены');
            }

            $participantsPrompt = $telegram->sendMessage(
                $chatId,
                "Теги: " . implode(', ', array_map(static fn(string $tag): string => '#' . $tag, $tags)) .
                "\nПришлите участников (ники), например: @msavin_dev @asdfasdf\nЕсли не хотите указывать, отправьте: -"
            );
            if ($participantsPrompt !== null) {
                $pending['participants_prompt_message_id'] = (int) ($participantsPrompt['message_id'] ?? 0);
                $state->setPending($pending);
                $state->save();
            }

            return;
        }

        $slug = substr($callbackData, 4);
        $mappedTag = $this->keyboards->mapTagSlug($slug);
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
        $this->reminders->resetPendingReminder($pending, $config);

        $state->setPending($pending);
        $state->save();

        $messageId = (int) ($pending['prompt_message_id'] ?? 0);
        if ($messageId > 0) {
            $telegram->editMessageReplyMarkup($chatId, $messageId, $this->keyboards->buildTagsKeyboard($newTags));
        }
    }
}
