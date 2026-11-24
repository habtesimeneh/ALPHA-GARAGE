<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

$roles = Database::all("SELECT id, name, label FROM roles ORDER BY id ASC");
$perms = Database::all("SELECT id, code, label, module FROM permissions ORDER BY module, code ASC");
$rolePerms = Database::all("SELECT role_id, permission_id FROM role_permissions");

$map = [];
foreach ($rolePerms as $rp) {
    $map[$rp['role_id']][$rp['permission_id']] = true;
}

echo "=================================================================\n";
echo "ALPHA GARAGE — RBAC PERMISSION MATRIX AUDIT\n";
echo "=================================================================\n\n";

printf("%-32s | %-5s | %-5s | %-5s | %-5s | %-5s | %-5s\n", "Permission Code", "Admin", "Recep", "Mech", "Inven", "Acct", "Cust");
echo str_repeat("-", 80) . "\n";

foreach ($perms as $p) {
    $admin = isset($map[1][$p['id']]) ? "  X  " : "  -  ";
    $recep = isset($map[2][$p['id']]) ? "  X  " : "  -  ";
    $mech  = isset($map[3][$p['id']]) ? "  X  " : "  -  ";
    $inven = isset($map[4][$p['id']]) ? "  X  " : "  -  ";
    $acct  = isset($map[5][$p['id']]) ? "  X  " : "  -  ";
    $cust  = isset($map[6][$p['id']]) ? "  X  " : "  -  ";
    printf("%-32s | %s | %s | %s | %s | %s | %s\n", $p['code'], $admin, $recep, $mech, $inven, $acct, $cust);
}
