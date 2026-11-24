<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

echo "=================================================================\n";
echo "STORAGE & RESOURCE MANAGEMENT AUDIT\n";
echo "=================================================================\n";

$storageDir = APP_ROOT . '/storage';

function dir_stat(string $path): array {
    if (!is_dir($path)) return ['count' => 0, 'size' => 0];
    $count = 0;
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        $count++;
        $size += $file->getSize();
    }
    return ['count' => $count, 'size' => $size];
}

$subdirs = [
    'logs' => $storageDir . '/logs',
    'sessions' => $storageDir . '/sessions',
    'rate_limits' => $storageDir . '/rate_limits',
    'cache' => $storageDir . '/cache',
    'backups' => $storageDir . '/backups',
    'testing/sessions' => $storageDir . '/testing/sessions',
    'testing/rate_limits' => $storageDir . '/testing/rate_limits',
];

echo sprintf("%-25s %-12s %-15s\n", 'Directory', 'File Count', 'Size (KB)');
echo str_repeat('-', 55) . "\n";
foreach ($subdirs as $name => $path) {
    $stat = dir_stat($path);
    echo sprintf("%-25s %-12d %-15.2f\n", $name, $stat['count'], $stat['size'] / 1024);
}
echo "\n";

// Check session configuration
echo "Session Configuration:\n";
echo "  session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . " seconds\n";
echo "  session.gc_probability: " . ini_get('session.gc_probability') . "\n";
echo "  session.gc_divisor:     " . ini_get('session.gc_divisor') . "\n";
echo "  session.save_path:       " . session_save_path() . "\n";

// Inspect session files in storage/sessions
$sessFiles = glob($storageDir . '/sessions/sess_*');
$expiredCount = 0;
$now = time();
$gcLifetime = (int) ini_get('session.gc_maxlifetime') ?: 1440;
foreach ($sessFiles as $f) {
    if ($now - filemtime($f) > $gcLifetime) {
        $expiredCount++;
    }
}
echo "  Total session files:    " . count($sessFiles) . "\n";
echo "  Expired session files:  " . $expiredCount . " (older than $gcLifetime seconds)\n\n";

// Inspect log files
$logFiles = glob($storageDir . '/logs/*.log');
echo "Log Files:\n";
foreach ($logFiles as $lf) {
    echo "  " . basename($lf) . " : " . round(filesize($lf) / 1024, 2) . " KB\n";
}
