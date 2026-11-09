<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

define('APP_ROOT', dirname(__DIR__));

try {
    $config = require APP_ROOT . '/app/config/database.php';
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['name'], $config['charset']),
        $config['user'],
        $config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec('SET time_zone = ' . $pdo->quote($config['tz']));

    $requiredColumns = [
        'users' => ['id', 'role_id', 'username', 'email', 'password_hash', 'is_active', 'must_change_password'],
        'roles' => ['id', 'name', 'label'],
        'permissions' => ['id', 'code'],
        'role_permissions' => ['role_id', 'permission_id'],
        'login_attempts' => ['id', 'username', 'ip_address', 'success', 'attempted_at'],
    ];
    $columnQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?'
    );
    $missing = [];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnQuery->execute([$config['name'], $table, $column]);
            if ((int) $columnQuery->fetchColumn() !== 1) {
                $missing[] = "$table.$column";
            }
        }
    }

    $requiredIndexes = [
        'users' => ['PRIMARY' => ['id'], 'username' => ['username'], 'email' => ['email']],
        'roles' => ['PRIMARY' => ['id'], 'name' => ['name']],
        'permissions' => ['PRIMARY' => ['id'], 'code' => ['code']],
        'role_permissions' => ['PRIMARY' => ['role_id', 'permission_id']],
        'login_attempts' => ['PRIMARY' => ['id'], 'idx_la_user' => ['username', 'attempted_at'], 'idx_la_ip' => ['ip_address', 'attempted_at']],
    ];
    $indexQuery = $pdo->prepare(
        'SELECT column_name FROM information_schema.statistics
         WHERE table_schema = ? AND table_name = ? AND index_name = ?
         ORDER BY seq_in_index'
    );
    foreach ($requiredIndexes as $table => $indexes) {
        foreach ($indexes as $index => $expectedColumns) {
            $indexQuery->execute([$config['name'], $table, $index]);
            $actualColumns = array_column($indexQuery->fetchAll(), 'column_name');
            if ($actualColumns !== $expectedColumns) {
                $missing[] = "$table.$index index";
            }
        }
    }

    $requiredForeignKeys = [
        ['users', 'role_id', 'roles', 'id'],
        ['role_permissions', 'role_id', 'roles', 'id'],
        ['role_permissions', 'permission_id', 'permissions', 'id'],
    ];
    $foreignKeyQuery = $pdo->prepare(
        'SELECT referenced_table_name, referenced_column_name
         FROM information_schema.key_column_usage
         WHERE table_schema = ? AND table_name = ? AND column_name = ?
           AND referenced_table_name IS NOT NULL'
    );
    foreach ($requiredForeignKeys as [$table, $column, $referencedTable, $referencedColumn]) {
        $foreignKeyQuery->execute([$config['name'], $table, $column]);
        $reference = $foreignKeyQuery->fetch();
        if (!$reference
            || $reference['referenced_table_name'] !== $referencedTable
            || $reference['referenced_column_name'] !== $referencedColumn) {
            $missing[] = "$table.$column foreign key";
        }
    }

    $attemptsLengthQuery = $pdo->prepare(
        'SELECT character_maximum_length FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?'
    );
    $attemptsLengthQuery->execute([$config['name'], 'login_attempts', 'username']);
    if ((int) $attemptsLengthQuery->fetchColumn() < 150) {
        $missing[] = 'login_attempts.username length >= 150 (apply forward migration)';
    }

    if ($missing) {
        fwrite(STDERR, "Missing or inconsistent authentication schema elements: " . implode(', ', $missing) . PHP_EOL);
        exit(3);
    }

    $roleQuery = $pdo->query(
        "SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.id = u.role_id
         WHERE r.id IS NULL"
    );
    $orphanedRoles = (int) $roleQuery->fetchColumn();
    $adminQuery = $pdo->query(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.name = 'administrator' AND u.is_active = 1"
    );
    $activeAdmins = (int) $adminQuery->fetchColumn();

    echo "Database connection and read-only PDO checks: OK\n";
    echo "Required authentication tables/columns: OK\n";
    echo "Users with missing roles: $orphanedRoles\n";
    echo "Active administrator accounts: $activeAdmins\n";
    if ($orphanedRoles > 0) {
        fwrite(STDERR, "Role relationship integrity check failed.\n");
        exit(4);
    }
} catch (PDOException $e) {
    $state = (string) $e->getCode();
    fwrite(STDERR, "Database verification failed (SQLSTATE $state). Credentials and SQL details were not displayed.\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database verification could not run: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
