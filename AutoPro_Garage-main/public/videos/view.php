<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — VIDEO VIEW TRACKER & DIRECT PLAYER REDIRECT
 */

require __DIR__ . '/../../app/bootstrap.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id > 0) {
    VideoService::incrementViews($id);
}

if (is_post()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

redirect('videos.php?play=' . $id);
