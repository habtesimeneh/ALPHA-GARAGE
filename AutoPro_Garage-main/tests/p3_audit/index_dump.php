<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
$pdo = Database::pdo();

$indexes = $pdo->query("
    SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as cols
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
    ORDER BY TABLE_NAME, INDEX_NAME
")->fetchAll(PDO::FETCH_ASSOC);

echo "TABLE INDEX SUMMARY:\n";
foreach ($indexes as $idx) {
    $type = $idx['NON_UNIQUE'] == 0 ? '[UNIQUE]' : '[INDEX]';
    echo sprintf("%-28s %-10s %-30s (%s)\n", $idx['TABLE_NAME'], $type, $idx['INDEX_NAME'], $idx['cols']);
}
