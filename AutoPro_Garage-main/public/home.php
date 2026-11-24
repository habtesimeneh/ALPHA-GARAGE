<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — LEGACY HOMEPAGE ALIAS
 * Permanently forwards legacy home.php requests to canonical index.php,
 * preserving any query parameters (e.g. ?lang=am).
 * Frontend engine reference: scroll-animations.js
 */
require_once __DIR__ . '/../app/bootstrap.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
redirect('index.php' . $qs);
