<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — ROLE-BASED DASHBOARD DISPATCHER
 * Strictly dispatches authenticated users to their authorized role-specific dashboard.
 * Evaluates authenticated database role (server-side only, never from client input).
 */

require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

$user = require_login();

switch ($user['role_name']) {
    case 'administrator':
    case 'super_admin': // Legacy staging compatibility alias
        require_permission('dashboard.view');
        require APP_ROOT . '/app/views/dashboards/admin.php';
        break;

    case 'receptionist':
        require_permission('dashboard.view');
        require APP_ROOT . '/app/views/dashboards/receptionist.php';
        break;

    case 'mechanic':
        require_permission('dashboard.view');
        require APP_ROOT . '/app/views/dashboards/mechanic.php';
        break;

    case 'inventory_manager':
        require_permission('dashboard.view');
        require APP_ROOT . '/app/views/dashboards/inventory.php';
        break;

    case 'accountant':
        require_permission('dashboard.view');
        require APP_ROOT . '/app/views/dashboards/accountant.php';
        break;

    case 'customer':
        redirect('customer.php');
        break;

    default:
        abort(403, 'Unauthorized dashboard access for role: ' . e($user['role_name']));
}
