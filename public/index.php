<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CANONICAL PUBLIC WEB REDIRECT
 * Redirects legacy /public/ requests directly to the active AutoPro public web application.
 */
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ../AutoPro_Garage-main/public/' . $qs, true, 302);
exit;
