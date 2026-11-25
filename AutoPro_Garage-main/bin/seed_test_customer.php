<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function seedInDb(string $dbName) {
    echo "Seeding in $dbName...\n";
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$dbName;charset=utf8mb4", 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'customer'");
    $stmt->execute();
    $custRoleId = (int) $stmt->fetchColumn();
    if (!$custRoleId) {
        $pdo->exec("INSERT INTO roles (name, label, description) VALUES ('customer', 'Customer', 'Public website account without staff dashboard access')");
        $custRoleId = (int) $pdo->lastInsertId();
    }

    $passHash = password_hash('Customer#Pass1', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'test_cust_alice'");
    $stmt->execute();
    $alice = $stmt->fetch();

    if (!$alice) {
        $stmt = $pdo->prepare("INSERT INTO users (role_id, username, full_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$custRoleId, 'test_cust_alice', 'Alice Customer', 'alice@customer.example', '+251911112233', $passHash]);
        $aliceId = (int) $pdo->lastInsertId();
    } else {
        $aliceId = (int) $alice['id'];
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?");
        $stmt->execute([$passHash, $aliceId]);
    }

    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? OR created_by = ?");
    $stmt->execute([$aliceId, $aliceId]);
    $cust = $stmt->fetch();

    if (!$cust) {
        $stmt = $pdo->prepare("INSERT INTO customers (full_name, phone, email, address, created_by, user_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute(['Alice Customer', '+251911112233', 'alice@customer.example', 'Bole Atlas, Addis Ababa', $aliceId, $aliceId]);
        $custId = (int) $pdo->lastInsertId();
    } else {
        $custId = (int) $cust['id'];
        $stmt = $pdo->prepare("UPDATE customers SET user_id = ?, is_active = 1 WHERE id = ?");
        $stmt->execute([$aliceId, $custId]);
    }

    $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE customer_id = ?");
    $stmt->execute([$custId]);
    $veh = $stmt->fetch();

    if (!$veh) {
        $stmt = $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number, make, model, year, fuel_type, is_active) VALUES (?, 'ALICE-01', 'Audi', 'A6', 2023, 'Petrol', 1)");
        $stmt->execute([$custId]);
    }

    echo "Successfully ensured test_cust_alice in $dbName (User ID: $aliceId, Customer ID: $custId)\n";
}

seedInDb('garage_management_system');
seedInDb('garage_management_system_test');
