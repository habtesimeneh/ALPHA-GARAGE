<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — Isolated Test Database Provisioning Script
 * 
 * Safely provisions and resets the dedicated test database (`garage_management_system_test`).
 * STRICTLY GUARDS against touching the production database (`garage_management_system`).
 * 
 * Usage:
 *   php scripts/provision_test_db.php [--no-seed] [--admin-pass=...] [--demo-pass=...]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: scripts/provision_test_db.php must be run from the CLI.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));

// Parse CLI options
$options = getopt('', ['no-seed', 'admin-pass:', 'demo-pass:', 'db-name:']);
$targetDb = (string) ($options['db-name'] ?? getenv('GMS_TEST_DB_NAME') ?: 'garage_management_system_test');
$noSeed = isset($options['no-seed']);
$adminPassword = (string) ($options['admin-pass'] ?? getenv('ADMIN_PASS') ?: 'Admin#TestPass2026');
$demoPassword = (string) ($options['demo-pass'] ?? getenv('DEMO_PASS') ?: 'Demo#TestPass2026');

// =========================================================================
// 1. STRICT SAFETY BOUNDARY CHECKS
// =========================================================================
if ($targetDb === 'garage_management_system') {
    fwrite(STDERR, "\nFATAL SAFETY VIOLATION: Cannot provision production database 'garage_management_system'!\n");
    fwrite(STDERR, "Target database must be a dedicated test database ending with '_test'.\n\n");
    exit(2);
}

if (!str_ends_with($targetDb, '_test')) {
    fwrite(STDERR, "\nFATAL SAFETY VIOLATION: Target database name '$targetDb' does not end with '_test'.\n");
    fwrite(STDERR, "Provisioning aborted to prevent accidental modification of non-test databases.\n\n");
    exit(2);
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $targetDb)) {
    fwrite(STDERR, "\nFATAL ERROR: Target database name contains invalid characters.\n\n");
    exit(2);
}

echo "=================================================================\n";
echo "ALPHA GARAGE — TEST DATABASE PROVISIONING\n";
echo "Target Database: $targetDb\n";
echo "=================================================================\n\n";

// =========================================================================
// 2. ISOLATED TEST STORAGE INITIALIZATION
// =========================================================================
$testStorageDir = APP_ROOT . '/storage/testing';
foreach (['', '/sessions', '/logs', '/rate_limits', '/mail_spool'] as $sub) {
    $dir = $testStorageDir . $sub;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
            fwrite(STDERR, "Error: Failed to create test storage directory: $dir\n");
            exit(1);
        }
    }
}

$gitignorePath = $testStorageDir . '/.gitignore';
if (!file_exists($gitignorePath)) {
    file_put_contents($gitignorePath, "*\n!.gitignore\n");
}
echo "[1/7] Test storage directories initialized at storage/testing/\n";

// =========================================================================
// 3. DATABASE CREATION & CONNECTION VALIDATION
// =========================================================================
$provisionUser = getenv('GMS_PROVISION_DB_USER') ?: 'root';
$provisionPass = getenv('GMS_PROVISION_DB_PASS') ?: 'root';
$host = getenv('GMS_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('GMS_DB_PORT') ?: 3306);

try {
    $serverPdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $provisionUser, $provisionPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    // Create the test database if it does not exist
    $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `$targetDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[2/7] Database `$targetDb` created/verified.\n";

    // Grant privileges to garage_app on the test database
    try {
        $serverPdo->exec("GRANT ALL PRIVILEGES ON `$targetDb`.* TO 'garage_app'@'localhost'");
        $serverPdo->exec("FLUSH PRIVILEGES");
        echo "[3/7] Granted test permissions to 'garage_app'@'localhost' on `$targetDb`.\n";
    } catch (Throwable $e) {
        echo "[3/7] Note: Could not grant to 'garage_app'@'localhost' (continuing): " . $e->getMessage() . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL ERROR: Could not connect to MySQL server with provision credentials: " . $e->getMessage() . "\n");
    exit(1);
}

// Connect directly to the test database
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$targetDb;charset=utf8mb4", $provisionUser, $provisionPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    // STRICT IDENTITY ASSERTION:
    $currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($currentDb !== $targetDb) {
        throw new RuntimeException("CRITICAL SAFETY FAULT: Active database is '$currentDb', expected '$targetDb'!");
    }
    if ($currentDb === 'garage_management_system') {
        throw new RuntimeException("CRITICAL SAFETY FAULT: Connected to production database 'garage_management_system'!");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL ERROR connecting to target database: " . $e->getMessage() . "\n");
    exit(1);
}

// =========================================================================
// 4. CLEAN RESET OF EXISTING TABLES IN TEST DATABASE
// =========================================================================
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$existingTables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '$targetDb'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($existingTables as $tbl) {
    $pdo->exec("DROP TABLE IF EXISTS `$tbl`");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "[4/7] Cleaned test database (dropped " . count($existingTables) . " prior tables).\n";

// =========================================================================
// 5. SAFE SCHEMA IMPORT (SANITIZED AGAINST CONTEXT COLLISION)
// =========================================================================
$schemaFile = APP_ROOT . '/database/database.sql';
if (!is_file($schemaFile)) {
    fwrite(STDERR, "Error: Schema file not found: $schemaFile\n");
    exit(1);
}

/**
 * Statement parser that respects quotes and comments.
 */
function parse_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
                $buffer .= "\n";
            }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $blockComment = false;
                $i++;
                $buffer .= ' ';
            }
            continue;
        }
        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $quote !== '`' && $next !== '') {
                $buffer .= $next;
                $i++;
            } elseif ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($char === '-' && $next === '-' && (($sql[$i + 2] ?? '') === '' || ctype_space($sql[$i + 2]))) {
            $lineComment = true;
            $i++;
        } elseif ($char === '#') {
            $lineComment = true;
        } elseif ($char === '/' && $next === '*') {
            $blockComment = true;
            $i++;
        } elseif ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
        } elseif ($char === ';') {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
        } else {
            $buffer .= $char;
        }
    }

    if ($quote !== null || $blockComment) {
        throw new RuntimeException('SQL script contains an unterminated quoted string or comment.');
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

$rawSql = (string) file_get_contents($schemaFile);
$statements = parse_sql_statements($rawSql);

$executedSchemaCount = 0;
foreach ($statements as $stmt) {
    $trimmed = trim($stmt);
    // Strip database-switching and database-creation statements to prevent context escape
    if (preg_match('/^CREATE\s+DATABASE/i', $trimmed) || preg_match('/^USE\s+/i', $trimmed)) {
        continue;
    }
    $result = $pdo->query($stmt);
    if ($result instanceof PDOStatement) {
        $result->closeCursor();
    }
    $executedSchemaCount++;
}

// STRICT VERIFICATION: Verify database context did not change
$activeCheck = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($activeCheck !== $targetDb) {
    throw new RuntimeException("CRITICAL SAFETY FAULT: Database context escaped during schema execution! Active: $activeCheck");
}
echo "[5/7] Imported base schema ($executedSchemaCount statements executed safely).\n";

// =========================================================================
// 6. SEQUENTIAL MIGRATION APPLICATION
// =========================================================================
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);

$migrationFiles = glob(APP_ROOT . '/database/migrations/*.sql');
sort($migrationFiles, SORT_STRING);
$appliedMigrations = 0;

foreach ($migrationFiles as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    $checksum = hash('sha256', $sql);

    $check = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
    $check->execute([$name]);
    $existing = $check->fetchColumn();
    if ($existing !== false) {
        continue;
    }

    $stmts = parse_sql_statements($sql);
    foreach ($stmts as $st) {
        $result = $pdo->query($st);
        if ($result instanceof PDOStatement) {
            $result->closeCursor();
        }
    }

    $ins = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
    $ins->execute([$name, $checksum]);
    $appliedMigrations++;
}

// Add engine-level immutable payment trigger (matches production least_privilege.sql)
$pdo->exec("DROP TRIGGER IF EXISTS trg_payments_immutable");
$pdo->exec("CREATE TRIGGER trg_payments_immutable BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    IF NOT (
        OLD.amount <=> NEW.amount AND OLD.kind <=> NEW.kind AND OLD.invoice_id <=> NEW.invoice_id
        AND OLD.method <=> NEW.method AND OLD.reference <=> NEW.reference AND OLD.paid_at <=> NEW.paid_at
        AND OLD.notes <=> NEW.notes AND OLD.recorded_by <=> NEW.recorded_by
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'payments are immutable: only receipt_number may be set after insert';
    END IF;
END");

echo "[6/7] Applied $appliedMigrations migrations and established triggers.\n";

// =========================================================================
// 7. SYNTHETIC FIXTURE SEEDING (ADMINISTRATOR + DEMO DATA)
// =========================================================================
if ($noSeed) {
    echo "[7/7] Seeding skipped (--no-seed passed).\n";
} else {
    // Configure environment variables for application services
    putenv("GMS_DB_NAME=$targetDb");
    putenv("GMS_DB_USER=$provisionUser");
    putenv("GMS_DB_PASS=$provisionPass");
    putenv("GMS_TEST_MODE=1");
    putenv("GMS_ALLOW_DESTRUCTIVE_TESTS=1");
    putenv("GMS_STORAGE_PATH=$testStorageDir");

    require_once APP_ROOT . '/app/bootstrap.php';

    // Seed administrator account
    $roleId = (int) Database::value("SELECT id FROM roles WHERE name = 'administrator'");
    if ($roleId < 1) {
        fwrite(STDERR, "Error: Administrator role not found.\n");
        exit(1);
    }

    $adminId = Database::insert(
        'INSERT INTO users (role_id, username, full_name, email, password_hash) VALUES (?,?,?,?,?)',
        [$roleId, 'admin', 'Alpha Test Administrator', 'admin@autopro-garage.example', AuthService::hash($adminPassword)]
    );

    AuditLogger::log('initial_admin_created', 'users', $adminId, null, ['username' => 'admin', 'role' => 'administrator'], 'success',
        ['user_id' => $adminId, 'username' => 'admin', 'role' => 'administrator']);

    // Seed demo fixtures via DemoSeeder
    $_SESSION = [];
    DemoSeeder::run($demoPassword, $adminId);

    // Verify tamper-evident audit chain
    $chainCheck = AuditLogger::verifyChain();
    if (!$chainCheck['ok']) {
        fwrite(STDERR, "CRITICAL ERROR: Seeded audit chain is invalid: {$chainCheck['message']}\n");
        exit(1);
    }

    $tableCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$targetDb'")->fetchColumn();
    $userCount = (int) Database::value("SELECT COUNT(*) FROM users");
    $auditCount = (int) Database::value("SELECT COUNT(*) FROM audit_logs");

    echo "[7/7] Synthetic test fixtures seeded successfully:\n";
    echo "      - Tables: $tableCount\n";
    echo "      - Users: $userCount (Admin: 'admin', Demo: 'reception', 'stock', 'accounts', 'mechanic1', 'mechanic2')\n";
    echo "      - Audit chain: VALID ({$chainCheck['checked']} records verified)\n";
}

echo "\n=================================================================\n";
echo "PROVISIONING COMPLETE: `$targetDb` is ready for testing.\n";
echo "Live database `garage_management_system` remains untouched.\n";
echo "=================================================================\n";
exit(0);
