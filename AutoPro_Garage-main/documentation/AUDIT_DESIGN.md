# Forensic Audit Trail & Tamper-Evident Hashing Design
## Garage Management System with Secure Data Storage and Auditing

**System:** AutoPro Garage Management System (Group 1)  
**Specification:** Append-Only Cryptographic Audit Log with Forward SHA-256 Hash Chaining  
**Author:** Lead Cybersecurity & Database Architect  

---

## 1. Overview & Forensic Objectives

In high-integrity enterprise environments, traditional database audit logs suffer from a critical vulnerability: **any user or software component with write access to the database can alter or delete historic audit entries to conceal unauthorized activity.**

To fulfill the core requirement of Group 1 (**"Garage Management System with Secure Data Storage and Auditing"**), AutoPro implements a **forensic-grade, tamper-evident audit logging subsystem**.

### Core Forensic Objectives:
1. **Non-Repudiation:** Actions performed by mechanics, receptionists, accountants, and administrators are indelibly linked to their authenticated identity, IP address, and microsecond timestamp.
2. **Immutability:** Once written, an audit record cannot be modified or deleted through any standard application or database channel.
3. **Tamper-Evidence:** If a malicious database administrator directly modifies, truncates, or injects a record into historic audit data, the mathematical integrity of the cryptographic chain breaks, pinpointing the exact record corrupted.
4. **Data Privacy (Zero Secrets Leakage):** Passwords, PINs, tokens, and encryption keys are automatically scrubbed prior to cryptographic hashing and persistent storage.

---

## 2. Audit Trail Data Model

The audit subsystem consists of two tables:
- `audit_logs`: The append-only historical event ledger.
- `audit_chain_head`: A single-row concurrency mutex table that synchronizes sequential hash updates.

### 2.1 Table Schema: `audit_logs`

```sql
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_time DATETIME(6) NOT NULL,
  user_id INT UNSIGNED NULL,
  username VARCHAR(50) NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  action VARCHAR(60) NOT NULL,
  table_name VARCHAR(50) NULL,
  record_id VARCHAR(50) NULL,
  old_value LONGTEXT NULL,
  new_value LONGTEXT NULL,
  prev_hash CHAR(64) NOT NULL,
  record_hash CHAR(64) NOT NULL UNIQUE,
  INDEX idx_audit_time (event_time),
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_action (action),
  INDEX idx_audit_table (table_name),
  INDEX idx_audit_ip (ip_address)
) ENGINE=InnoDB;
```

### 2.2 Table Schema: `audit_chain_head`

```sql
CREATE TABLE audit_chain_head (
  id TINYINT UNSIGNED PRIMARY KEY,
  last_hash CHAR(64) NOT NULL,
  last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_single_head CHECK (id = 1)
) ENGINE=InnoDB;
```

---

## 3. Cryptographic Hash Chain Mathematics & Algorithm

The cryptographic architecture uses a **Merkle-style forward hash chain**, mathematically similar to a private blockchain ledger.

```
┌────────────────────────────┐       ┌────────────────────────────┐
│ Audit Record #1 (Genesis)  │       │ Audit Record #2            │
│ ────────────────────────── │       │ ────────────────────────── │
│ prev_hash: 0000...0000     │       │ prev_hash: Hash of #1      │
│ payload:   User login      │       │ payload:   Job Card create │
│ record_hash: H(0000 + data)│──────>│ record_hash: H(H#1 + data) │
└────────────────────────────┘       └────────────────────────────┘
                                                    │
                                                    ▼
                                     ┌────────────────────────────┐
                                     │ Audit Record #3            │
                                     │ ────────────────────────── │
                                     │ prev_hash: Hash of #2      │
                                     │ payload:   Part Stock-In   │
                                     │ record_hash: H(H#2 + data) │
                                     └────────────────────────────┘
```

### 3.1 Genesis Block
For the first audit record in the system (`id = 1`), `prev_hash` is initialized to the standard 64-character hexadecimal zero string:
```
prev_hash[genesis] = "0000000000000000000000000000000000000000000000000000000000000000"
```

### 3.2 Canonical Payload Serialization
To guarantee deterministic cryptographic hashes across diverse operating systems and PHP runtimes, all field payloads are canonically sorted and serialized before hashing:

1. **Secret Redaction:**
   ```php
   $sensitiveKeys = ['password', 'password_hash', 'password_confirm', 'token', 'csrf_token', 'secret', 'card_number'];
   // Recursively replaces matching keys with '[REDACTED]'
   ```

2. **JSON Normalization:**
   - Keys in associative arrays are sorted using `ksort()`.
   - Floats and numbers are standardized.
   - Serialized with `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.

3. **Canonical Tuple Assembly:**
   The exact byte string fed into the hash algorithm is formatted as:
   ```
   CanonicalString = prev_hash
                   . "|" . id
                   . "|" . event_time
                   . "|" . (user_id ?? "")
                   . "|" . (username ?? "")
                   . "|" . ip_address
                   . "|" . action
                   . "|" . (table_name ?? "")
                   . "|" . (record_id ?? "")
                   . "|" . (old_value_json ?? "")
                   . "|" . (new_value_json ?? "")
   ```

4. **Hash Digest Calculation:**
   ```php
   $record_hash = hash('sha256', $canonicalString);
   ```

### 3.3 Concurrency Control & Head Mutex
Under high-concurrency environments with multiple concurrent web requests, two transactions writing audit logs simultaneously could create a fork in the chain.

To eliminate race conditions, `AuditLogger::log()` uses pessimistic locking on `audit_chain_head`:
```sql
SELECT last_hash, last_id FROM audit_chain_head WHERE id = 1 FOR UPDATE;
```
1. The row lock serializes write access.
2. The current `last_hash` is fetched and set as `prev_hash` for the new record.
3. The new audit record is inserted into `audit_logs`.
4. The mutex row is updated:
   ```sql
   UPDATE audit_chain_head SET last_hash = :new_hash, last_id = :new_id WHERE id = 1;
   ```
5. The transaction commits, releasing the lock for the next event.

---

## 4. Multi-Layer Immutability Controls

### Layer 1: Application-Level Enforcement
- No controller, service, or API endpoint provides an `UPDATE` or `DELETE` path for `audit_logs`.
- The web interface only provides read-only inspection and CSV export features.
- Access to audit inspection requires the `audit.view` permission, which only the `administrator` role holds in the live database (verified 2026-11-09).

### Layer 2: Database Engine Triggers
Even if an attacker gains code execution or exploits an SQL injection vulnerability in the application layer, the database engine itself rejects any attempt to mutate audit records:

```sql
DELIMITER //

CREATE TRIGGER trg_audit_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs is append-only: updates are strictly forbidden';
END//

CREATE TRIGGER trg_audit_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs is append-only: deletions are strictly forbidden';
END//

DELIMITER ;
```

Any attempt to run `UPDATE audit_logs SET ...` or `DELETE FROM audit_logs WHERE ...` immediately aborts with SQLSTATE `45000`.

---

## 5. Verification & Tamper Detection Engine

The system includes a built-in verification engine: `AuditLogger::verifyChain()`.

### 5.1 Verification Algorithm
```php
public static function verifyChain(?int $fromId = 1, ?int $toId = null): array {
    // 1. Fetch chain head
    $head = Database::one('SELECT * FROM audit_chain_head WHERE id = 1');
    
    // 2. Stream audit logs sequentially by id ASC
    $stmt = Database::pdo()->query('SELECT * FROM audit_logs ORDER BY id ASC');
    
    $expectedPrev = str_repeat('0', 64);
    $count = 0;
    
    while ($row = $stmt->fetch()) {
        $count++;
        
        // Check 1: Does this record's prev_hash match the previous record's hash?
        if ($row['prev_hash'] !== $expectedPrev) {
            return [
                'ok' => false,
                'bad_id' => (int)$row['id'],
                'message' => "Broken chain link at ID #{$row['id']}: prev_hash does not match preceding record_hash."
            ];
        }
        
        // Check 2: Recompute the cryptographic hash from row fields
        $recomputed = self::computeHash($row['prev_hash'], $row);
        if ($row['record_hash'] !== $recomputed) {
            return [
                'ok' => false,
                'bad_id' => (int)$row['id'],
                'message' => "Hash mismatch at ID #{$row['id']}: recorded data has been altered."
            ];
        }
        
        $expectedPrev = $row['record_hash'];
    }
    
    // Check 3: Does the final record match the audit_chain_head mutex?
    if ($count > 0 && $expectedPrev !== $head['last_hash']) {
        return [
            'ok' => false,
            'bad_id' => (int)$head['last_id'],
            'message' => "Chain head divergence: head pointer does not match final log entry."
        ];
    }
    
    return ['ok' => true, 'checked' => $count, 'message' => "Audit chain verified intact ({$count} entries)."];
}
```

### 5.2 Tamper Scenarios & Detection Outcomes

| Attack Scenario | Threat Action | Detection Mechanism |
|---|---|---|
| **Payload Tampering** | Attacker changes an invoice amount in historic record #45 | Hash recalculation of record #45 fails: `record_hash !== recomputed`. Flagged immediately at record #45. |
| **Row Deletion** | Attacker deletes row #12 to hide a fraudulent stock issue | Row #13's `prev_hash` points to deleted row #12's hash, but previous row in table is now #11. Flagged at row #13 as broken link. |
| **Row Insertion** | Attacker inserts a forged row between #30 and #31 | Row #31's `prev_hash` does not match forged row's hash. Flagged at row #31. |
| **Truncation / Tail Trimming** | Attacker deletes the last 5 records | `audit_chain_head.last_hash` does not match the final row's `record_hash`. Flagged as head divergence. |

---

## 6. Threat Modeling & Limitations (DBA Threat Model)

### 6.1 What if a Rogue DBA drops the database triggers?
If a malicious DBA with root access drops `trg_audit_no_update` and alters historical rows:
1. The mathematical hash equation breaks immediately upon the next execution of `verifyChain()`.
2. To conceal the change, the DBA would need to:
   - Alter row *k*.
   - Recompute `record_hash` for row *k*.
   - Update `prev_hash` for row *k+1*, recompute its `record_hash`, and repeat sequentially for every row up to the end of the table.
   - Update `audit_chain_head`.
3. Even in this scenario, automated log shippers, offsite replication, and external timestamping notarization expose the divergence between the offsite replica and the altered local table.

### 6.2 Recommended Enterprise Extensions
For mission-critical production deployment:
- **Remote Syslog Shipping:** Mirror audit events via TLS/UDP to a remote WORM (Write Once, Read Many) SIEM (e.g. Splunk, Elastic, AWS CloudWatch).
- **Periodic External Notarization:** Daily publication of the chain head hash to an immutable external ledger or public blockchain.

---

## 7. Catalog of Audited Events

The system logs all state-changing activities across every operational domain:

| Event Domain | Action Identifier | Trigger Condition |
|---|---|---|
| **Authentication** | `auth_login_success` | User credentials successfully verified |
| | `auth_login_failed` | Invalid credentials entered; records targeted username & IP |
| | `auth_logout` | User cleanly terminates session |
| | `auth_lockout` | Account locked due to repeated authentication failures |
| | `password_reset_request` | Password reset link generated |
| | `password_reset_success` | Password changed via reset token |
| **Customers & Vehicles** | `customer_created` | New customer onboarded |
| | `customer_updated` | Customer profile or contact info modified |
| | `vehicle_created` | New vehicle registered |
| | `vehicle_updated` | Vehicle specifications or ownership updated |
| **Appointments** | `appointment_created` | Service appointment scheduled |
| | `appointment_converted` | Appointment converted to active Job Card |
| | `appointment_cancelled` | Appointment cancelled |
| **Workshop Job Cards** | `job_created` | New repair job card opened |
| | `job_updated` | Diagnosis, required repairs, or labor estimate modified |
| | `job_status_changed` | State transition (e.g. `In Progress` -> `Completed`) |
| | `job_service_added` | Standard catalog service attached to job |
| **Spare Parts & Inventory**| `part_created` | New inventory SKU registered |
| | `part_updated` | Part price or reorder threshold modified |
| | `stock_adjusted` | Manual stock take or inventory correction |
| | `stock_issued` | Parts requisitioned and fitted to a job card |
| | `stock_returned` | Parts returned to inventory upon job cancellation |
| **Billing & Finance** | `invoice_created` | Commercial invoice issued |
| | `payment_recorded` | Customer payment receipt entered |
| | `payment_refunded` | Authorized refund issued against an invoice |
| **Suppliers** | `supplier_created` | New vendor added |
| | `supplier_updated` | Vendor profile or contact terms updated |
| **User Administration** | `user_created` | New staff user account provisioned |
| | `user_updated` | User role, permissions, or profile modified |

---
*End of Forensic Audit Trail & Tamper-Evident Hashing Design.*

