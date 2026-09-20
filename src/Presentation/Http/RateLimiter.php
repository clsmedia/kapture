<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class RateLimiter
{
    /**
     * Record one attempt for $key and return whether it stays within $max
     * over a $windowSeconds window. Atomic via flock(); fail-open on
     * temp-file errors so captures are never blocked by a full /tmp.
     *
     * The counter file is kept on disk between calls — dropping it after
     * every write would make the window restart on each request and the
     * limit would never trigger. Stale files are simply reused: the window
     * resets in place once $windowSeconds has elapsed.
     */
    public static function record(string $key, int $max, int $windowSeconds, string $prefix = ''): bool
    {
        $tmp = sys_get_temp_dir() . '/kapture_rl_' . $prefix . md5($key);
        $now = time();

        $fp = fopen($tmp, 'c+');
        if ($fp === false) {
            return true;
        }

        try {
            flock($fp, LOCK_EX);
            // allowed_classes=false: the file path is predictable (md5 of the
            // key), so a pre-seeded file must never instantiate objects.
            $existing = @unserialize(stream_get_contents($fp) ?: '', ['allowed_classes' => false]);
            $window = is_array($existing) && ($existing['reset'] ?? 0) >= $now
                ? $existing
                : ['reset' => $now + $windowSeconds, 'count' => 0];

            $window['count']++;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, serialize($window));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        return $window['count'] <= $max;
    }
}