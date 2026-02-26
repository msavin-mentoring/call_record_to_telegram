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
}
