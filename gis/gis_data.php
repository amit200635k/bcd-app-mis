<?php

declare(strict_types=1);

/**
 * GIS points feed for the MIS dashboard (session-authenticated).
 */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;

SessionAuth::requireAuth();
SessionAuth::requirePermission('gis.view');

$user = SessionAuth::user();
$pdo = Connection::instance();

$formId = (int) ($_GET['form_id'] ?? 0);
$status = (string) ($_GET['status'] ?? '');

$where = 'g.latitude IS NOT NULL AND g.longitude IS NOT NULL';
$params = [];
if ($formId > 0) {
    $where .= ' AND r.form_id = :f';
    $params['f'] = $formId;
}
if ($status !== '') {
    $where .= ' AND r.status = :s';
    $params['s'] = $status;
}
// Data scope: viewers only see their own + sub-users' points.
if (!$user->isStateAdmin()) {
    $ids = \App\Services\RecordService::scopeUserIds($user);
    if ($ids === []) {
        $ids = [$user->id()];
    }
    $where .= ' AND r.user_id IN (' . implode(',', array_map('intval', $ids)) . ')';
}

$stmt = $pdo->prepare(
    "SELECT r.id, r.record_uuid, r.status, f.title AS form_title,
            g.latitude, g.longitude, g.accuracy, r.updated_at
     FROM survey_records r
     JOIN gps_logs g ON g.record_id = r.id
     JOIN survey_forms f ON f.id = r.form_id
     WHERE {$where}
     ORDER BY r.updated_at DESC"
);
$stmt->execute($params);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'count'   => $stmt->rowCount(),
    'points'  => $stmt->fetchAll(),
]);
