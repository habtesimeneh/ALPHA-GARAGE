<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

echo "=================================================================\n";
echo "ALPHA GARAGE — DASHBOARD LOAD BENCHMARKS (PRE-OPTIMIZATION)\n";
echo "=================================================================\n\n";

$roles = [
    'admin' => ['view' => 'admin', 'role' => 'administrator'],
    'reception' => ['view' => 'receptionist', 'role' => 'receptionist'],
    'mechanic1' => ['view' => 'mechanic', 'role' => 'mechanic'],
    'stock' => ['view' => 'inventory', 'role' => 'inventory_manager'],
    'accounts' => ['view' => 'accountant', 'role' => 'accountant'],
    'test_cust_alice' => ['view' => 'customer', 'role' => 'customer']
];

foreach ($roles as $username => $info) {
    $viewName = $info['view'];
    $roleName = $info['role'];
    
    // Resolve user from DB
    $user = Database::one("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = ?", [$username]);
    if (!$user) {
        echo "User $username not found!\n";
        continue;
    }
    
    // Attach mechanic profile if mechanic
    if ($roleName === 'mechanic') {
        $mech = Database::one("SELECT id FROM mechanics WHERE user_id = ? OR id = 1 LIMIT 1", [$user['id']]);
        $user['mechanic_id'] = $mech ? (int) $mech['id'] : 1;
    }
    
    $_SESSION['uid'] = $user['id'];
    $GLOBALS['user'] = $user;
    
    // Measure response times
    $times = [];
    $outputSizes = [];
    
    for ($run = 0; $run < 5; $run++) {
        $t0 = microtime(true);
        ob_start();
        $viewFile = APP_ROOT . '/app/views/dashboards/' . $viewName . '.php';
        require $viewFile;
        $html = ob_get_clean();
        $t = (microtime(true) - $t0) * 1000;
        $times[] = $t;
        $outputSizes[] = strlen($html);
    }
    
    $avgTime = array_sum($times) / count($times);
    $minTime = min($times);
    $maxTime = max($times);
    $avgSize = array_sum($outputSizes) / count($outputSizes);
    
    echo sprintf(
        "%-16s | %-17s | Avg: %6.2f ms | Min: %6.2f ms | Max: %6.2f ms | HTML: %5.1f KB\n",
        $username,
        $roleName,
        $avgTime,
        $minTime,
        $maxTime,
        $avgSize / 1024
    );
}
echo "\n";
