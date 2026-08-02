<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Audit\AuditLog;
use App\Auth\SessionAuth;
use App\Services\NotificationService;
use App\Services\SurveyService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('survey_builder.publish');

$user = SessionAuth::user();
$service = new SurveyService();
$formId = (int) ($_GET['id'] ?? 0);
$form = $service->findForm($formId);

if ($form === null) {
    flash('error', 'Form not found.');
    redirect('mis/builder/index.php');
}

try {
    $info = $service->versionInfo($formId);
    if (!$info['pending_changes']) {
        flash('error', 'No pending draft changes to sync — edit and save the form first.');
        redirect('mis/builder/index.php');
    }

    $note = trim((string) ($_GET['note'] ?? 'Version sync via MIS'));
    $versionId = $service->publish($formId, $user->id(), $note);

    // Broadcast to all active users (web + mobile) that a new version is live.
    $notif = new NotificationService();
    $notif->send(
        'New form version available: ' . $form['title'],
        'Version ' . (int) ($service->versionInfo($formId)['published_version'] ?? 0) . ' of "' . $form['title'] . '" (' . $form['code'] . ') is live. Sync your app/device to get the latest form.',
        null,
        null,
        $user->id(),
        'info'
    );

    AuditLog::record('survey.sync', 'builder', 'survey_form', (string) $formId, ['status' => $form['status']], ['version_id' => $versionId], $user->id());

    flash('success', 'Draft published as v' . (int) ($service->versionInfo($formId)['published_version'] ?? 0) . ' and synced to all users (web + mobile).');
} catch (Throwable $e) {
    flash('error', exception_message($e));
}
redirect('mis/builder/index.php');
