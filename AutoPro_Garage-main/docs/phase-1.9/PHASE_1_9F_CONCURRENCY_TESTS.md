# ALPHA GARAGE — PHASE 1.9F: CONCURRENCY TESTS
## High-Concurrency Multi-Socket HTTP & Race Condition Verification

**Document Version:** 1.0.0  
**Phase:** 1.9F  
**Target Environment:** Isolated Test Web Server (Port 8089)  
**Database Target:** `garage_management_system_test`  
**Test Suite:** `tests/payment_idempotency_1_9f_hardening_suite.php`  

---

## 1. Concurrency Testing Methodology

To rigorously stress-test the Phase 1.9F payment idempotency hardening, tests were executed using `curl_multi` non-blocking parallel sockets connecting to an isolated PHP CLI web server running on `127.0.0.1:8089`. Requests were dispatched simultaneously across concurrent OS network threads to produce genuine race conditions at the HTTP, application, and database transaction layers.

---

## 2. Concurrency Test Scenarios & Results

### Scenario 1: Two Simultaneous Retries of One Failed Key (`F-IDEM-01`)
- **Objective:** Verify that when two concurrent client retries attempt to reclaim a previously `failed` idempotency key, exactly one request acquires ownership (`status = 'new'`), while the other is serialized into the polling loop.
- **Payload:** Both requests submit identical payload for Invoice `INV-19F-...` ($500.00).
- **Execution:** Dispatched simultaneously via `curl_multi_exec`.
- **Latency:** 551.5 ms.
- **Database Ledger Verification:**
  - `payments` count: exactly 1 row.
  - `invoices` balance: accurately deducted from 1500.00 to 1000.00.
  - `payment_idempotency_keys` status: transitioned to `completed`.
- **Outcome:** **PASSED**. Zero double-credit, zero race collision.

### Scenario 2: Two Simultaneous Requests on an In-Flight Key (`F-IDEM-02`)
- **Objective:** Verify that when two concurrent requests submit the same idempotency key simultaneously before either has completed, the second request polls safely and returns the exact receipt of the first request without executing a duplicate payment.
- **Payload:** Simultaneous POST of $750.00 for Invoice `INV-19F-...` ($3000.00).
- **Execution:** Dispatched concurrently on parallel sockets.
- **Latency:** 622.1 ms.
- **HTTP Response Verification:**
  - Request 1: HTTP 302 Redirect to `receipts/view.php?id=...`
  - Request 2: HTTP 302 Redirect to the exact same URL (`loc1 === loc2`).
- **Database Ledger Verification:**
  - `payments` count: exactly 1 row.
  - `invoices` balance: 2250.00 (deducted exactly once).
- **Outcome:** **PASSED**. Both clients received identical receipts; single financial deduction.

### Scenario 3: Polling Timeout on Long-Running In-Flight Worker (`F-IDEM-03`)
- **Objective:** Verify that a slow worker exceeding the 2500ms polling lease does not allow a competing thread to steal the key and execute a duplicate payment.
- **Execution:** First worker pre-seeds key in `pending` status. Second worker attempts acquisition.
- **Latency:** 3004.9 ms (2500ms lease + polling backoff).
- **Result:** Second worker throws `DomainException: This payment is currently being processed by another request. Please wait a moment and refresh before retrying.`
- **Database Ledger Verification:** Zero payment rows inserted during or after timeout.
- **Outcome:** **PASSED**. Fail-safe protection holds; no duplicate payment permitted under timeout.

### Scenario 4: Concurrent Parallel Requests with Distinct Keys (`F-IDEM-10`)
- **Objective:** Verify that concurrent requests targeting the same invoice with *distinct* idempotency keys serialize cleanly under InnoDB pessimistic row locking (`SELECT ... FOR UPDATE`) without deadlocks, deducting the exact cumulative sum.
- **Payloads:**
  - Request 1: $600.00 (Key 1)
  - Request 2: $400.00 (Key 2)
  - Invoice Total: $3500.00
- **Execution:** Simultaneous parallel POST.
- **Latency:** 494.6 ms.
- **Database Ledger Verification:**
  - Both HTTP responses: HTTP 302 Redirects.
  - Total payment rows: exactly 2 rows.
  - Amount paid: $1000.00.
  - Remaining balance: $2500.00.
  - Deadlock count: 0.
- **Outcome:** **PASSED**. Complete transactional serialization and balance consistency.

---

## 3. Summary of Concurrency Assertions

| Test ID | Concurrency Scenario | HTTP Response | Payments Created | Ledger Consistency | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `F-IDEM-01` | Simultaneous retries of failed key | 302 / 302 | 1 | 1000.00 / 1500.00 | **PASS** |
| `F-IDEM-02` | Simultaneous in-flight race | 302 / 302 (Identical Loc) | 1 | 2250.00 / 3000.00 | **PASS** |
| `F-IDEM-03` | In-flight worker timeout | Exception caught | 0 | 2000.00 / 2000.00 | **PASS** |
| `F-IDEM-10` | Simultaneous distinct keys | 302 / 302 | 2 | 2500.00 / 3500.00 | **PASS** |
