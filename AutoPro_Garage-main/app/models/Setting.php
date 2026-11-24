<?php
declare(strict_types=1);

/** Garage settings stored in the garage_settings table (cached per request). */
final class Setting
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Database::all('SELECT setting_key, setting_value FROM garage_settings') as $r) {
                    self::$cache[$r['setting_key']] = $r['setting_value'];
                }
            } catch (Throwable) {
                // Return defaults if database is not yet ready
            }
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, string $value, ?int $userId): void
    {
        Database::run(
            'INSERT INTO garage_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, $value, $userId]
        );
        self::$cache = null;
    }

    /** @return string[] */
    public static function paymentMethods(): array
    {
        $m = array_filter(array_map('trim', explode(',', self::get('payment_methods', 'Cash,Bank Transfer'))));
        try {
            $dbMethods = Database::all('SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY display_order ASC, name ASC');
            foreach ($dbMethods as $row) {
                $m[] = $row['name'];
                $m[] = $row['code'];
            }
        } catch (Throwable $e) {
            // Fallback to settings
        }
        return array_values(array_unique($m)) ?: ['Cash'];
    }
}
