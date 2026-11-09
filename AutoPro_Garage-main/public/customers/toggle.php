<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_post();
require_permission('customers.deactivate');

$id = post_int('id'); $action = post('action');
if (!in_array($action, ['activate', 'deactivate'], true)) { abort(400, 'Invalid action.'); }
try {
    Database::transaction(function () use ($id, $action) {
        $c = Database::one('SELECT id, full_name, is_active FROM customers WHERE id = ? FOR UPDATE', [$id]);
        if (!$c) { throw new DomainException('Customer not found.'); }
        if ($action === 'deactivate') {
            $open = (int) Database::value("SELECT COUNT(*) FROM job_cards WHERE customer_id = ? AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress','Completed')", [$id]);
            if ($open > 0) { throw new DomainException("This customer still has $open open job card(s). Finish or cancel them first."); }
        }
        $to = $action === 'activate' ? 1 : 0;
        Database::run('UPDATE customers SET is_active = ? WHERE id = ?', [$to, $id]);
        AuditLogger::log('customer_' . $action . 'd', 'customers', $id, ['is_active' => (int) $c['is_active']], ['is_active' => $to]);
    });
    flash('success', $action === 'activate' ? 'Customer reactivated.' : 'Customer deactivated. History has been retained.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('customers/view.php?id=' . $id);
