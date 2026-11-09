<?php
declare(strict_types=1);

/**
 * In-App Notification & Alert Service.
 * Delivers alerts to users and roles for new job cards, assignments, low stock, and payments.
 */
final class NotificationService
{
    public static function create(?int $userId, ?string $roleName, string $title, string $message, string $link = ''): int
    {
        return Database::insert(
            'INSERT INTO notifications (user_id, role_name, title, message, link, is_read)
             VALUES (?, ?, ?, ?, ?, 0)',
            [$userId ?: null, $roleName ?: null, $title, $message, $link ?: null]
        );
    }

    public static function broadcast(string $title, string $message, string $link = '', ?string $roleName = null): void
    {
        self::create(null, $roleName, $title, $message, $link);
    }

    public static function notifyUser(int $userId, string $title, string $message, string $link = ''): void
    {
        self::create($userId, null, $title, $message, $link);
    }

    public static function unreadForCurrentUser(): array
    {
        $u = current_user();
        if (!$u) {
            return [];
        }

        $userId = (int) $u['id'];
        $roleName = $u['role_name'] ?? '';

        return Database::all(
            'SELECT * FROM notifications 
             WHERE (user_id = ? OR role_name = ? OR (user_id IS NULL AND role_name IS NULL))
               AND is_read = 0
             ORDER BY id DESC LIMIT 10',
            [$userId, $roleName]
        );
    }

    public static function unreadCount(): int
    {
        $u = current_user();
        if (!$u) {
            return 0;
        }

        $userId = (int) $u['id'];
        $roleName = $u['role_name'] ?? '';

        return (int) Database::value(
            'SELECT COUNT(*) FROM notifications 
             WHERE (user_id = ? OR role_name = ? OR (user_id IS NULL AND role_name IS NULL))
               AND is_read = 0',
            [$userId, $roleName]
        );
    }

    public static function markAllRead(): void
    {
        $u = current_user();
        if (!$u) {
            return;
        }

        $userId = (int) $u['id'];
        $roleName = $u['role_name'] ?? '';

        Database::run(
            'UPDATE notifications SET is_read = 1 
             WHERE (user_id = ? OR role_name = ? OR (user_id IS NULL AND role_name IS NULL))',
            [$userId, $roleName]
        );
    }

    public static function markRead(int $id): void
    {
        Database::run('UPDATE notifications SET is_read = 1 WHERE id = ?', [$id]);
    }

    public static function allForUser(int $limit = 50): array
    {
        $u = current_user();
        if (!$u) {
            return [];
        }

        $userId = (int) $u['id'];
        $roleName = $u['role_name'] ?? '';

        return Database::all(
            'SELECT * FROM notifications 
             WHERE (user_id = ? OR role_name = ? OR (user_id IS NULL AND role_name IS NULL))
             ORDER BY id DESC LIMIT ?',
            [$userId, $roleName, $limit]
        );
    }
}

