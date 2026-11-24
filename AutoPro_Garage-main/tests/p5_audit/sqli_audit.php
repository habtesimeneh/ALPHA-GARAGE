<?php
declare(strict_types=1);

$dir = realpath(__DIR__ . '/../../');
$scanDirs = [
    $dir . '/app',
    $dir . '/public',
];

$patterns = [
    '/\b(?:Database::(?:run|all|one|value|insert)|->(?:query|prepare|exec))\s*\(\s*["\'](?:SELECT|INSERT|UPDATE|DELETE|REPLACE)[^"\']*?\$[a-zA-Z0-9_]+/i',
    '/\b(?:Database::(?:run|all|one|value|insert)|->(?:query|prepare|exec))\s*\(\s*["\'][^"\']*?["\']\s*\.\s*\$[a-zA-Z0-9_]+/i',
];

$results = [];

foreach ($scanDirs as $sDir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sDir));
    foreach ($iterator as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }
        $filePath = $file->getPathname();
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            foreach ($patterns as $pat) {
                if (preg_match($pat, $line)) {
                    // Check if it's safe (e.g., $inClause of sanitized ints, or table name whitelist)
                    $results[] = [
                        'file' => str_replace($dir, '', $filePath),
                        'line' => $lineNum + 1,
                        'code' => trim($line),
                    ];
                }
            }
        }
    }
}

echo "=================================================================\n";
echo "ALPHA GARAGE — SQL INJECTION STATIC SCANNER\n";
echo "Found " . count($results) . " potential SQL concatenation instances.\n";
echo "=================================================================\n\n";

foreach ($results as $r) {
    echo "  " . $r['file'] . ":" . $r['line'] . "\n    " . substr($r['code'], 0, 100) . "\n\n";
}
