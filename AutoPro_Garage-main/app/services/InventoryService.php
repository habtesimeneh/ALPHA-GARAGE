<?php
declare(strict_types=1);

/** Spare parts and stock. Every quantity change runs in a transaction, locks the part row and is recorded twice:
 *  in inventory_transactions (stock ledger) and in the audit log. */
final class InventoryService
{
    public static function savePart(?int $id, array $d): int
    {
        $quantity = filter_var($d['quantity'] ?? 0, FILTER_VALIDATE_INT);
        $reorderLevel = filter_var($d['reorder_level'] ?? null, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0 || $quantity > 2147483647
            || $reorderLevel === false || $reorderLevel < 0 || $reorderLevel > 2147483647) {
            throw new DomainException('Stock and reorder levels must be non-negative whole numbers within the supported range.');
        }
        foreach (['unit_cost', 'unit_price'] as $field) {
            $amount = $d[$field] ?? '';
            if ((!is_string($amount) && !is_int($amount)) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', (string) $amount)
                || to_cents($amount) > 999999999999) {
                throw new DomainException('Part costs and prices must be non-negative amounts within the supported range.');
            }
        }

        return (int) Database::transaction(function () use ($id, $d) {
            $vals = [$d['sku'], $d['name'], $d['category'] ?: null, $d['unit'], (int) $d['reorder_level'],
                     from_cents(to_cents($d['unit_cost'])), from_cents(to_cents($d['unit_price'])), $d['supplier'] ?: null];
            if ($id === null) {
                $qty = (int) ($d['quantity'] ?? 0);
                $id = Database::insert(
                    'INSERT INTO spare_parts (sku, name, category, unit, reorder_level, unit_cost, unit_price, supplier, quantity) VALUES (?,?,?,?,?,?,?,?,?)',
                    [...$vals, $qty]);
                if ($qty > 0) {
                    self::ledger($id, 'purchase', $qty, $qty, $d['unit_cost'], $d['supplier'] ?: null, 'Opening stock', null, 'Opening stock on creation');
                }
                AuditLogger::log('part_created', 'inventory', $id, null, ['sku' => $d['sku'], 'name' => $d['name'], 'quantity' => $qty, 'unit_price' => $d['unit_price']]);
            } else {
                $old = Database::one('SELECT sku, name, category, unit, reorder_level, unit_cost, unit_price, supplier, is_active FROM spare_parts WHERE id = ? FOR UPDATE', [$id]);
                if (!$old) { throw new DomainException('Spare part not found.'); }
                Database::run('UPDATE spare_parts SET sku=?, name=?, category=?, unit=?, reorder_level=?, unit_cost=?, unit_price=?, supplier=? WHERE id=?', [...$vals, $id]);
                $new = Database::one('SELECT sku, name, category, unit, reorder_level, unit_cost, unit_price, supplier, is_active FROM spare_parts WHERE id = ?', [$id]);
                AuditLogger::log('part_updated', 'inventory', $id, $old, $new);
            }
            return $id;
        });
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::transaction(function () use ($id, $active) {
            $p = Database::one('SELECT id, name, is_active FROM spare_parts WHERE id = ? FOR UPDATE', [$id]);
            if (!$p) { throw new DomainException('Spare part not found.'); }
            Database::run('UPDATE spare_parts SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
            AuditLogger::log($active ? 'part_reactivated' : 'part_deactivated', 'inventory', $id, ['is_active' => (int) $p['is_active']], ['is_active' => $active ? 1 : 0]);
        });
    }

    public static function purchase(int $partId, int $qty, string $unitCost, string $supplier, string $reference, string $notes): void
    {
        if ($qty < 1 || $qty > 100000) { throw new DomainException('Quantity must be between 1 and 100000.'); }
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $unitCost) || to_cents($unitCost) > 999999999999) {
            throw new DomainException('Unit cost must be a non-negative amount within the supported range.');
        }
        if (mb_strlen($supplier) > 120 || mb_strlen($reference) > 80 || mb_strlen($notes) > 255) {
            throw new DomainException('Supplier, reference, or notes exceed the allowed length.');
        }
        Database::transaction(function () use ($partId, $qty, $unitCost, $supplier, $reference, $notes) {
            $p = self::lockPart($partId);
            $after = (int) $p['quantity'] + $qty;
            if ($after > 2147483647) { throw new DomainException('The resulting stock exceeds the supported inventory limit.'); }
            Database::run('UPDATE spare_parts SET quantity = ?, unit_cost = ?, supplier = COALESCE(NULLIF(?, \'\'), supplier) WHERE id = ?',
                [$after, from_cents(to_cents($unitCost)), $supplier, $partId]);
            self::ledger($partId, 'purchase', $qty, $after, $unitCost, $supplier ?: null, $reference ?: null, null, $notes ?: null);
            AuditLogger::log('stock_purchase', 'inventory', $partId,
                ['quantity' => (int) $p['quantity'], 'unit_cost' => $p['unit_cost']],
                ['quantity' => $after, 'added' => $qty, 'unit_cost' => from_cents(to_cents($unitCost)), 'supplier' => $supplier, 'reference' => $reference]);
        });
    }

    public static function adjust(int $partId, string $direction, int $qty, string $reason): void
    {
        if ($qty < 1 || $qty > 100000) { throw new DomainException('Quantity must be between 1 and 100000.'); }
        if (!in_array($direction, ['in', 'out'], true)) { throw new DomainException('Choose whether stock is being added or removed.'); }
        if (trim($reason) === '') { throw new DomainException('A reason is required for stock adjustments.'); }
        if (mb_strlen($reason) > 255) { throw new DomainException('The adjustment reason must be at most 255 characters.'); }
        Database::transaction(function () use ($partId, $direction, $qty, $reason) {
            $p = self::lockPart($partId);
            $after = $direction === 'in' ? (int) $p['quantity'] + $qty : (int) $p['quantity'] - $qty;
            if ($after < 0) { throw new DomainException('Cannot remove ' . $qty . ' — only ' . $p['quantity'] . ' in stock.'); }
            if ($after > 2147483647) { throw new DomainException('The resulting stock exceeds the supported inventory limit.'); }
            Database::run('UPDATE spare_parts SET quantity = ? WHERE id = ?', [$after, $partId]);
            self::ledger($partId, $direction === 'in' ? 'adjustment_in' : 'adjustment_out', $qty, $after, null, null, null, null, $reason);
            AuditLogger::log('stock_adjustment_' . $direction, 'inventory', $partId, ['quantity' => (int) $p['quantity']], ['quantity' => $after, 'reason' => $reason]);
        });
    }

    /** Issue stock to a job card. Prevents over-issuing; optionally fulfils a mechanic's part request. */
    public static function issue(int $jobId, int $partId, int $qty, ?int $requestId = null): void
    {
        if ($qty < 1 || $qty > 100000) { throw new DomainException('Quantity must be between 1 and 100000.'); }
        Database::transaction(function () use ($jobId, $partId, $qty, $requestId) {
            $job = Database::one('SELECT id, job_number, status FROM job_cards WHERE id = ? FOR UPDATE', [$jobId]);
            if (!$job) { throw new DomainException('Job card not found.'); }
            if (!in_array($job['status'], ['Pending', 'Diagnosing', 'Waiting for Parts', 'In Progress'], true)) {
                throw new DomainException('Parts cannot be issued to a job that is ' . $job['status'] . '.');
            }
            $p = self::lockPart($partId);
            if (!$p['is_active']) { throw new DomainException('This part is deactivated.'); }
            if ($qty > (int) $p['quantity']) {
                throw new DomainException('Insufficient stock: requested ' . $qty . ', available ' . $p['quantity'] . '.');
            }
            if ($requestId !== null) {
                $req = Database::one('SELECT id, job_id, part_id, status FROM part_requests WHERE id = ? FOR UPDATE', [$requestId]);
                if (!$req || $req['status'] !== 'pending' || (int) $req['job_id'] !== $jobId || (int) $req['part_id'] !== $partId) {
                    throw new DomainException('This part request is no longer pending.');
                }
            }
            $after = (int) $p['quantity'] - $qty;
            $uid = current_user()['id'] ?? null;
            Database::run('UPDATE spare_parts SET quantity = ? WHERE id = ?', [$after, $partId]);
            Database::insert('INSERT INTO job_parts (job_id, part_id, quantity, unit_price, issued_by) VALUES (?,?,?,?,?)', [$jobId, $partId, $qty, $p['unit_price'], $uid]);
            self::ledger($partId, 'issue', $qty, $after, $p['unit_cost'], null, $job['job_number'], $jobId, 'Issued to job card');
            if ($requestId !== null) {
                Database::run("UPDATE part_requests SET status='issued', handled_by=?, handled_at=NOW() WHERE id=?", [$uid, $requestId]);
            }
            Database::run("INSERT INTO job_updates (job_id, user_id, update_type, note) VALUES (?,?, 'parts', ?)",
                [$jobId, $uid, 'Issued ' . $qty . ' × ' . $p['name'] . ' (' . $p['sku'] . ')']);
            AuditLogger::log('parts_issued', 'inventory', $partId, ['quantity' => (int) $p['quantity']],
                ['quantity' => $after, 'issued' => $qty, 'job_card' => $job['job_number'], 'request_id' => $requestId]);
        });
    }

    /** Issue several distinct parts atomically; any invalid line rolls back every stock movement. */
    public static function issueMany(int $jobId, array $items): void
    {
        if (!$items || count($items) > 5) {
            throw new DomainException('Select between 1 and 5 different parts.');
        }

        $quantities = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['part_id'], $item['quantity'])) {
                throw new DomainException('Each issue line needs a part and quantity.');
            }
            $partId = filter_var($item['part_id'], FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
            if ($partId === false || $partId < 1 || $quantity === false || $quantity < 1 || $quantity > 100000) {
                throw new DomainException('Part IDs and quantities must be positive whole numbers; quantity cannot exceed 100000.');
            }
            if (isset($quantities[$partId])) {
                throw new DomainException('Choose each part only once per issue.');
            }
            $quantities[$partId] = $quantity;
        }

        ksort($quantities, SORT_NUMERIC);
        Database::transaction(static function () use ($jobId, $quantities): void {
            foreach ($quantities as $partId => $quantity) {
                self::issue($jobId, (int) $partId, $quantity);
            }
        });
    }

    public static function rejectRequest(int $requestId, string $note): void
    {
        Database::transaction(function () use ($requestId, $note) {
            $req = Database::one('SELECT id, job_id, part_id, quantity, status FROM part_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if (!$req || $req['status'] !== 'pending') { throw new DomainException('This part request is no longer pending.'); }
            $uid = current_user()['id'] ?? null;
            Database::run("UPDATE part_requests SET status='rejected', handled_by=?, handled_at=NOW(), note=COALESCE(NULLIF(?, ''), note) WHERE id=?", [$uid, $note, $requestId]);
            Database::run("INSERT INTO job_updates (job_id, user_id, update_type, note) VALUES (?,?, 'parts', ?)", [$req['job_id'], $uid, 'Part request #' . $requestId . ' rejected' . ($note ? ': ' . $note : '')]);
            AuditLogger::log('part_request_rejected', 'inventory', $requestId, ['status' => 'pending'], ['status' => 'rejected', 'note' => $note]);
        });
    }

    /** Put every not-yet-returned part of a (cancelled) job back into stock. */
    public static function returnJobParts(int $jobId): int
    {
        return (int) Database::transaction(function () use ($jobId) {
            $rows = Database::all('SELECT id, part_id, quantity FROM job_parts WHERE job_id = ? AND returned = 0 FOR UPDATE', [$jobId]);
            $job = Database::one('SELECT job_number FROM job_cards WHERE id = ?', [$jobId]);
            foreach ($rows as $r) {
                $p = self::lockPart((int) $r['part_id']);
                $after = (int) $p['quantity'] + (int) $r['quantity'];
                Database::run('UPDATE spare_parts SET quantity = ? WHERE id = ?', [$after, $p['id']]);
                Database::run('UPDATE job_parts SET returned = 1 WHERE id = ?', [$r['id']]);
                self::ledger((int) $p['id'], 'return', (int) $r['quantity'], $after, $p['unit_cost'], null, $job['job_number'] ?? null, $jobId, 'Returned: job cancelled');
                AuditLogger::log('parts_returned', 'inventory', (int) $p['id'], ['quantity' => (int) $p['quantity']], ['quantity' => $after, 'returned' => (int) $r['quantity'], 'job_card' => $job['job_number'] ?? null]);
            }
            return count($rows);
        });
    }

    public static function lowStock(int $limit = 50): array
    {
        return Database::all('SELECT id, sku, name, quantity, reorder_level, unit FROM spare_parts
            WHERE is_active = 1 AND quantity <= reorder_level ORDER BY (quantity - reorder_level) ASC, name ASC LIMIT ' . (int) $limit);
    }

    private static function lockPart(int $id): array
    {
        $p = Database::one('SELECT * FROM spare_parts WHERE id = ? FOR UPDATE', [$id]);
        if (!$p) { throw new DomainException('Spare part not found.'); }
        return $p;
    }

    private static function ledger(int $partId, string $type, int $qty, int $balance, mixed $unitCost, ?string $supplier, ?string $reference, ?int $jobId, ?string $notes): void
    {
        Database::run('INSERT INTO inventory_transactions (part_id, txn_type, quantity, balance_after, unit_cost, supplier, reference, job_card_id, notes, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$partId, $type, $qty, $balance, $unitCost === null || $unitCost === '' ? null : from_cents(to_cents($unitCost)), $supplier, $reference, $jobId, $notes, current_user()['id'] ?? null]);
    }
}
