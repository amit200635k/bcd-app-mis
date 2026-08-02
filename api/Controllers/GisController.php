<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;

final class GisController
{
    /** Survey records with GPS coordinates for map layers. */
    public static function points(): never
    {
        $user = ApiAuth::requireAuth();
        $pdo = Connection::instance();

        $formId = Request::query('form_id') !== null ? (int) Request::query('form_id') : null;
        $status = (string) Request::query('status', '');

        $where = 'g.latitude IS NOT NULL AND g.longitude IS NOT NULL';
        $params = [];
        if ($formId !== null) {
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
                    g.latitude, g.longitude, g.accuracy,
                    r.updated_at
             FROM survey_records r
             JOIN gps_logs g ON g.record_id = r.id
             JOIN survey_forms f ON f.id = r.form_id
             WHERE {$where}
             ORDER BY r.updated_at DESC"
        );
        $stmt->execute($params);
        $points = $stmt->fetchAll();

        Response::ok(['count' => count($points), 'points' => $points]);
    }
}
