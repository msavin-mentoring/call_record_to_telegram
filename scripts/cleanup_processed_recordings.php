#!/usr/bin/env php
<?php

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_ERROR = 1;

main($argv);

/**
 * @param string[] $argv
 */
function main(array $argv): void
{
    $projectRoot = dirname(__DIR__);
    $options = getopt('', [
        'state:',
        'recordings:',
        'days:',
        'apply',
        'prune-empty-dirs',
        'help',
    ]);

    if (isset($options['help'])) {
        printUsage($argv[0] ?? 'cleanup_processed_recordings.php');
        exit(EXIT_OK);
    }

    $days = isset($options['days']) ? (int) $options['days'] : 14;
    if ($days < 0) {
        fwrite(STDERR, "Error: --days must be >= 0\n");
        exit(EXIT_ERROR);
    }

    $env = loadSimpleEnv($projectRoot . '/.env');

    $statePath = (string) ($options['state'] ?? '');
    if ($statePath === '') {
        $statePath = $projectRoot . '/data/state.json';
    } elseif (!isAbsolutePath($statePath)) {
        $statePath = $projectRoot . '/' . ltrim($statePath, '/');
    }

    $recordingsRoot = (string) ($options['recordings'] ?? '');
    if ($recordingsRoot === '') {
        $recordingsRoot = (string) ($env['RECORDINGS_HOST_PATH'] ?? ($projectRoot . '/recordings'));
    } elseif (!isAbsolutePath($recordingsRoot)) {
        $recordingsRoot = $projectRoot . '/' . ltrim($recordingsRoot, '/');
    }

    $apply = isset($options['apply']);
    $pruneEmptyDirs = isset($options['prune-empty-dirs']);
    $cutoffTs = time() - ($days * 86400);

    if (!is_file($statePath)) {
        fwrite(STDERR, "Error: state file not found: {$statePath}\n");
        exit(EXIT_ERROR);
    }
    if (!is_dir($recordingsRoot)) {
        fwrite(STDERR, "Error: recordings root not found: {$recordingsRoot}\n");
        exit(EXIT_ERROR);
    }

    $raw = file_get_contents($statePath);
    if ($raw === false) {
        fwrite(STDERR, "Error: failed to read state file: {$statePath}\n");
        exit(EXIT_ERROR);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Error: invalid JSON in state file: {$statePath}\n");
        exit(EXIT_ERROR);
    }

    $processed = $decoded['processed'] ?? null;
    if (!is_array($processed)) {
        fwrite(STDERR, "Error: state file has no valid 'processed' map\n");
        exit(EXIT_ERROR);
    }

    $summary = [
        'total_processed_records' => count($processed),
        'eligible_records' => 0,
        'existing_files' => 0,
        'missing_files' => 0,
        'unsafe_keys_skipped' => 0,
        'deleted_files' => 0,
        'failed_deletes' => 0,
        'bytes_eligible' => 0,
        'bytes_deleted' => 0,
        'pruned_dirs' => 0,
    ];

    echo "Cleanup processed recordings\n";
    echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
    echo "State file: {$statePath}\n";
    echo "Recordings root: {$recordingsRoot}\n";
    echo "Delete processed older than {$days} day(s), cutoff: " . date(DATE_ATOM, $cutoffTs) . "\n\n";

    foreach ($processed as $relativeKey => $payload) {
        if (!is_string($relativeKey) || !is_array($payload)) {
            continue;
        }

        $processedAtRaw = $payload['processed_at'] ?? null;
        if (!is_string($processedAtRaw) || trim($processedAtRaw) === '') {
            continue;
        }

        $processedAtTs = strtotime($processedAtRaw);
        if ($processedAtTs === false || $processedAtTs > $cutoffTs) {
            continue;
        }

        $normalizedKey = normalizeRelativeKey($relativeKey);
        if ($normalizedKey === null) {
            $summary['unsafe_keys_skipped']++;
            continue;
        }

        $summary['eligible_records']++;
        $fullPath = $recordingsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedKey);

        if (!is_file($fullPath)) {
            $summary['missing_files']++;
            continue;
        }

        $summary['existing_files']++;
        $size = filesize($fullPath);
        $size = $size === false ? 0 : (int) $size;
        $summary['bytes_eligible'] += $size;

        if (!$apply) {
            echo "[DRY] {$fullPath}\n";
            continue;
        }

        if (@unlink($fullPath)) {
            $summary['deleted_files']++;
            $summary['bytes_deleted'] += $size;
            echo "[DEL] {$fullPath}\n";
        } else {
            $summary['failed_deletes']++;
            echo "[ERR] {$fullPath}\n";
        }
    }

    if ($apply && $pruneEmptyDirs) {
        $summary['pruned_dirs'] = pruneEmptyDirectories($recordingsRoot);
    }

    echo "\nSummary:\n";
    echo "- processed records in state: {$summary['total_processed_records']}\n";
    echo "- eligible old processed records: {$summary['eligible_records']}\n";
    echo "- existing files among eligible: {$summary['existing_files']}\n";
    echo "- missing files among eligible: {$summary['missing_files']}\n";
    echo "- unsafe keys skipped: {$summary['unsafe_keys_skipped']}\n";
    echo "- bytes eligible: " . humanBytes((int) $summary['bytes_eligible']) . "\n";
    if ($apply) {
        echo "- deleted files: {$summary['deleted_files']}\n";
        echo "- failed deletes: {$summary['failed_deletes']}\n";
        echo "- bytes deleted: " . humanBytes((int) $summary['bytes_deleted']) . "\n";
        if ($pruneEmptyDirs) {
            echo "- pruned empty dirs: {$summary['pruned_dirs']}\n";
        }
    }

    if ($apply && $summary['failed_deletes'] > 0) {
        exit(EXIT_ERROR);
    }

    exit(EXIT_OK);
}

function printUsage(string $scriptName): void
{
    $base = basename($scriptName);
    echo "Usage:\n";
    echo "  php scripts/{$base} [--days=14] [--state=data/state.json] [--recordings=/path] [--apply] [--prune-empty-dirs]\n\n";
    echo "Options:\n";
    echo "  --days               Delete records processed more than N days ago (default: 14)\n";
    echo "  --state              Path to state.json (default: ./data/state.json)\n";
    echo "  --recordings         Root recordings path (default: RECORDINGS_HOST_PATH from .env)\n";
    echo "  --apply              Actually delete files (without this flag it's dry-run)\n";
    echo "  --prune-empty-dirs   After deletion, remove empty directories under recordings root\n";
    echo "  --help               Show this help\n";
}

/**
 * @return array<string, string>
 */
function loadSimpleEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        if ($key === '') {
            continue;
        }

        $env[$key] = $value;
    }

    return $env;
}

function isAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/');
}

function normalizeRelativeKey(string $key): ?string
{
    $key = str_replace('\\', '/', trim($key));
    $key = ltrim($key, '/');
    if ($key === '') {
        return null;
    }

    $parts = explode('/', $key);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
    }

    return implode('/', $parts);
}

function pruneEmptyDirectories(string $root): int
{
    $removed = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }

        $path = $item->getPathname();
        if ($path === $root) {
            continue;
        }

        if (@rmdir($path)) {
            $removed++;
        }
    }

    return $removed;
}

function humanBytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    return sprintf('%.2f GB', $bytes / (1024 * 1024 * 1024));
}
