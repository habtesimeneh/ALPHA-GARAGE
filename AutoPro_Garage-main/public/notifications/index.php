<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();

$notifications = NotificationService::allForUser(100);

ui_header('System Notifications', 'dashboard');
page_header(
    'Notifications & Alerts',
    'Real-time workshop, inventory, and financial notifications',
    '<form method="post" action="' . e(url('notifications/mark_read.php')) . '">' . csrf_field() . '<button class="btn btn-ghost" type="submit">Mark all as read</button></form>'
);
?>

<div class="card card-narrow">
  <ul class="feed">
    <?php if (empty($notifications)): ?>
      <li class="empty feed-empty">No notifications to display.</li>
    <?php else: foreach ($notifications as $n): ?>
      <li class="notification-item-row<?= $n['is_read'] ? '' : ' is-unread' ?>">
        <span class="notification-dot<?= $n['is_read'] ? '' : ' is-unread' ?>"></span>
        <div class="notification-body">
          <div class="notification-head">
            <strong><?= e($n['title']) ?></strong>
            <small class="muted"><?= fmt_datetime($n['created_at']) ?></small>
          </div>
          <p class="notification-message"><?= e($n['message']) ?></p>
          <?php if (!empty($n['link'])): ?>
            <a class="btn btn-ghost btn-sm mt-2" href="<?= e(url($n['link'])) ?>">Open Related Record ›</a>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; endif; ?>
  </ul>
</div>

<?php ui_footer();
