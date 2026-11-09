<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_permission('appointments.manage');
require_post();

$id = post_int('id');
$action = post('action');
if (!$id || !$action) { abort(400, 'Invalid request.'); }

try {
    switch ($action) {
        case 'confirm':
            AppointmentService::updateStatus($id, 'confirmed');
            flash('success', 'Appointment confirmed.');
            redirect('appointments/');
            break;

        case 'cancel':
            AppointmentService::updateStatus($id, 'cancelled');
            flash('success', 'Appointment cancelled.');
            redirect('appointments/');
            break;

        case 'convert':
            $jobId = AppointmentService::convertToJob($id);
            flash('success', 'Appointment successfully converted to Job Card!');
            redirect('job-cards/view.php?id=' . $jobId);
            break;

        default:
            abort(400, 'Unknown action.');
    }
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    redirect('appointments/');
}

