<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CUSTOMER PORTAL DASHBOARD CONTROLLER
 * Serves the VIP Client Services Portal for registered vehicle owners.
 * Complete data isolation and strict client-side scoping.
 */

require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

$user = require_login();

// Strictly restrict customer portal to customer role and administrator (for preview/support)
if ($user['role_name'] !== 'customer' && $user['role_name'] !== 'administrator') {
    abort(403, 'Customer portal is exclusively for registered vehicle owners.');
}

require APP_ROOT . '/app/views/dashboards/customer.php';
