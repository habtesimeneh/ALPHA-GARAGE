<?php
/**
 * AutoPro Garage Management System
 * Production CLI Administrator Provisioning Utility
 *
 * Usage:
 *   php scripts/create_admin.php --username=admin --email=admin@alphagarage.et --name="System Administrator" --password="Ex4mple#Complex$Secret99"
 * Or interactively:
 *   php scripts/create_admin.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    fwrite(STDERR, "Error: CLI access only.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/helpers/functions.php';
require_once APP_ROOT . '/app/models/Database.php';

// Parse CLI options
$options = getopt('', ['username:', 'email:', 'name:', 'password:']);

$username = $options['username'] ?? null;
$email    = $options['email'] ?? null;
$name     = $options['name'] ?? null;
$password = $options['password'] ?? null;

// Helper to prompt interactively if running in an interactive terminal
function promptInput(string $prompt): string {
    echo $prompt . ": ";
    $line = fgets(STDIN);
    return trim($line ?: '');
}

if (!$username) {
    $username = promptInput("Enter Administrator Username (min 4 chars)");
}
if (!$email) {
    $email = promptInput("Enter Administrator Email");
}
if (!$name) {
    $name = promptInput("Enter Full Name");
}
if (!$password) {
    $password = promptInput("Enter Master Password (min 10 chars, uppercase, lowercase, number, symbol)");
}

// 1. Validation
if (strlen($username) < 4 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
    fwrite(STDERR, "Error: Username must be at least 4 alphanumeric characters (dots/dashes/underscores allowed).\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: Invalid email format: $email\n");
    exit(1);
}

if (strlen($name) < 2) {
    fwrite(STDERR, "Error: Full name is required.\n");
    exit(1);
}

// NIST Password validation
$hasUpper = preg_match('/[A-Z]/', $password);
$hasLower = preg_match('/[a-z]/', $password);
$hasDigit = preg_match('/[0-9]/', $password);
$hasSpecial = preg_match('/[^A-Za-z0-9]/', $password);

if (strlen($password) < 10 || !$hasUpper || !$hasLower || !$hasDigit || !$hasSpecial) {
    fwrite(STDERR, "Error: Password must be at least 10 characters and contain uppercase, lowercase, digit, and special character.\n");
    exit(1);
}

try {
    $dbConfig = require APP_ROOT . '/app/config/database.php';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['name'], $dbConfig['charset']);
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET time_zone = ' . $pdo->quote($dbConfig['tz']));

    // 2. Check if username or email already exists
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['username'] === $username) {
            fwrite(STDERR, "Error: An account with username '$username' already exists (User ID: {$existing['id']}).\n");
        } else {
            fwrite(STDERR, "Error: An account with email '$email' already exists (User ID: {$existing['id']}).\n");
        }
        exit(1);
    }

    // 3. Find administrator role ID
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name IN ('administrator', 'super_admin') ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $roleId = $stmt->fetchColumn();
    if (!$roleId) {
        fwrite(STDERR, "Error: Could not locate 'administrator' or 'super_admin' role in database.\n");
        exit(1);
    }

    // 4. Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // 5. Insert Administrator
    $stmt = $pdo->prepare("
        INSERT INTO users (role_id, username, full_name, email, password_hash, is_active, must_change_password, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())
    ");
    $stmt->execute([
        $roleId,
        $username,
        $name,
        $email,
        $passwordHash
    ]);
    $newUserId = (int)$pdo->lastInsertId();

    echo "========================================================\n";
    echo "SUCCESS: Administrator provisioned successfully!\n";
    echo "========================================================\n";
    echo "  User ID:   $newUserId\n";
    echo "  Username:  $username\n";
    echo "  Email:     $email\n";
    echo "  Full Name: $name\n";
    echo "  Role ID:   $roleId (Administrator)\n";
    echo "  Active:    Yes\n";
    echo "========================================================\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Fatal Error: " . $e->getMessage() . "\n");
    exit(1);
}
