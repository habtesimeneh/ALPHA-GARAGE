<?php
declare(strict_types=1);
/** Single POST endpoint for job-card workflow actions. Every action re-checks permission AND object access (IDOR). */
require __DIR__ . '/../../app/bootstrap.php';
$user = require_login();
require_post();

$id = post_int('id');
$job = JobService::find($id);
if (!$job) { abort(404, 'Job card not found.'); }
if (!JobService::canAccess($job, $user)) { deny('jobs.view_all (object access)', 'job_cards', $id); }

$do = post('do');
try {
    switch ($do) {
        case 'status':
            $target = post('status');
            if (!in_array($target, JobService::STATUSES, true)) { abort(400, 'Invalid status.'); }
            if (!JobService::canChangeStatus($job, $user, $target)) { deny('jobs.progress', 'job_cards', $id); }
            JobService::changeStatus($id, $target, post('note'));
            flash('success', "Status changed to $target.");
            break;
        case 'note':
            require_permission('jobs.progress');
            $e = validate(['note' => post('note')], ['note' => 'required|max:2000']);
            if ($e) { throw new DomainException(reset($e)); }
            JobService::addNote($id, post('note'));
            flash('success', 'Note added.');
            break;
        case 'labor':
            require_permission('jobs.progress');
            JobService::addLabor($id, post('hours'), post('note'));
            flash('success', 'Labor recorded.');
            break;
        case 'diagnosis':
            require_permission('jobs.progress');
            $e = validate(['d' => post('diagnosis'), 'r' => post('required_repairs')], ['d' => 'max:2000', 'r' => 'max:2000']);
            if ($e) { throw new DomainException('Text is too long (max 2000 characters).'); }
            JobService::updateDiagnosis($id, post('diagnosis'), post('required_repairs'));
            flash('success', 'Diagnosis saved.');
            break;
        case 'assign':
            require_permission('jobs.assign');
            JobService::assign($id, post_int('mechanic_id') ?: null);
            flash('success', 'Assignment saved.');
            break;
        case 'request_part':
            require_permission('jobs.request_parts');
            JobService::requestPart($id, post_int('part_id'), post_int('quantity'), post('note'));
            flash('success', 'Part request sent to the inventory manager.');
            break;
        case 'attach_service':
            require_permission('jobs.progress');
            $srvId = post_int('service_id');
            if (!$srvId) { throw new DomainException('Please select a service.'); }
            CatalogService::attachToJob($id, $srvId, post_int('service_mechanic_id') ?: null, post('service_notes'));
            flash('success', 'Service added to job card.');
            break;
        case 'service_status':
            require_permission('jobs.progress');
            $jsId = post_int('job_service_id');
            $st = post('service_status');
            CatalogService::updateServiceStatus($id, $jsId, $st);
            flash('success', 'Service item status updated.');
            break;
        default:
            abort(400, 'Unknown action.');
    }
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('job-cards/view.php?id=' . $id);
