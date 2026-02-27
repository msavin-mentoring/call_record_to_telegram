<?php

declare(strict_types=1);

namespace App;

final class KeyboardFactory
{
    public const TAG_PRESETS = [
        'mock' => 'мок',
        'summary' => 'резюме',
        'tasks' => 'задачи',
        'review' => 'ревью',
    ];

    /**
     * @return array{inline_keyboard:array<int,array<int,array{text:string,callback_data:string}>>}
     */
    public function buildTagsKeyboard(array $selectedTags): array
    {
        $selected = array_fill_keys($selectedTags, true);

        $mk = self::TAG_PRESETS['mock'];
        $sm = self::TAG_PRESETS['summary'];
        $ts = self::TAG_PRESETS['tasks'];
        $rv = self::TAG_PRESETS['review'];

        return [
            'inline_keyboard' => [
                [
                    ['text' => (isset($selected[$mk]) ? '✅ ' : '') . $mk, 'callback_data' => 'tag:mock'],
                    ['text' => (isset($selected[$sm]) ? '✅ ' : '') . $sm, 'callback_data' => 'tag:summary'],
                ],
                [
                    ['text' => (isset($selected[$ts]) ? '✅ ' : '') . $ts, 'callback_data' => 'tag:tasks'],
                    ['text' => (isset($selected[$rv]) ? '✅ ' : '') . $rv, 'callback_data' => 'tag:review'],
                ],
                [
                    ['text' => 'Готово', 'callback_data' => 'tag:done'],
                    ['text' => 'Без тега', 'callback_data' => 'tag:skip'],
                ],
            ],
        ];
    }

    /**
     * @return array{inline_keyboard:array<int,array<int,array{text:string,callback_data:string}>>}
     */
    public function buildSummaryChoiceKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => 'Да', 'callback_data' => 'summary:yes'],
                    ['text' => 'Нет', 'callback_data' => 'summary:no'],
                ],
            ],
        ];
    }

    public function mapTagSlug(string $slug): ?string
    {
        return self::TAG_PRESETS[$slug] ?? null;
    }

    /**
     * @param string[] $presets
     * @param string[] $selectedParticipants
     * @return array{inline_keyboard:array<int,array<int,array{text:string,callback_data:string}>>}
     */
    public function buildParticipantsKeyboard(array $presets, array $selectedParticipants): array
    {
        $normalizedPresets = [];
        foreach ($presets as $preset) {
            $username = trim((string) $preset);
            if ($username === '') {
                continue;
            }
            if (!str_starts_with($username, '@')) {
                $username = '@' . $username;
            }
            if (preg_match('/^@[A-Za-z0-9_]{3,32}$/', $username) !== 1) {
                continue;
            }
            $normalizedPresets[] = $username;
        }
        $normalizedPresets = array_values(array_unique($normalizedPresets));

        $selectedSet = array_fill_keys($selectedParticipants, true);
        $rows = [];
        $currentRow = [];

        foreach ($normalizedPresets as $username) {
            $currentRow[] = [
                'text' => (isset($selectedSet[$username]) ? '✅ ' : '') . $username,
                'callback_data' => 'participant:toggle:' . $username,
            ];
            if (count($currentRow) === 2) {
                $rows[] = $currentRow;
                $currentRow = [];
            }
        }

        if ($currentRow !== []) {
            $rows[] = $currentRow;
        }

        $rows[] = [
            ['text' => 'Готово', 'callback_data' => 'participant:done'],
            ['text' => 'Пропустить', 'callback_data' => 'participant:skip'],
        ];

        return ['inline_keyboard' => $rows];
    }
}
