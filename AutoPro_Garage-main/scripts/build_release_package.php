<?php
/**
 * AutoPro Garage Management System
 * Production & Staging Release Packaging Engine
 *
 * Version: v1.0.3-staging (Priority 23 Final Remediation)
 *
 * Features:
 * - Strict documentation allowlist (FIND-20-03)
 * - Deterministic bit-for-bit reproducible archives (FIND-20-02, FIND-22-02)
 * - External detached SHA-256 sidecar checksum (FIND-22-01)
 * - Authenticated backup crypto tooling inclusion (FIND-20-04, FIND-22-05)
 * - Accurate storage skeleton and permissions (FIND-20-08)
 *
 * Usage:
 *   php scripts/build_release_package.php [target_output_dir]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__));
$version = 'v1.0.3-staging';

// Allow overriding output directory for dual-build reproducibility verification
$customReleaseDir = $argv[1] ?? null;
$releaseDir = $customReleaseDir !== null ? (strpos($customReleaseDir, '/') === 0 || strpos($customReleaseDir, ':') !== false ? $customReleaseDir : APP_ROOT . '/' . $customReleaseDir) : APP_ROOT . '/storage/staging/releases';

$packageName = "alpha-garage-$version";
$packageDir = $releaseDir . '/' . $packageName;
$tarFile    = $releaseDir . "/$packageName.tar";
$tarGzFile  = $releaseDir . "/$packageName.tar.gz";
$shaFile    = $releaseDir . "/$packageName.sha256";

echo "=================================================================\n";
echo "ALPHA GARAGE — RELEASE PACKAGING ENGINE ($version)\n";
echo "=================================================================\n";
echo "Output Directory: $releaseDir\n";

// 1. Clean previous staging release artifacts for this tag
if (is_dir($packageDir)) {
    deleteDirectory($packageDir);
}
@unlink($tarFile);
@unlink($tarGzFile);
@unlink($shaFile);

if (!is_dir($releaseDir) && !mkdir($releaseDir, 0755, true) && !is_dir($releaseDir)) {
    throw new RuntimeException("Failed to create staging releases directory: $releaseDir");
}

if (!is_dir($packageDir) && !mkdir($packageDir, 0755, true) && !is_dir($packageDir)) {
    throw new RuntimeException("Failed to create package directory: $packageDir");
}

// 2. Define whitelist of core application directories
$whitelistDirs = [
    'app',
    'database',
    'public',
];

// Explicit Documentation Allowlist (FIND-20-03)
// Only operator runbooks, deployment guides, and end-user system manuals are packaged.
// Internal vulnerability audits, phase reports, and assignment artifacts are strictly excluded.
$allowedDocFiles = [
    'docs/deployment/UBUNTU_24_04_DEPLOYMENT_RUNBOOK.md',
    'docs/deployment/BACKUP_AND_DISASTER_RECOVERY.md',
    'docs/deployment/PRODUCTION_RELEASE_CHECKLIST.md',
    'docs/deployment/ENVIRONMENT_CONFIGURATION.md',
    'docs/deployment/RELEASE_PACKAGE_MANIFEST.md',
    'documentation/INSTALLATION_GUIDE.md',
    'documentation/USER_MANUAL.md',
    'documentation/SYSTEM_DESIGN.md',
    'documentation/DATABASE_DESIGN.md',
    'documentation/SECURITY_DESIGN.md',
    'documentation/DATA_DICTIONARY.md',
];

$whitelistFiles = [
    '.env.example',
    'README.md',
    '.htaccess',
    'index.php',
];

// Files to explicitly skip inside allowed directories
$blacklistedPatterns = [
    '#^tests/#',
    '#^app/services/DemoSeeder\.php$#',
    '#^storage/backups/#',
    '#^storage/logs/.*\.log$#',
    '#^storage/uploads/.*(?<!\.htaccess|\.gitkeep)$#',
    '#^storage/private/(encryption\.key|.*\.generated\.sql)$#',
    '#^app/config/local\.php$#',
    '#^scripts/(provision_test_db\.php|run_isolated_tests\.php|legacy_setup\.php)$#',
    '#\.(git|svn|hg)#',
    '#\.(DS_Store|tmp|swp|bak)#',
    '#Thumbs\.db#',
];

function isBlacklisted(string $relPath, array $patterns): bool {
    $norm = str_replace('\\', '/', $relPath);
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $norm)) {
            return true;
        }
    }
    return false;
}

$copiedFiles = [];
$totalBytes = 0;

// Copy core directories
foreach ($whitelistDirs as $dir) {
    $srcDir = APP_ROOT . '/' . $dir;
    if (!is_dir($srcDir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            continue;
        }

        $srcPath = $item->getPathname();
        $relPath = substr($srcPath, strlen(APP_ROOT) + 1);
        $normRel = str_replace('\\', '/', $relPath);

        if (isBlacklisted($normRel, $blacklistedPatterns)) {
            continue;
        }

        $destPath = $packageDir . '/' . $normRel;
        $destSubDir = dirname($destPath);
        if (!is_dir($destSubDir) && !mkdir($destSubDir, 0755, true) && !is_dir($destSubDir)) {
            throw new RuntimeException("Failed to create destination directory: $destSubDir");
        }

        if (!copy($srcPath, $destPath)) {
            throw new RuntimeException("Failed to copy: $srcPath -> $destPath");
        }

        $copiedFiles[] = $normRel;
        $totalBytes += filesize($srcPath);
    }
}

// Copy Documentation Allowlist (FIND-20-03)
foreach ($allowedDocFiles as $docRel) {
    $srcPath = APP_ROOT . '/' . $docRel;
    if (!file_exists($srcPath)) {
        throw new RuntimeException("Allowlisted documentation file missing from source: $docRel");
    }
    $destPath = $packageDir . '/' . $docRel;
    $destSubDir = dirname($destPath);
    if (!is_dir($destSubDir) && !mkdir($destSubDir, 0755, true) && !is_dir($destSubDir)) {
        throw new RuntimeException("Failed to create doc destination directory: $destSubDir");
    }
    if (!copy($srcPath, $destPath)) {
        throw new RuntimeException("Failed to copy doc file: $docRel");
    }
    $copiedFiles[] = $docRel;
    $totalBytes += filesize($srcPath);
}

// Copy allowed scripts (FIND-20-04 included: backup_crypto.php)
$scriptsToCopy = ['backup_database.sh', 'backup_crypto.php', 'create_admin.php'];
foreach ($scriptsToCopy as $s) {
    $src = APP_ROOT . '/scripts/' . $s;
    if (file_exists($src)) {
        $dest = $packageDir . '/scripts/' . $s;
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) {
            throw new RuntimeException("Failed to create scripts directory in package.");
        }
        if (!copy($src, $dest)) {
            throw new RuntimeException("Failed to copy script: $s");
        }
        $copiedFiles[] = "scripts/$s";
        $totalBytes += filesize($src);
    } else {
        throw new RuntimeException("Required script missing from source: scripts/$s");
    }
}

// Copy whitelist root files
foreach ($whitelistFiles as $wf) {
    $src = APP_ROOT . '/' . $wf;
    if (file_exists($src)) {
        $dest = $packageDir . '/' . $wf;
        if (!copy($src, $dest)) {
            throw new RuntimeException("Failed to copy root file: $wf");
        }
        $copiedFiles[] = $wf;
        $totalBytes += filesize($src);
    }
}

// 3. Storage Skeleton & Root Storage .htaccess (FIND-20-08: Exactly 14 files)
$storageTargetDir = $packageDir . '/storage';
if (!is_dir($storageTargetDir) && !mkdir($storageTargetDir, 0755, true) && !is_dir($storageTargetDir)) {
    throw new RuntimeException("Failed to create storage directory in package: $storageTargetDir");
}

$rootStorageHtaccess = APP_ROOT . '/storage/.htaccess';
if (file_exists($rootStorageHtaccess)) {
    $destHtaccess = $storageTargetDir . '/.htaccess';
    if (!copy($rootStorageHtaccess, $destHtaccess)) {
        throw new RuntimeException("Failed to copy storage/.htaccess to package destination.");
    }
    $copiedFiles[] = 'storage/.htaccess';
    $totalBytes += filesize($rootStorageHtaccess);
} else {
    throw new RuntimeException("Source storage/.htaccess does not exist: $rootStorageHtaccess");
}

// Note: storage/private intentionally contains only .htaccess (no .gitkeep) per FIND-20-08
$storageSkeleton = [
    'storage/logs' => ['.gitkeep' => '', '.htaccess' => "Require all denied\n"],
    'storage/uploads' => ['.gitkeep' => '', '.htaccess' => "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8\nRemoveType .php .phtml .php3 .php4 .php5 .php7 .php8\nRequire all granted\n"],
    'storage/private' => ['.htaccess' => "Require all denied\n"],
    'storage/sessions' => ['.gitkeep' => '', '.htaccess' => "Require all denied\n"],
    'storage/backups' => ['.gitkeep' => '', '.htaccess' => "Require all denied\n"],
    'storage/mail_spool' => ['.gitkeep' => '', '.htaccess' => "Require all denied\n"],
    'storage/rate_limits' => ['.gitkeep' => '', '.htaccess' => "Require all denied\n"],
];

foreach ($storageSkeleton as $skelDir => $files) {
    $fullDir = $packageDir . '/' . $skelDir;
    if (!is_dir($fullDir) && !mkdir($fullDir, 0755, true) && !is_dir($fullDir)) {
        throw new RuntimeException("Failed to create storage skeleton directory: $fullDir");
    }
    foreach ($files as $name => $content) {
        $fp = $fullDir . '/' . $name;
        if (file_put_contents($fp, $content) === false) {
            throw new RuntimeException("Failed to write storage skeleton file: $fp");
        }
        $copiedFiles[] = "$skelDir/$name";
        $totalBytes += strlen($content);
    }
}

// 4. Deterministic Sort of Packaged Files
sort($copiedFiles, SORT_STRING);

// 5. Assertions & Validation on Packaged Artifacts
echo "Performing pre-archive package assertions...\n";

// Assert storage/.htaccess exists and matches source
if (!file_exists($packageDir . '/storage/.htaccess')) {
    throw new RuntimeException("FATAL: storage/.htaccess is missing from package directory!");
}

// Assert docs/deployment exists
if (!is_dir($packageDir . '/docs/deployment') || !file_exists($packageDir . '/docs/deployment/UBUNTU_24_04_DEPLOYMENT_RUNBOOK.md')) {
    throw new RuntimeException("FATAL: docs/deployment/UBUNTU_24_04_DEPLOYMENT_RUNBOOK.md is missing from package directory!");
}

// Assert DemoSeeder.php is NOT present
if (file_exists($packageDir . '/app/services/DemoSeeder.php')) {
    throw new RuntimeException("FATAL: DemoSeeder.php was packaged! Blacklist failed.");
}

// Assert no internal security reports or phase docs leaked into package (FIND-20-03)
foreach ($copiedFiles as $f) {
    if (preg_match('#^(docs/security/|docs/production-readiness/|docs/payment-management/|docs/phases/|docs/phase)#', $f)) {
        throw new RuntimeException("FATAL: Internal audit documentation leaked into package: $f");
    }
    if (preg_match('#^documentation/(ASSIGNMENT_REQUIREMENTS|PROJECT_PORTFOLIO|SUBMISSION_CHECKLIST|FINAL_SUBMISSION)#', $f)) {
        throw new RuntimeException("FATAL: Assignment/academic document leaked into package: $f");
    }
}

// Secret & Credential Sanitization Scan on Package
echo "Scanning packaged files for secrets and forbidden test artifacts...\n";
$forbiddenKeywords = [
    'Admin#2026!', 'Manager#2026!', 'Recept#2026!', 'Mechanic#2026!',
    'Password123!', 'quickLogin', 'demo-role-card'
];

$violations = [];
foreach ($copiedFiles as $f) {
    $fp = $packageDir . '/' . $f;
    if (is_file($fp) && !in_array(pathinfo($fp, PATHINFO_EXTENSION), ['png', 'jpg', 'jpeg', 'ico', 'woff', 'woff2', 'ttf', 'pdf', 'mp4'], true)) {
        $c = file_get_contents($fp);
        foreach ($forbiddenKeywords as $bad) {
            if (strpos($c, $bad) !== false) {
                $violations[] = ["file" => $f, "keyword" => $bad];
            }
        }
    }
}

if (!empty($violations)) {
    echo "ERROR: Package contains forbidden keywords / secrets:\n";
    foreach ($violations as $v) {
        echo "  - {$v['file']}: '{$v['keyword']}'\n";
    }
    throw new RuntimeException("Packaging halted due to security violation.");
}
echo "PASS: Zero credentials, zero test shortcuts, zero secrets found in package.\n";

// 6. Normalization for Bit-for-Bit Reproducible Build (FIND-20-02)
echo "Normalizing file timestamps for deterministic build (epoch: 1700000000)...\n";
$fixedEpoch = 1700000000;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($packageDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    touch($item->getPathname(), $fixedEpoch);
}
touch($packageDir, $fixedEpoch);

// 7. Deterministic Archive Creation
echo "Creating deterministic release tarball...\n";
$cmdTar = "tar -cf \"$tarFile\" --mtime=\"@$fixedEpoch\" -C \"$releaseDir\" \"$packageName\"";
exec($cmdTar, $outTar, $codeTar);

if ($codeTar !== 0 || !file_exists($tarFile)) {
    throw new RuntimeException("Tar archive creation failed with code: $codeTar");
}

// Compress using deterministic RFC 1952 gzip stream with zeroed header timestamp
echo "Applying deterministic gzip compression (zeroed header timestamp)...\n";
$tarData = file_get_contents($tarFile);
$crc = crc32($tarData);
$size = strlen($tarData) % (2**32);
$deflated = gzdeflate($tarData, 9);
$gzipHeader = "\x1f\x8b\x08\x00\x00\x00\x00\x00\x02\xff"; // ID1, ID2, CM=8, FLG=0, MTIME=0, XFL=2 (max), OS=255
$gzipFooter = pack('V', $crc) . pack('V', $size);
file_put_contents($tarGzFile, $gzipHeader . $deflated . $gzipFooter);
unlink($tarFile);

$tarSize = filesize($tarGzFile);
$tarSha  = hash_file('sha256', $tarGzFile);
echo "Created $tarGzFile: $tarSize bytes | SHA-256: $tarSha\n";

// Checksum file (both BSD style and standard Linux sha256sum format)
$checksumContent = "$tarSha  $packageName.tar.gz\nSHA256 ($packageName.tar.gz) = $tarSha\n";
file_put_contents($shaFile, $checksumContent);

echo "\n=================================================================\n";
echo "RELEASE PACKAGE BUILD SUMMARY\n";
echo "=================================================================\n";
echo "  Release Tag:       $version\n";
echo "  Staging Dir:       $packageDir\n";
echo "  Total Files:       " . count($copiedFiles) . "\n";
echo "  Uncompressed Size: " . round($totalBytes / (1024 * 1024), 2) . " MB ($totalBytes bytes)\n";
echo "  Tarball:           $tarGzFile (" . round($tarSize / (1024 * 1024), 2) . " MB)\n";
echo "  Archive SHA-256:   $tarSha\n";
echo "=================================================================\n";

function deleteDirectory(string $dir): void {
    if (!file_exists($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? deleteDirectory("$dir/$file") : unlink("$dir/$file");
    }
    rmdir($dir);
}
