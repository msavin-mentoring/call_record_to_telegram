<?php

declare(strict_types=1);

namespace App;

final class PlatformParticipantDirectory
{
    private ?\PDO $platformDb = null;

    public function __construct(
        private readonly Config $config,
        private readonly PlatformApiClient $platformApiClient,
    ) {
    }

    /**
     * @return array{
     *   presets:string[],
     *   suggested:string[],
     *   suggestion_text:?string
     * }
     */
    public function resolveForRecording(\DateTimeImmutable $recordedAt, array $fallbackPresets): array
    {
        $presets = $fallbackPresets;

        try {
            $students = $this->platformApiClient->fetchStudents();
            $activePresets = [];
            foreach ($students as $student) {
                if ($student['status'] !== 'active') {
                    continue;
                }

                $activePresets[] = $student['telegramNick'];
            }

            if ($activePresets !== []) {
                $presets = array_values(array_unique($activePresets));
            }
        } catch (\Throwable $e) {
            Logger::warning('Failed to refresh participants from platform API, using fallback presets.', [
                'error' => $e->getMessage(),
            ]);
        }

        $suggested = [];
        $suggestionText = null;
        try {
            $match = $this->matchMentorSession($recordedAt);
            if ($match !== null) {
                $suggested = $match['participants'];
                $suggestionText = $match['text'];
                $presets = array_values(array_unique(array_merge($presets, $suggested)));
            }
        } catch (\Throwable $e) {
            Logger::warning('Failed to match mentor_session from platform DB.', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'presets' => $presets,
            'suggested' => $suggested,
            'suggestion_text' => $suggestionText,
        ];
    }

    /**
     * @return array{participants:string[],text:string}|null
     */
    private function matchMentorSession(\DateTimeImmutable $recordedAt): ?array
    {
        $db = $this->platformDb();
        if ($db === null) {
            return null;
        }

        $windowMinutes = max(15, $this->config->platformSessionMatchWindowMinutes);
        $from = $recordedAt->modify(sprintf('-%d minutes', $windowMinutes));
        $to = $recordedAt->modify(sprintf('+%d minutes', $windowMinutes));

        $sql = <<<'SQL'
SELECT
    s.telegram_nick,
    s.first_name,
    s.last_name,
    s.status,
    ms.started_at,
    ms.duration_min,
    ms.type,
    ms.event_url,
    ms.is_group
FROM mentor_session ms
JOIN student s ON s.id = ms.student_id
WHERE ms.started_at BETWEEN :from_ts AND :to_ts
ORDER BY ms.started_at ASC, s.last_name ASC, s.first_name ASC
SQL;

        $statement = $db->prepare($sql);
        $statement->execute([
            'from_ts' => $from->format('Y-m-d H:i:sP'),
            'to_ts' => $to->format('Y-m-d H:i:sP'),
        ]);

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        $bestGroup = null;
        foreach ($this->groupMentorSessions($rows) as $group) {
            $distance = abs($group['started_at']->getTimestamp() - $recordedAt->getTimestamp());
            if ($bestGroup === null || $distance < $bestGroup['distance']) {
                $bestGroup = $group + ['distance' => $distance];
            }
        }

        if ($bestGroup === null) {
            return null;
        }

        $participants = array_values(array_unique($bestGroup['participants']));
        if ($participants === []) {
            return null;
        }

        $startedAt = $bestGroup['started_at']->setTimezone(new \DateTimeZone($this->config->appTimezone));
        $text = sprintf(
            'Автоподсказка по mentor_session: %s (%s, %d мин.)',
            implode(', ', $participants),
            $startedAt->format('Y-m-d H:i'),
            $bestGroup['duration_min']
        );

        return [
            'participants' => $participants,
            'text' => $text,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{participants:string[],started_at:\DateTimeImmutable,duration_min:int,key:string}>
     */
    private function groupMentorSessions(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $telegramNick = $this->normalizeTelegramNick((string) ($row['telegram_nick'] ?? ''));
            if ($telegramNick === '') {
                continue;
            }

            $startedAt = new \DateTimeImmutable((string) $row['started_at']);
            $groupKey = trim((string) ($row['event_url'] ?? ''));
            if ($groupKey === '') {
                $groupKey = $startedAt->format(DATE_ATOM) . '|' . (string) ($row['duration_min'] ?? '0');
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'participants' => [],
                    'started_at' => $startedAt,
                    'duration_min' => max(1, (int) ($row['duration_min'] ?? 0)),
                    'key' => $groupKey,
                ];
            }

            $groups[$groupKey]['participants'][] = $telegramNick;
        }

        return array_values($groups);
    }

    private function platformDb(): ?\PDO
    {
        if ($this->platformDb instanceof \PDO) {
            return $this->platformDb;
        }

        if (
            $this->config->platformDbHost === null ||
            $this->config->platformDbName === null ||
            $this->config->platformDbUser === null ||
            $this->config->platformDbPassword === null
        ) {
            return null;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->config->platformDbHost,
            max(1, $this->config->platformDbPort),
            $this->config->platformDbName
        );

        $this->platformDb = new \PDO($dsn, $this->config->platformDbUser, $this->config->platformDbPassword, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
        ]);

        return $this->platformDb;
    }

    private function normalizeTelegramNick(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        return str_starts_with($normalized, '@') ? $normalized : '@' . $normalized;
    }
}
