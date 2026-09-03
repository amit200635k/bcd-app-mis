<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Audit\AuditLog;
use App\Auth\SessionAuth;
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
    $service->unpublish($formId, $user->id());

    AuditLog::record(
        'survey.unpublish',
        'builder',
        'survey_form',
        (string) $formId,
        ['status' => $form['status']],
        ['code' => $form['code']],
        $user->id()
    );

    flash('success', 'Form "' . $form['title'] . '" moved back to draft. It is hidden from surveyors until published again.');
} catch (Throwable $e) {
    flash('error', exception_message($e));
}
redirect('mis/builder/index.php');
