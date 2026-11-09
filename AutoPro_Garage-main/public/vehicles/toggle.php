<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_post();
require_permission('vehicles.manage');

$id = post_int('id'); $action = post('action');
if (!in_array($action, ['activate', 'deactivate'], true)) { abort(400, 'Invalid action.'); }
try {
    Database::transaction(function () use ($id, $action) {
        $v = Database::one('SELECT id, plate_number, is_active FROM vehicles WHERE id = ? FOR UPDATE', [$id]);
        if (!$v) { throw new DomainException('Vehicle not found.'); }
        if ($action === 'deactivate' && (int) Database::value("SELECT COUNT(*) FROM job_cards WHERE vehicle_id = ? AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress','Completed')", [$id]) > 0) {
            throw new DomainException('This vehicle still has open job cards.');
        }
        $to = $action === 'activate' ? 1 : 0;
        Database::run('UPDATE vehicles SET is_active = ? WHERE id = ?', [$to, $id]);
        AuditLogger::log('vehicle_' . $action . 'd', 'vehicles', $id, ['is_active' => (int) $v['is_active']], ['is_active' => $to, 'plate' => $v['plate_number']]);
    });
    flash('success', $action === 'activate' ? 'Vehicle reactivated.' : 'Vehicle deactivated.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('vehicles/view.php?id=' . $id);
