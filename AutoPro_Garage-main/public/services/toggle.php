<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_permission('services.manage');
require_post();

$id = post_int('id');
if (!$id) { abort(400, 'Service ID is required.'); }

try {
    $active = CatalogService::toggleActive($id);
    flash('success', $active ? 'Service has been reactivated.' : 'Service has been deactivated.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('services/');

