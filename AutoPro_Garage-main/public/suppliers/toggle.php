<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_permission('suppliers.manage');
require_post();

$id = post_int('id');
if (!$id) { abort(400, 'Supplier ID is required.'); }

try {
    $active = SupplierService::toggleActive($id);
    flash('success', $active ? 'Supplier has been reactivated.' : 'Supplier has been deactivated.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('suppliers/');

