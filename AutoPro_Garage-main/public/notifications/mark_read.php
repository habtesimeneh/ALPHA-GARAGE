<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_post();

$id = post_int('id');
if ($id) {
    NotificationService::markRead($id);
} else {
    NotificationService::markAllRead();
}

flash('success', 'Notifications marked as read.');
redirect('notifications/');

