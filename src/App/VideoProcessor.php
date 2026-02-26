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

        $result = \runCommand($command);
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

        $result = \runCommand($command);
        if ($result['code'] !== 0) {
            \logMessage('ffmpeg failed for ' . $sourceFile . ': ' . trim($result['stderr'] ?: $result['stdout']));
            return false;
        }

        return is_file($outputFile) && ((int) filesize($outputFile)) > 0;
    }
}
