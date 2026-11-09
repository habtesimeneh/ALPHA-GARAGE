<?php
declare(strict_types=1);

/** Race-free document numbers: insert with a throw-away value, then derive the number from the auto-increment id. */
final class Numbering
{
    public static function placeholder(): string
    {
        return 'TMP' . bin2hex(random_bytes(8));
    }

    public static function make(string $prefix, int $id): string
    {
        return sprintf('%s-%s-%05d', $prefix, date('Y'), $id);
    }
}
