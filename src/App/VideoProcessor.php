<?php

declare(strict_types=1);

namespace App;

final class VideoProcessor
{
    public function __construct(private readonly int $clipDurationSeconds)
    {
    }

    public function getDuration(string $filePath): ?float
    {
        $command = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($filePath);

        $result = CommandRunner::run($command);
        if ($result['code'] !== 0) {
            return null;
        }

        $duration = trim($result['stdout']);
        if ($duration === '' || !is_numeric($duration)) {
            return null;
        }

        return (float) $duration;
    }

    public function createMiddleClip(string $sourceFile, string $outputFile, float $duration): bool
    {
        $clipLength = min((float) $this->clipDurationSeconds, $duration);
        if ($clipLength <= 0.0) {
            return false;
        }

        $start = max(0.0, ($duration - $clipLength) / 2.0);
        $command = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -ss %.3f -i %s -t %.3f -c:v libx264 -preset veryfast -crf 27 -c:a aac -movflags +faststart %s',
            $start,
            escapeshellarg($sourceFile),
            $clipLength,
            escapeshellarg($outputFile)
        );

        $result = CommandRunner::run($command);
        if ($result['code'] !== 0) {
            Logger::info('ffmpeg failed for ' . $sourceFile . ': ' . trim($result['stderr'] ?: $result['stdout']));
            return false;
        }

        return is_file($outputFile) && ((int) filesize($outputFile)) > 0;
    }

    /**
     * @return string[]
     */
    public function splitIntoSegments(
        string $sourceFile,
        string $tempDir,
        string $segmentPrefix,
        int $segmentSeconds
    ): array {
        if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            return [];
        }

        $segmentSeconds = max(60, $segmentSeconds);
        $safePrefix = preg_replace('/[^A-Za-z0-9._-]+/', '_', $segmentPrefix);
        if (!is_string($safePrefix) || $safePrefix === '') {
            $safePrefix = 'segment';
        }

        $pattern = $tempDir . DIRECTORY_SEPARATOR . $safePrefix . '_%03d.mp4';
        foreach (glob($tempDir . DIRECTORY_SEPARATOR . $safePrefix . '_*.mp4') ?: [] as $oldFile) {
            @unlink($oldFile);
        }

        $command = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -i %s -map 0 -c copy -f segment -segment_time %d -reset_timestamps 1 %s',
            escapeshellarg($sourceFile),
            $segmentSeconds,
            escapeshellarg($pattern)
        );

        $result = CommandRunner::run($command);
        if ($result['code'] !== 0) {
            Logger::info('ffmpeg split failed for ' . $sourceFile . ': ' . trim($result['stderr'] ?: $result['stdout']));
            return [];
        }

        $files = glob($tempDir . DIRECTORY_SEPARATOR . $safePrefix . '_*.mp4') ?: [];
        sort($files);

        return array_values(array_filter($files, static fn(string $path): bool => is_file($path) && ((int) filesize($path)) > 0));
    }
}
