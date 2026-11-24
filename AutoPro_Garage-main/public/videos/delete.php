<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — DELETE VIDEO ACTION
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
    VideoService::delete($id, (int) $user['id']);
    flash('success', t('Video deleted successfully.', 'Video deleted successfully.'));
}

redirect('videos/index.php');
