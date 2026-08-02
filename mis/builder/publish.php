<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

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
    $note = trim((string) ($_GET['note'] ?? 'Publish via MIS'));
    $service->publish($formId, $user->id(), $note);
    flash('success', 'Form "' . $form['title'] . '" published. It is now available for mobile download.');
} catch (Throwable $e) {
    flash('error', exception_message($e));
}
redirect('mis/builder/index.php');
