<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

echo "=================================================================\n";
echo "ALPHA GARAGE — PRIORITY 5 DASHBOARD PERFORMANCE DIAGNOSTICS\n";
echo "Database: " . Database::value("SELECT DATABASE()") . "\n";
echo "=================================================================\n\n";

$roles = [
    'admin' => 'admin',
    'receptionist' => 'receptionist',
    'mechanic' => 'mechanic1',
    'inventory' => 'inventory',
    'accountant' => 'accountant',
    'customer' => 'test_cust_alice',
];

$pdo = Database::pdo();

foreach ($roles as $roleKey => $username) {
    $user = Database::one("SELECT id, username, role_id FROM users WHERE username = ?", [$username]);
    if (!$user) {
        echo "User {$username} ({$roleKey}) not found!\n";
        continue;
    }
    
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['started_at'] = time();
    $_SESSION['ua'] = hash('sha256', user_agent());
    
    $pdo->query("SET profiling = 0");
    $pdo->query("SET profiling = 1");
    
    $memBefore = memory_get_usage(true);
    $timeStart = microtime(true);
    
    ob_start();
    if ($roleKey === 'customer') {
        require __DIR__ . '/../../public/customer.php';
    } else {
        require __DIR__ . '/../../public/dashboard.php';
    }
    $renderedHtml = ob_get_clean();
    $timeElapsed = (microtime(true) - $timeStart) * 1000;
    $memPeak = memory_get_peak_usage(true);
    
    $profiles = $pdo->query("SHOW PROFILES")->fetchAll(PDO::FETCH_ASSOC);
    $queryCount = count($profiles);
    $dbDuration = 0.0;
    foreach ($profiles as $p) {
        $dbDuration += (float) ($p['Duration'] ?? 0);
    }
    $dbDurationMs = $dbDuration * 1000;
    
    printf("Role: %-18s | Queries: %2d | DB Time: %6.2f ms | PHP Exec: %6.2f ms | Peak Mem: %5.2f MB | HTML: %6d bytes\n",
        ucfirst($roleKey) . " ({$username})",
        $queryCount,
        $dbDurationMs,
        $timeElapsed,
        $memPeak / (1024 * 1024),
        strlen($renderedHtml)
    );
}

$pdo->query("SET profiling = 0");
unset($_SESSION['uid']);
