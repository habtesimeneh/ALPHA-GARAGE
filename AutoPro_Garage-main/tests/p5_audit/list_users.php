<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

$users = Database::all("SELECT u.username, r.name as role FROM users u JOIN roles r ON r.id = u.role_id");
foreach ($users as $u) {
    echo "  " . $u['username'] . " -> " . $u['role'] . "\n";
}
