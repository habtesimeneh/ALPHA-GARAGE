<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
$user = require_login();
require_post();
require_permission('inventory.manage');

$id = post_int('id');
if (!$id) {
    abort(400, t('Invalid part ID.', 'Invalid part ID.'));
}

try {
    $res = InventoryService::deletePart($id);
    if ($res['deleted']) {
        flash('success', t('Part successfully removed from catalog.', 'Part successfully removed from catalog.'));
    } else {
        flash('warning', t('Part has transaction history and was deactivated instead of permanently deleted to preserve audit records.', 'Part has transaction history and was deactivated instead of permanently deleted to preserve audit records.'));
    }
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}

redirect('inventory/');
