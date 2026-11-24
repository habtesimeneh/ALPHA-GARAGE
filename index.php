<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CANONICAL ENTRY REDIRECT
 * Redirects root visits directly to the active public web application,
 * preserving query strings (e.g. ?lang=am) and eliminating intermediate 302 redirect hops.
 */
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: AutoPro_Garage-main/public/' . $qs, true, 302);
exit;
