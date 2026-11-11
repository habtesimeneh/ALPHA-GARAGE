<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/helpers/functions.php';
require_once APP_ROOT . '/app/models/Database.php';

/**
 * Split the project's SQL migration files into statements without splitting
 * semicolons inside quoted strings or SQL comments.
 *
 * @return list<string>
 */
function migration_statements(string $sql): array
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
        throw new RuntimeException('An SQL migration contains an unterminated quoted string or comment.');
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

try {
    $db = require APP_ROOT . '/app/config/database.php';
    $migrationUser = getenv('GMS_MIGRATION_DB_USER');
    $migrationPassword = getenv('GMS_MIGRATION_DB_PASS');
    if ($migrationUser === false || $migrationUser === '' || $migrationPassword === false || $migrationPassword === '') {
        throw new RuntimeException('GMS_MIGRATION_DB_USER and GMS_MIGRATION_DB_PASS are required for migrations.');
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
    $pdo = new PDO($dsn, $migrationUser, $migrationPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec('SET time_zone = ' . $pdo->quote($db['tz']));
    $hasBaseSchema = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
    )->fetchColumn();
    if ($hasBaseSchema !== 1) {
        throw new RuntimeException('Base schema is missing. Import database/database.sql into an empty database first.');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(255) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $files = glob(APP_ROOT . '/database/migrations/*.sql');
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $name = basename($file);
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Cannot read migration: ' . $name);
        }
        $checksum = hash('sha256', $sql);
        $applied = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
        $applied->execute([$name]);
        $storedChecksum = $applied->fetchColumn();
        if ($storedChecksum !== false) {
            if (!hash_equals((string) $storedChecksum, $checksum)) {
                throw new RuntimeException('Applied migration was modified: ' . $name . '. Create a new forward migration instead.');
            }
            echo "Already applied: $name\n";
            continue;
        }

        foreach (migration_statements($sql) as $statement) {
            $result = $pdo->query($statement);
            if ($result instanceof PDOStatement) {
                $result->closeCursor();
            }
        }
        $record = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
        $record->execute([$name, $checksum]);
        echo "Applied: $name\n";
    }
} catch (Throwable $e) {
    app_log('Database migration failed: ' . $e->getMessage());
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
