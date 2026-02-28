<?php

declare(strict_types=1);

namespace App;

final class UserInputParser
{
    /**
     * @return string[]
     */
    public function parseTagsFromText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (preg_match_all('/#([\p{L}\p{N}_-]+)/u', $text, $matches) > 0) {
            $tags = $matches[1];
        } else {
            $tags = preg_split('/[\s,;]+/u', $text) ?: [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            $clean = trim((string) $tag);
            if ($clean === '') {
                continue;
            }

            $lower = mb_strtolower(ltrim($clean, '#'));
            $mapped = match ($lower) {
                'mock', 'мок' => 'мок',
                'summary', 'резюме' => 'резюме',
                'tasks', 'задачи' => 'задачи',
                'review', 'ревью' => 'ревью',
                'legend', 'легенда' => 'легенда',
                'screen', 'screenshot', 'скрин', 'скриншот' => 'скрин',
                'crossmock', 'кроссмок' => 'кроссмок',
                'welcome', 'велком' => 'велком',
                default => $lower,
            };

            $mapped = preg_replace('/[^\p{L}\p{N}_]+/u', '', $mapped);
            if (!is_string($mapped) || $mapped === '') {
                continue;
            }

            $normalized[] = $mapped;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return string[]
     */
    public function parseParticipantsFromText(string $text): array
    {
        $text = trim($text);
        if ($this->isParticipantsSkipInput($text)) {
            return [];
        }

        $participants = [];
        if (preg_match_all('/@([A-Za-z0-9_]{3,32})/', $text, $matches) > 0) {
            foreach ($matches[1] as $username) {
                $participants[] = '@' . $username;
            }

            return array_values(array_unique($participants));
        }

        $tokens = preg_split('/[\s,;]+/', $text) ?: [];
        foreach ($tokens as $token) {
            $clean = ltrim(trim($token), '@');
            if ($clean === '') {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_]{3,32}$/', $clean) !== 1) {
                continue;
            }

            $participants[] = '@' . $clean;
        }

        return array_values(array_unique($participants));
    }

    public function isParticipantsSkipInput(string $text): bool
    {
        $value = mb_strtolower(trim($text));
        return $value === '-' || $value === 'skip' || $value === 'пропустить';
    }

    public function isTagsSkipInput(string $text): bool
    {
        $value = mb_strtolower(trim($text));
        return $value === '-' || $value === 'skip' || $value === 'пропустить' || $value === 'без тега';
    }

    public function parseYesNoChoice(string $text): ?bool
    {
        $value = mb_strtolower(trim($text));
        return match ($value) {
            'да', 'yes', 'y', '+', 'ok', 'ага' => true,
            'нет', 'no', 'n', '-', 'не', 'nope' => false,
            default => null,
        };
    }

    public function isBackInput(string $text): bool
    {
        $value = mb_strtolower(trim($text));
        return $value === 'назад'
            || $value === 'вернуться назад'
            || $value === 'back';
    }

    public function isRestartInput(string $text): bool
    {
        $value = mb_strtolower(trim($text));
        return $value === 'сначала'
            || $value === 'начать сначала'
            || $value === 'restart'
            || $value === 'reset';
    }
}
