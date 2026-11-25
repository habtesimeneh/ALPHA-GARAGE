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
        case 'send_to_manager':
            AppointmentService::sendToManager($id, post('notes'));
            flash('success', 'Appointment submitted to Manager for approval. SMS sent to Manager.');
            redirect('appointments/');
            break;

        case 'manager_approve':
            AppointmentService::managerApprove($id, post('notes'));
            flash('success', 'Appointment approved by Manager. SMS notification sent to Receptionist and Customer.');
            redirect('appointments/');
            break;

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
            $mechId = post_int('mechanic_id') ?: null;
            $jobId = AppointmentService::convertToJob($id, $mechId);
            flash('success', 'Appointment converted to Job Card with Claim Ticket generated! Mechanic and Customer notified via SMS.');
            redirect('job-cards/view.php?id=' . $jobId);
            break;

        default:
            abort(400, 'Unknown action.');
    }
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    redirect('appointments/');
}

