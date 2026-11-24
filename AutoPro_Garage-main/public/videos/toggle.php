<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — TOGGLE VIDEO PUBLICATION ACTION
 */

require __DIR__ . '/../../app/bootstrap.php';

$user = require_login();
require_permission('videos.manage');

if (!is_post()) {
    redirect('videos/index.php');
}

verify_csrf();

$id = (int) post('id');
if ($id > 0) {
    VideoService::togglePublish($id, (int) $user['id']);
    flash('success', t('Video publication status updated.', 'Video publication status updated.'));
}

redirect('videos/index.php');
