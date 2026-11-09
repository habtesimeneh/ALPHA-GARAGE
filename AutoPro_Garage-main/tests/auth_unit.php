<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/helpers/functions.php';
require APP_ROOT . '/app/services/AuthService.php';

$GLOBALS['config'] = ['security' => ['min_password_len' => 10]];

function auth_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$password = 'Unit#TestPassword2026';
$hash = AuthService::hash($password);
auth_check($hash !== $password, 'Passwords must never be stored as plaintext.');
auth_check(password_verify($password, $hash), 'A hashed password must verify.');
auth_check(!password_verify('wrong-password', $hash), 'A wrong password must fail verification.');
auth_check(AuthService::passwordProblems($password, 'garage-admin') === [], 'A valid password should satisfy policy.');
auth_check(AuthService::passwordProblems('weak', 'garage-admin') !== [], 'A weak password should fail policy.');

$token = bin2hex(random_bytes(32));
$now = time();
auth_check(csrf_token_is_valid($token, $token, $now, $now), 'A matching CSRF token should pass.');
auth_check(!csrf_token_is_valid('', $token, $now, $now), 'A missing CSRF token should fail.');
auth_check(!csrf_token_is_valid(str_repeat('0', 64), $token, $now, $now), 'A forged CSRF token should fail.');
auth_check(!csrf_token_is_valid($token, $token, $now - 7201, $now), 'An expired CSRF token should fail.');

foreach ([
    'GMS_DB_HOST' => '127.0.0.1',
    'GMS_DB_PORT' => '3306',
    'GMS_DB_NAME' => 'garage_auth_test',
    'GMS_DB_USER' => 'unit_test_user',
    'GMS_DB_PASS' => 'unit-test-only-placeholder',
] as $name => $value) {
    putenv($name . '=' . $value);
}
$dbConfig = require APP_ROOT . '/app/config/database.php';
auth_check($dbConfig['host'] === '127.0.0.1' && $dbConfig['port'] === 3306, 'Database environment settings should be parsed.');
auth_check($dbConfig['name'] === 'garage_auth_test' && $dbConfig['user'] === 'unit_test_user', 'Database name and user should be parsed.');

echo "Authentication unit tests passed.\n";
