<?php
declare(strict_types=1);

/**
 * Tamper-evident, append-only audit logger.
 *
 *  - Every row stores prev_hash (hash of the previous row) and row_hash = SHA-256(prev_hash + row content).
 *    Editing, deleting or re-ordering any historic row breaks the chain and is detected by verifyChain().
 *  - The database also blocks UPDATE/DELETE on audit_logs with triggers (see database.sql).
 *  - log() joins the caller's transaction, so a business change and its audit record commit or roll back together.
 *  - Secrets (passwords, tokens, CSRF values) are redacted before storage.
 */
final class AuditLogger
{
    private const REDACT = ['password', 'password_hash', 'new_password', 'current_password', 'confirm_password',
                            'token', '_csrf', 'csrf', 'secret', 'session_id', 'api_key'];
    private const SEP = "\x1f";

    public static function log(string $action, string $module, ?int $recordId = null, mixed $previous = null,
                               mixed $new = null, string $result = 'success', ?array $actor = null): int
    {
        $actor ??= self::currentActor();
        $createdAt = date('Y-m-d H:i:s');
        $prevJson = self::encode($previous);
        $newJson  = self::encode($new);
        $ip = client_ip();
        $ua = user_agent();

        return (int) Database::transaction(function (PDO $pdo) use ($actor, $action, $module, $recordId, $prevJson, $newJson, $result, $createdAt, $ip, $ua) {
            // Lock the chain head: appends are serialised so the chain stays linear.
            $head = $pdo->query('SELECT last_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE')->fetch();
            $prevHash = $head ? (string) $head['last_hash'] : str_repeat('0', 64);

            $rowHash = self::hashRow($prevHash, [
                $actor['user_id'] ?? null, $actor['username'] ?? null, $actor['role'] ?? null, $action, $module,
                $recordId, $prevJson, $newJson, $ip, $ua, $result, $createdAt,
            ]);

            $st = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, username, user_role, action_type, module_name, record_id, previous_value,
                    new_value, ip_address, user_agent, action_result, created_at, prev_hash, row_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([$actor['user_id'] ?? null, $actor['username'] ?? null, $actor['role'] ?? null, $action, $module,
                $recordId, $prevJson, $newJson, $ip, $ua, $result, $createdAt, $prevHash, $rowHash]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE audit_chain_head SET last_id = ?, last_hash = ? WHERE id = 1')->execute([$id, $rowHash]);
            return $id;
        });
    }

    /** For security events (denials, failed logins): never let an audit failure hide the original error. */
    public static function logSafe(string $action, string $module, ?int $recordId = null, mixed $previous = null,
                                   mixed $new = null, string $result = 'success', ?array $actor = null): void
    {
        try {
            self::log($action, $module, $recordId, $previous, $new, $result, $actor);
        } catch (Throwable $e) {
            app_log('AUDIT WRITE FAILED (' . $action . '): ' . $e->getMessage());
        }
    }

    /** Verify the whole chain. @return array{ok:bool,checked:int,bad_id:?int,message:string} */
    public static function verifyChain(): array
    {
        $pdo = Database::pdo();
        $expectedPrev = str_repeat('0', 64);
        $checked = 0;
        $lastId = 0;
        $afterId = 0;
        do {
            $st = $pdo->prepare('SELECT * FROM audit_logs WHERE id > ? ORDER BY id ASC LIMIT 1000');
            $st->execute([$afterId]);
            $rows = $st->fetchAll();
            foreach ($rows as $r) {
                $checked++;
                if (!hash_equals($expectedPrev, (string) $r['prev_hash'])) {
                    return ['ok' => false, 'checked' => $checked, 'bad_id' => (int) $r['id'],
                            'message' => 'Chain broken before record #' . $r['id'] . ': a previous record was altered or removed.'];
                }
                $calc = self::hashRow((string) $r['prev_hash'], [
                    $r['user_id'], $r['username'], $r['user_role'], $r['action_type'], $r['module_name'], $r['record_id'],
                    $r['previous_value'], $r['new_value'], $r['ip_address'], $r['user_agent'], $r['action_result'], $r['created_at'],
                ]);
                if (!hash_equals($calc, (string) $r['row_hash'])) {
                    return ['ok' => false, 'checked' => $checked, 'bad_id' => (int) $r['id'],
                            'message' => 'Record #' . $r['id'] . ' content does not match its hash: it was modified.'];
                }
                $expectedPrev = (string) $r['row_hash'];
                $lastId = (int) $r['id'];
                $afterId = $lastId;
            }
        } while (count($rows) === 1000);

        $head = $pdo->query('SELECT last_id, last_hash FROM audit_chain_head WHERE id = 1')->fetch();
        if ($head && ((int) $head['last_id'] !== $lastId || !hash_equals((string) $head['last_hash'], $expectedPrev))) {
            return ['ok' => false, 'checked' => $checked, 'bad_id' => null,
                    'message' => 'The newest audit records are missing: the stored chain head does not match the last record.'];
        }
        return ['ok' => true, 'checked' => $checked, 'bad_id' => null, 'message' => "All $checked audit records are intact."];
    }

    private static function hashRow(string $prevHash, array $fields): string
    {
        $parts = [$prevHash];
        foreach ($fields as $f) {
            $parts[] = $f === null ? '' : (string) $f;
        }
        return hash('sha256', implode(self::SEP, $parts));
    }

    private static function currentActor(): array
    {
        $u = function_exists('current_user') ? current_user() : null;
        return $u ? ['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role_name']]
                  : ['user_id' => null, 'username' => null, 'role' => null];
    }

    private static function encode(mixed $v): ?string
    {
        if ($v === null) { return null; }
        if (is_array($v)) { $v = self::redact($v); }
        return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function redact(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $a[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $a[$k] = self::redact($v);
            }
        }
        return $a;
    }
}
