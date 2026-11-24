<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — TEST SESSION PRUNING UTILITY
 * Strictly scoped to storage/testing/sessions.
 * Never touches live application sessions.
 */

$testSessionDir = str_replace('\\', '/', dirname(__DIR__, 2)) . '/storage/testing/sessions';

if (!is_dir($testSessionDir)) {
    echo "No testing session directory found at $testSessionDir.\n";
    exit(0);
}

// Ensure path strictly ends with storage/testing/sessions
if (!str_ends_with($testSessionDir, 'storage/testing/sessions')) {
    fwrite(STDERR, "SECURITY GUARD: Path does not match storage/testing/sessions! Aborting.\n");
    exit(1);
}

$now = time();
$cutoff = 7200; // 2 hours
$deleted = 0;
$preserved = 0;

$files = glob($testSessionDir . '/sess_*');
foreach ($files as $file) {
    if (!is_file($file)) continue;
    $base = basename($file);
    if (!preg_match('/^sess_[a-zA-Z0-9,-]+$/', $base)) {
        continue; // safety guard on filename pattern
    }
    $age = $now - filemtime($file);
    if ($age > $cutoff) {
        if (@unlink($file)) {
            $deleted++;
        }
    } else {
        $preserved++;
    }
}

echo "Testing Session Cleanup Summary:\n";
echo "  Target Directory: $testSessionDir\n";
echo "  Cutoff Age:       > $cutoff seconds (2 hours)\n";
echo "  Pruned Sessions:  $deleted\n";
echo "  Active Sessions:  $preserved\n";
