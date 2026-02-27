<?php

declare(strict_types=1);

namespace App;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RecordingFileService
{
    /**
     * @return string[]
     */
    public function findVideoFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            if (strtolower((string) pathinfo($item->getFilename(), PATHINFO_EXTENSION)) === 'mp4') {
                $files[] = $item->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    public function relativePath(string $baseDir, string $filePath): string
    {
        $base = rtrim(realpath($baseDir) ?: $baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = realpath($filePath) ?: $filePath;

        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    public function isFileStable(string $filePath, int $minAgeSeconds, int $stabilityWaitSeconds): bool
    {
        clearstatcache(true, $filePath);

        $firstMtime = filemtime($filePath);
        $firstSize = filesize($filePath);

        if ($firstMtime === false || $firstSize === false) {
            return false;
        }

        if ((time() - $firstMtime) < $minAgeSeconds) {
            return false;
        }

        if ($stabilityWaitSeconds > 0) {
            sleep($stabilityWaitSeconds);
            clearstatcache(true, $filePath);

            $secondMtime = filemtime($filePath);
            $secondSize = filesize($filePath);

            if ($secondMtime === false || $secondSize === false) {
                return false;
            }

            return $firstMtime === $secondMtime && $firstSize === $secondSize;
        }

        return true;
    }

    public function clipTempPath(string $tempDir, string $relativePath): string
    {
        $hash = substr(sha1($relativePath), 0, 12);
        $name = pathinfo($relativePath, PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        if (!is_string($safeName) || $safeName === '') {
            $safeName = 'clip';
        }

        return $tempDir . DIRECTORY_SEPARATOR . $safeName . '_' . $hash . '.mp4';
    }

    public function detectRecordingDate(string $relativeKey, string $filePath): string
    {
        if (preg_match('/_(\d{4}-\d{2}-\d{2})-\d{2}-\d{2}-\d{2}\.mp4$/', basename($relativeKey), $matches) === 1) {
            return $matches[1];
        }

        $mtime = filemtime($filePath);
        if ($mtime !== false) {
            return date('Y-m-d', $mtime);
        }

        return date('Y-m-d');
    }

    /**
     * @return array{date:string,time:string}
     */
    public function detectRecordingDateTime(string $relativeKey, string $filePath): array
    {
        if (preg_match('/_(\d{4}-\d{2}-\d{2})-(\d{2})-(\d{2})-(\d{2})\.mp4$/', basename($relativeKey), $matches) === 1) {
            return [
                'date' => $matches[1],
                'time' => "{$matches[2]}:{$matches[3]}:{$matches[4]}",
            ];
        }

        $mtime = filemtime($filePath);
        if ($mtime !== false) {
            return [
                'date' => date('Y-m-d', $mtime),
                'time' => date('H:i:s', $mtime),
            ];
        }

        return [
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
        ];
    }

    public function writeTranscriptToTempFile(string $tempDir, string $key, string $transcript): ?string
    {
        if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            return null;
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($key, PATHINFO_FILENAME));
        if (!is_string($safeBase) || $safeBase === '') {
            $safeBase = 'transcript';
        }

        $path = $tempDir . DIRECTORY_SEPARATOR . $safeBase . '_transcript.txt';
        $content = "Транскрипт для: {$key}\n\n" . trim($transcript) . "\n";
        if (file_put_contents($path, $content) === false) {
            return null;
        }

        return $path;
    }
}
