<?php
declare(strict_types=1);

function can(string $permission): bool
{
    $u = current_user();
    return $u !== null && in_array($permission, $u['permissions'], true);
}

function can_any(string ...$permissions): bool
{
    foreach ($permissions as $p) {
        if (can($p)) { return true; }
    }
    return false;
}

/** Server-side authorisation gate. Failed attempts are written to the audit log. */
function require_permission(string ...$any): void
{
    if (!can_any(...$any)) {
        deny(implode(' | ', $any));
    }
}

function deny(string $needed, string $module = 'security', ?int $recordId = null): never
{
    AuditLogger::logSafe('authorization_denied', $module, $recordId,
        null, ['uri' => $_SERVER['REQUEST_URI'] ?? '', 'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'required' => $needed], 'denied');
    abort(403, 'You do not have permission to perform this action. The attempt has been recorded.');
}
