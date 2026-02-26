<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ReminderService
{
    public function resetPendingReminder(array &$pending, Config $config): void
    {
        $base = max(30, $config->reminderBaseSeconds);
        $pending['reminder_attempt'] = 0;
        $pending['next_reminder_at'] = time() + $base;
        unset($pending['last_reminder_at']);
    }

    public function clearPendingReminder(array &$pending): void
    {
        unset($pending['next_reminder_at'], $pending['reminder_attempt'], $pending['last_reminder_at']);
    }

    public function maybeSendPendingReminder(Config $config, StateStore $state, TelegramClient $telegram): void
    {
        $pending = $state->getPending();
        if ($pending === null || !$this->pendingNeedsUserReply($pending)) {
            return;
        }

        $chatId = (string) ($pending['chat_id'] ?? '');
        if ($chatId === '') {
            return;
        }

        if (!array_key_exists('next_reminder_at', $pending)) {
            $this->resetPendingReminder($pending, $config);
            $state->setPending($pending);
            $state->save();
            return;
        }

        $nextReminderAt = (int) ($pending['next_reminder_at'] ?? 0);
        if (time() < $nextReminderAt) {
            return;
        }

        $quietInfo = $this->moscowQuietHoursInfo($config);
        if ($quietInfo['night']) {
            $pending['next_reminder_at'] = $quietInfo['next_daytime_at'];
            $state->setPending($pending);
            $state->save();
            return;
        }

        $telegram->sendMessage($chatId, $this->buildReminderText($pending));

        $attempt = (int) ($pending['reminder_attempt'] ?? 0) + 1;
        $pending['reminder_attempt'] = $attempt;
        $pending['last_reminder_at'] = time();
        $pending['next_reminder_at'] = time() + $this->reminderIntervalForAttempt($config, $attempt);

        $state->setPending($pending);
        $state->save();
    }

    public function pendingNeedsUserReply(array $pending): bool
    {
        $stage = (string) ($pending['stage'] ?? '');
        if ($stage === 'await_tags' || $stage === 'await_summary_choice') {
            return true;
        }

        if ($stage === 'await_participants') {
            return !(bool) ($pending['participants_set'] ?? false);
        }

        return false;
    }

    private function reminderIntervalForAttempt(Config $config, int $attempt): int
    {
        $base = max(30, $config->reminderBaseSeconds);
        $max = max($base, $config->reminderMaxSeconds);
        $attempt = max(0, $attempt);

        $interval = $base;
        for ($i = 0; $i < $attempt; $i++) {
            $interval = min($max, $interval * 2);
            if ($interval >= $max) {
                break;
            }
        }

        return $interval;
    }

    private function isNightHour(int $hour, int $nightStartHour, int $nightEndHour): bool
    {
        if ($nightStartHour === $nightEndHour) {
            return false;
        }

        if ($nightStartHour < $nightEndHour) {
            return $hour >= $nightStartHour && $hour < $nightEndHour;
        }

        return $hour >= $nightStartHour || $hour < $nightEndHour;
    }

    /**
     * @return array{now:int,night:bool,next_daytime_at:int}
     */
    private function moscowQuietHoursInfo(Config $config): array
    {
        try {
            $tz = new DateTimeZone($config->reminderTimezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('Europe/Moscow');
        }

        $nowDt = new DateTimeImmutable('now', $tz);
        $hour = (int) $nowDt->format('G');
        $isNight = $this->isNightHour($hour, $config->reminderNightStartHour, $config->reminderNightEndHour);

        if (!$isNight) {
            return [
                'now' => $nowDt->getTimestamp(),
                'night' => false,
                'next_daytime_at' => $nowDt->getTimestamp(),
            ];
        }

        $target = $nowDt->setTime($config->reminderNightEndHour, 0, 0);
        if ($target <= $nowDt) {
            $target = $target->modify('+1 day');
        }

        return [
            'now' => $nowDt->getTimestamp(),
            'night' => true,
            'next_daytime_at' => $target->getTimestamp(),
        ];
    }

    private function buildReminderText(array $pending): string
    {
        $stage = (string) ($pending['stage'] ?? '');
        return match ($stage) {
            'await_tags' => "Напоминание: пришлите теги для созвона (или нажмите кнопки в сообщении с клипом).",
            'await_participants' => "Напоминание: пришлите участников в формате @user1 @user2 (или '-' для пропуска).",
            'await_summary_choice' => "Напоминание: нужно ли саммари? Ответьте «да» или «нет» (или нажмите кнопку).",
            default => "Напоминание: ожидаю ваш ответ по текущему созвону.",
        };
    }
}
