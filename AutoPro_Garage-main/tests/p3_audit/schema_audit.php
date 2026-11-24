<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
$pdo = Database::pdo();

echo "=================================================================\n";
echo "ALPHA GARAGE — PRIORITY 3: DATABASE SCHEMA & INDEX AUDIT\n";
echo "=================================================================\n";

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
echo "Active Database: $dbName\n";
echo "MySQL Version:   $version\n\n";

// 1. Table status
$tables = $pdo->query("
    SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION, AUTO_INCREMENT
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    ORDER BY TABLE_NAME
")->fetchAll(PDO::FETCH_ASSOC);

echo sprintf("%-32s %-10s %-8s %-10s %-10s %-25s\n", 'Table', 'Engine', 'Rows', 'Data(KB)', 'Idx(KB)', 'Collation');
echo str_repeat('-', 98) . "\n";
$totalData = 0;
$totalIdx = 0;
foreach ($tables as $t) {
    $dataKb = ($t['DATA_LENGTH'] ?? 0) / 1024;
    $idxKb = ($t['INDEX_LENGTH'] ?? 0) / 1024;
    $totalData += $dataKb;
    $totalIdx += $idxKb;
    echo sprintf(
        "%-32s %-10s %-8d %-10.1f %-10.1f %-25s\n",
        $t['TABLE_NAME'],
        $t['ENGINE'] ?? 'NULL',
        $t['TABLE_ROWS'] ?? 0,
        $dataKb,
        $idxKb,
        $t['TABLE_COLLATION'] ?? ''
    );
}
echo str_repeat('-', 98) . "\n";
echo sprintf("TOTAL TABLES: %d | Total Data: %.1f KB | Total Index: %.1f KB\n\n", count($tables), $totalData, $totalIdx);

// 2. Indexes audit
echo "=================================================================\n";
echo "TABLE INDEXES AUDIT\n";
echo "=================================================================\n";

$indexes = $pdo->query("
    SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, CARDINALITY, INDEX_TYPE
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
")->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($indexes as $idx) {
    $tb = $idx['TABLE_NAME'];
    $name = $idx['INDEX_NAME'];
    if (!isset($grouped[$tb])) $grouped[$tb] = [];
    if (!isset($grouped[$tb][$name])) {
        $grouped[$tb][$name] = [
            'unique' => $idx['NON_UNIQUE'] == 0,
            'type' => $idx['INDEX_TYPE'],
            'columns' => []
        ];
    }
    $grouped[$tb][$name]['columns'][] = $idx['COLUMN_NAME'];
}

foreach ($grouped as $table => $idxs) {
    echo "TABLE: $table\n";
    foreach ($idxs as $name => $info) {
        $unq = $info['unique'] ? '[UNIQUE]' : '[INDEX]';
        $cols = implode(', ', $info['columns']);
        echo sprintf("  %-10s %-30s (%s)\n", $unq, $name, $cols);
    }
    echo "\n";
}

// 3. Foreign keys audit
echo "=================================================================\n";
echo "FOREIGN KEYS AUDIT\n";
echo "=================================================================\n";
$fks = $pdo->query("
    SELECT 
        TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME, CONSTRAINT_NAME
")->fetchAll(PDO::FETCH_ASSOC);

echo sprintf("%-28s %-22s -> %-25s %-20s\n", 'Table', 'Column', 'Referenced Table', 'Referenced Column');
echo str_repeat('-', 98) . "\n";
foreach ($fks as $fk) {
    echo sprintf(
        "%-28s %-22s -> %-25s %-20s\n",
        $fk['TABLE_NAME'],
        $fk['COLUMN_NAME'],
        $fk['REFERENCED_TABLE_NAME'],
        $fk['REFERENCED_COLUMN_NAME']
    );
}
echo sprintf("\nTOTAL FOREIGN KEYS: %d\n\n", count($fks));
