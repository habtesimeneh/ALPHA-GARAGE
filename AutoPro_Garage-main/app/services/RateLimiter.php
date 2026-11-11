<?php
declare(strict_types=1);

/**
 * Minimal file-based, per-key fixed-window rate limiter.
 * Keys are hashed before touching the filesystem, so user-supplied values (IPs,
 * usernames) never become file names. Fails open and logs if storage is unavailable,
 * so a broken rate limiter cannot take the public site down.
 */
final class RateLimiter
{
    private const DIR = 'rate_limits';

    /** @return array{allowed: bool, remaining: int, retry_after: int} */
    public static function check(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $maxAttempts   = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $now           = time();

        try {
            $dir = storage_path('rate_limits');
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create the rate-limit directory.');
            }

            $fh = @fopen($dir . '/' . hash('sha256', $key) . '.json', 'c+');
            if ($fh === false) {
                throw new RuntimeException('Cannot open a rate-limit file.');
            }

            try {
                if (!flock($fh, LOCK_EX)) {
                    throw new RuntimeException('Cannot lock a rate-limit file.');
                }

                $raw   = stream_get_contents($fh);
                $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                if (
                    !is_array($state)
                    || !isset($state['start'], $state['count'])
                    || !is_int($state['start'])
                    || !is_int($state['count'])
                    || ($now - $state['start']) >= $windowSeconds
                ) {
                    $state = ['start' => $now, 'count' => 0];
                }

                $state['count']++;
                $allowed    = $state['count'] <= $maxAttempts;
                $retryAfter = $allowed ? 0 : max(1, $windowSeconds - ($now - $state['start']));

                rewind($fh);
                ftruncate($fh, 0);
                fwrite($fh, (string) json_encode($state));
                fflush($fh);

                return [
                    'allowed'     => $allowed,
                    'remaining'   => max(0, $maxAttempts - $state['count']),
                    'retry_after' => $retryAfter,
                ];
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        } catch (Throwable $e) {
            app_log('Rate limiter unavailable for key hash ' . substr(hash('sha256', $key), 0, 16) . ': ' . $e->getMessage());
            return ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after' => 0];
        }
    }
}
