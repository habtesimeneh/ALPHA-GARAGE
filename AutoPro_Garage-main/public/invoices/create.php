<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login(); require_post(); require_permission('invoices.create');
$jobId = post_int('job_id');
try { $id = InvoiceService::createForJob($jobId); flash('success', 'Invoice generated.'); redirect('invoices/view.php?id=' . $id); }
catch (DomainException $e) { flash('error', $e->getMessage()); redirect('job-cards/view.php?id=' . $jobId); }
