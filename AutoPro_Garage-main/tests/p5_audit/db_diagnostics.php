<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

$pdo = Database::pdo();
$dbName = Database::value("SELECT DATABASE()");

echo "=================================================================\n";
echo "ALPHA GARAGE — PRIORITY 5 READ-ONLY DATABASE DIAGNOSTICS\n";
echo "Connected Database: " . $dbName . "\n";
echo "=================================================================\n\n";

// 1. Table Engines, Rows, Charsets
$tables = $pdo->query("
    SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME ASC
")->fetchAll(PDO::FETCH_ASSOC);

echo "--- 1. Tables & Storage Engines (" . count($tables) . " tables) ---\n";
$nonInnodb = [];
$nonUtf8mb4 = [];
foreach ($tables as $t) {
    if ($t['ENGINE'] !== 'InnoDB') {
        $nonInnodb[] = $t['TABLE_NAME'] . " (" . $t['ENGINE'] . ")";
    }
    if (!str_starts_with($t['TABLE_COLLATION'] ?? '', 'utf8mb4')) {
        $nonUtf8mb4[] = $t['TABLE_NAME'] . " (" . $t['TABLE_COLLATION'] . ")";
    }
    printf("  %-32s | %-8s | %-24s | %6d rows | %8d KB data\n",
        $t['TABLE_NAME'],
        $t['ENGINE'],
        $t['TABLE_COLLATION'],
        (int)$t['TABLE_ROWS'],
        round((int)$t['DATA_LENGTH'] / 1024)
    );
}

echo "\nEngine Audit: " . (empty($nonInnodb) ? "ALL InnoDB (100% ACID compliant)" : "NON-INNODB FOUND: " . implode(', ', $nonInnodb)) . "\n";
echo "Collation Audit: " . (empty($nonUtf8mb4) ? "ALL utf8mb4 (100% full Unicode / Ethiopic compliant)" : "NON-UTF8MB4 FOUND: " . implode(', ', $nonUtf8mb4)) . "\n\n";

// 2. Foreign Keys
$fks = $pdo->query("
    SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME, CONSTRAINT_NAME
")->fetchAll(PDO::FETCH_ASSOC);

echo "--- 2. Foreign Key Constraints (" . count($fks) . " constraints) ---\n";
foreach ($fks as $fk) {
    echo "  " . $fk['TABLE_NAME'] . "." . $fk['COLUMN_NAME'] . " -> " . $fk['REFERENCED_TABLE_NAME'] . "." . $fk['REFERENCED_COLUMN_NAME'] . " [" . $fk['CONSTRAINT_NAME'] . "]\n";
}

// 3. Priority 3 Performance Indexes
$indexes = $pdo->query("
    SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS, NON_UNIQUE
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME NOT IN ('PRIMARY')
    GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
    ORDER BY TABLE_NAME, INDEX_NAME
")->fetchAll(PDO::FETCH_ASSOC);

echo "\n--- 3. Performance Indexes (" . count($indexes) . " secondary indexes) ---\n";
foreach ($indexes as $idx) {
    $uniqueStr = $idx['NON_UNIQUE'] == 0 ? "UNIQUE" : "INDEX";
    echo "  " . $idx['TABLE_NAME'] . " : " . $idx['INDEX_NAME'] . " (" . $idx['COLUMNS'] . ") [" . $uniqueStr . "]\n";
}

// 4. Linear SHA-256 Audit Chain Verification
echo "\n--- 4. Linear SHA-256 Audit Chain Verification ---\n";
$chainRes = AuditLogger::verifyChain();
echo "  Chain Status: " . ($chainRes['ok'] ? "VALID (100% INTACT)" : "BROKEN") . "\n";
echo "  Records Checked: " . $chainRes['checked'] . "\n";
echo "  Result Message: " . $chainRes['message'] . "\n";

// 5. Database Triggers
$triggers = $pdo->query("
    SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING
    FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE()
")->fetchAll(PDO::FETCH_ASSOC);

echo "\n--- 5. Database Triggers (" . count($triggers) . " triggers) ---\n";
foreach ($triggers as $trg) {
    echo "  " . $trg['TRIGGER_NAME'] . " [" . $trg['ACTION_TIMING'] . " " . $trg['EVENT_MANIPULATION'] . " on " . $trg['EVENT_OBJECT_TABLE'] . "]\n";
}
