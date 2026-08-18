<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class RateLimiter
{
    /**
     * Record one attempt for $key and return whether it stays within $max
     * over a $windowSeconds window. Atomic via flock(); fail-open on
     * temp-file errors so captures are never blocked by a full /tmp.
     */
    public static function record(string $key, int $max, int $windowSeconds, string $prefix = ''): bool
    {
        $tmp = sys_get_temp_dir() . '/kapture_rl_' . $prefix . md5($key);
        $now = time();

        $fp = fopen($tmp, 'c+');
        if ($fp === false) {
            return true;
        }

        $expired = false;
        try {
            flock($fp, LOCK_EX);
            // allowed_classes=false: the file path is predictable (md5 of the
            // key), so a pre-seeded file must never instantiate objects.
            $window = @unserialize(stream_get_contents($fp) ?: '', ['allowed_classes' => false]);
            if (!is_array($window) || ($window['reset'] ?? 0) < $now) {
                $window = ['reset' => $now + $windowSeconds, 'count' => 0];
                $expired = true;
            }

            $window['count']++;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, serialize($window));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        // An expired window is recreated on the next request anyway — drop the
        // stale file so /tmp does not accumulate one file per client.
        if ($expired) {
            @unlink($tmp);
        }

        return $window['count'] <= $max;
    }
}