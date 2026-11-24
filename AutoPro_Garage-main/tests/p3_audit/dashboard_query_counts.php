<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

echo "=================================================================\n";
echo "QUERY COUNT PER DASHBOARD (PRE-OPTIMIZATION)\n";
echo "=================================================================\n\n";

$roles = [
    'admin' => ['view' => 'admin', 'role' => 'administrator'],
    'reception' => ['view' => 'receptionist', 'role' => 'receptionist'],
    'mechanic1' => ['view' => 'mechanic', 'role' => 'mechanic'],
    'stock' => ['view' => 'inventory', 'role' => 'inventory_manager'],
    'accounts' => ['view' => 'accountant', 'role' => 'accountant'],
    'test_cust_alice' => ['view' => 'customer', 'role' => 'customer']
];

// Query counter using MySQL general query log or statement counter
$pdo = Database::pdo();

foreach ($roles as $username => $info) {
    $viewName = $info['view'];
    $roleName = $info['role'];
    
    $user = Database::one("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = ?", [$username]);
    if ($roleName === 'mechanic') {
        $mech = Database::one("SELECT id FROM mechanics WHERE user_id = ? OR id = 1 LIMIT 1", [$user['id']]);
        $user['mechanic_id'] = $mech ? (int) $mech['id'] : 1;
    }
    
    $_SESSION['uid'] = $user['id'];
    $GLOBALS['user'] = $user;
    
    // Read status variable Questions before
    $qBefore = (int) $pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    
    ob_start();
    $viewFile = APP_ROOT . '/app/views/dashboards/' . $viewName . '.php';
    require $viewFile;
    $html = ob_get_clean();
    
    $qAfter = (int) $pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    // subtract the SHOW STATUS query itself
    $queryCount = max(0, $qAfter - $qBefore - 1);
    
    echo sprintf("%-16s | %-18s | Query Count: %2d queries\n", $username, $roleName, $queryCount);
}
echo "\n";
