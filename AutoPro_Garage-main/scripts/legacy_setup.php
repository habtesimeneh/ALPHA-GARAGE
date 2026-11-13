<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — ARCHIVED / LEGACY SETUP SCRIPT
 * 
 * SECURITY NOTICE:
 * This script is an archived initial setup utility preserved strictly for
 * disaster recovery or historical documentation.
 * 
 * DO NOT PLACE THIS SCRIPT INSIDE ANY PUBLIC WEB-ACCESSIBLE DIRECTORY (e.g. public/).
 * In production, administrative account creation and initialization must be
 * conducted strictly via authorized CLI mechanisms (scripts/provision_test_db.php
 * or admin recovery scripts).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: This archived installer must not be accessed over HTTP.\n");
    http_response_code(404);
    exit(1);
}

fwrite(STDOUT, "Alpha Garage Legacy Installer (Archived Reference Only)\n");
fwrite(STDOUT, "Use scripts/provision_test_db.php for test database initialization.\n");
exit(0);
