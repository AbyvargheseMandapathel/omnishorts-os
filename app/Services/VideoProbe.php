<?php

namespace App\Services;

/**
 * Reads real metadata from an uploaded video file (duration). Pure PHP via
 * getID3 — works on shared hosting without ffmpeg/ffprobe. Returns null when
 * the duration cannot be determined; callers fall back to "unknown" instead
 * of ever fabricating a number.
 */
class VideoProbe
{
    /**
     * Duration of a local video file in whole seconds, or null when the file
     * is missing, corrupt, or in an unsupported container.
     */
    public function durationSeconds(string $path): ?int
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $getID3 = new \getID3;
            $info = $getID3->analyze($path);
        } catch (\Throwable) {
            return null;
        }

        if (empty($info['playtime_seconds']) || ! is_numeric($info['playtime_seconds'])) {
            return null;
        }

        return max(1, (int) round((float) $info['playtime_seconds']));
    }
}
