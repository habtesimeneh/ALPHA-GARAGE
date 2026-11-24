<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login(); require_post(); verify_csrf(); require_permission('payments.refund');
$id = post_int('invoice_id');
$idempotencyKey = post('idempotency_key');
try { $pid = PaymentService::refund($id, post('amount'), post('method'), post('reason'), $idempotencyKey); flash('success', 'Refund recorded.'); redirect('payments/receipt.php?id=' . $pid); }
catch (DomainException $e) { flash('error', $e->getMessage()); redirect('invoices/view.php?id=' . $id); }
