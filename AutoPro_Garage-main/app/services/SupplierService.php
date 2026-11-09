<?php
declare(strict_types=1);

/**
 * Supplier Management Service.
 * Manages automotive parts suppliers, contact details, active status, and audit trails.
 */
final class SupplierService
{
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT s.*, 
                       (SELECT COUNT(*) FROM spare_parts p WHERE p.supplier_id = s.id) AS parts_count,
                       (SELECT COUNT(*) FROM inventory_transactions it WHERE it.supplier = s.name OR it.supplier = s.code) AS transactions_count
                FROM suppliers s';
        if ($activeOnly) {
            $sql .= ' WHERE s.is_active = 1';
        }
        $sql .= ' ORDER BY s.name ASC';
        return Database::all($sql);
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM spare_parts p WHERE p.supplier_id = s.id) AS parts_count,
                    (SELECT COUNT(*) FROM inventory_transactions it WHERE it.supplier = s.name OR it.supplier = s.code) AS transactions_count
             FROM suppliers s WHERE s.id = ?', [$id]
        );
    }

    public static function save(?int $id, array $d): int
    {
        return (int) Database::transaction(function () use ($id, $d) {
            $code = mb_strtoupper(trim($d['code']));
            $name = trim($d['name']);
            $contact = trim($d['contact_person'] ?? '');
            $phone = trim($d['phone'] ?? '');
            $email = trim($d['email'] ?? '');
            $address = trim($d['address'] ?? '');

            // Uniqueness check for supplier code
            $existing = Database::one('SELECT id FROM suppliers WHERE code = ? AND id != ?', [$code, $id ?? 0]);
            if ($existing) {
                throw new DomainException('A supplier with code "' . $code . '" already exists.');
            }

            if ($id === null) {
                $id = Database::insert(
                    'INSERT INTO suppliers (code, name, contact_person, phone, email, address, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)',
                    [$code, $name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null]
                );
                AuditLogger::log('supplier_created', 'suppliers', $id, null, [
                    'code' => $code, 'name' => $name, 'contact_person' => $contact, 'phone' => $phone
                ]);
            } else {
                $old = self::find($id);
                if (!$old) {
                    throw new DomainException('Supplier not found.');
                }
                Database::run(
                    'UPDATE suppliers SET code=?, name=?, contact_person=?, phone=?, email=?, address=? WHERE id=?',
                    [$code, $name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null, $id]
                );
                $new = self::find($id);
                AuditLogger::log('supplier_updated', 'suppliers', $id, $old, $new);
            }
            return $id;
        });
    }

    public static function toggleActive(int $id): bool
    {
        return (bool) Database::transaction(function () use ($id) {
            $s = Database::one('SELECT id, name, is_active FROM suppliers WHERE id = ? FOR UPDATE', [$id]);
            if (!$s) {
                throw new DomainException('Supplier not found.');
            }
            $newActive = $s['is_active'] ? 0 : 1;
            Database::run('UPDATE suppliers SET is_active = ? WHERE id = ?', [$newActive, $id]);
            AuditLogger::log($newActive ? 'supplier_reactivated' : 'supplier_deactivated', 'suppliers', $id,
                ['is_active' => (int) $s['is_active']], ['is_active' => $newActive]);
            return (bool) $newActive;
        });
    }
}

