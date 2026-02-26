<?php

declare(strict_types=1);

namespace App;

final class TextFormatter
{
    /**
     * @return string[]
     */
    public function splitTextByMaxLength(string $text, int $maxLength): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $maxLength = max(1000, $maxLength);
        if (strlen($text) <= $maxLength) {
            return [$text];
        }

        $parts = [];
        $current = '';
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current === '') {
                if (strlen($paragraph) <= $maxLength) {
                    $current = $paragraph;
                    continue;
                }

                $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if ($sentence === '') {
                        continue;
                    }

                    if ($current === '') {
                        $current = $sentence;
                        continue;
                    }

                    if (strlen($current . ' ' . $sentence) <= $maxLength) {
                        $current .= ' ' . $sentence;
                    } else {
                        $parts[] = $current;
                        $current = $sentence;
                    }
                }

                continue;
            }

            if (strlen($current . "\n\n" . $paragraph) <= $maxLength) {
                $current .= "\n\n" . $paragraph;
                continue;
            }

            $parts[] = $current;
            if (strlen($paragraph) <= $maxLength) {
                $current = $paragraph;
                continue;
            }

            $current = '';
            $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }

                if ($current === '') {
                    $current = $sentence;
                    continue;
                }

                if (strlen($current . ' ' . $sentence) <= $maxLength) {
                    $current .= ' ' . $sentence;
                } else {
                    $parts[] = $current;
                    $current = $sentence;
                }
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    public function truncateForTelegram(string $text, int $maxChars = 3800): string
    {
        $text = trim($text);
        if ($text === '') {
            return '—';
        }

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, max(100, $maxChars - 20)) . "\n...\n[обрезано]";
    }

    public function buildFinalCaption(array $tags, array $participants, string $date): string
    {
        $tagText = $tags === []
            ? '—'
            : implode(', ', array_map(static fn(string $tag): string => '#' . $tag, $tags));

        $participantsText = $participants === []
            ? '—'
            : implode(', ', $participants);

        return "теги: {$tagText}\nучастники: {$participantsText}\nдата: {$date}";
    }
}
