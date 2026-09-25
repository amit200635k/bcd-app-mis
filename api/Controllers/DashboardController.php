<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;
use App\Models\User;
use App\Services\RecordService;
use App\Services\ReportService;

final class DashboardController
{
    public static function districtWise(): never
    {
        $user = ApiAuth::requireAuth();
        $formId = Request::query('form_id') !== null ? (int) Request::query('form_id') : null;
        
        $service = new ReportService();
        $data = $service->districtWise($formId, $user);
        
        $formatted = [];
        foreach ($data as $row) {
            $formatted[] = [
                'id' => (int) ($row['district_id'] ?? 0),
                'name' => (string) ($row['district'] ?? 'Unknown'),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }
        
        Response::ok([
            'level' => 'district',
            'items' => $formatted,
            'parent' => null,
        ]);
    }

    public static function blockWise(): never
    {
        $user = ApiAuth::requireAuth();
        $districtId = (int) (Request::query('district_id') ?? 0);
        $formId = Request::query('form_id') !== null ? (int) Request::query('form_id') : null;
        
        if ($districtId <= 0) {
            Response::validation(['district_id' => ['District ID is required.']]);
        }
        
        // Verify user has access to this district
        $scope = $user->scope();
        if (!$user->isStateAdmin() && (!empty($scope['district_id']) && (int) $scope['district_id'] !== $districtId)) {
            Response::forbidden('You do not have access to this district.');
        }
        
        $pdo = Connection::instance();
        $sql = '
            SELECT u2.block_id, b.name AS block, COUNT(r.id) AS total,
                   SUM(r.status = "submitted") AS submitted,
                   SUM(r.status IN ("block_verified","district_verified")) AS verified,
                   SUM(r.status IN ("approved","published")) AS approved,
                   SUM(r.status = "rejected") AS rejected
            FROM survey_records r
            JOIN users u2 ON u2.id = r.user_id
            LEFT JOIN blocks b ON b.id = u2.block_id
            WHERE u2.district_id = :district_id
        ';
        
        $params = ['district_id' => $districtId];
        
        [$formClause, $formParams] = (new ReportService())->formAccessClause('r.form_id', $user);
        $sql .= $formClause;
        $params = array_merge($params, $formParams);
        
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        
        $sql .= ' GROUP BY u2.block_id, b.name ORDER BY total DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        $formatted = [];
        foreach ($data as $row) {
            $formatted[] = [
                'id' => (int) ($row['block_id'] ?? 0),
                'name' => (string) ($row['block'] ?? 'Unknown'),
                'total' => (int) ($row['total'] ?? 0),
                'by_status' => [
                    'submitted' => (int) ($row['submitted'] ?? 0),
                    'verified' => (int) ($row['verified'] ?? 0),
                    'approved' => (int) ($row['approved'] ?? 0),
                    'rejected' => (int) ($row['rejected'] ?? 0),
                ],
            ];
        }
        
        Response::ok([
            'level' => 'block',
            'items' => $formatted,
            'parent' => ['type' => 'district', 'id' => $districtId],
        ]);
    }

    public static function panchayatWise(): never
    {
        $user = ApiAuth::requireAuth();
        $blockId = (int) (Request::query('block_id') ?? 0);
        $formId = Request::query('form_id') !== null ? (int) Request::query('form_id') : null;
        
        if ($blockId <= 0) {
            Response::validation(['block_id' => ['Block ID is required.']]);
        }
        
        // Verify user has access to this block
        $scope = $user->scope();
        if (!$user->isStateAdmin() && (!empty($scope['block_id']) && (int) $scope['block_id'] !== $blockId)) {
            Response::forbidden('You do not have access to this block.');
        }
        
        $pdo = Connection::instance();
        $sql = '
            SELECT u2.panchayat_id, p.name AS panchayat, COUNT(r.id) AS total,
                   SUM(r.status = "submitted") AS submitted,
                   SUM(r.status IN ("block_verified","district_verified")) AS verified,
                   SUM(r.status IN ("approved","published")) AS approved,
                   SUM(r.status = "rejected") AS rejected
            FROM survey_records r
            JOIN users u2 ON u2.id = r.user_id
            LEFT JOIN panchayats p ON p.id = u2.panchayat_id
            WHERE u2.block_id = :block_id
        ';
        
        $params = ['block_id' => $blockId];
        
        [$formClause, $formParams] = (new ReportService())->formAccessClause('r.form_id', $user);
        $sql .= $formClause;
        $params = array_merge($params, $formParams);
        
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        
        $sql .= ' GROUP BY u2.panchayat_id, p.name ORDER BY total DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        $formatted = [];
        foreach ($data as $row) {
            $formatted[] = [
                'id' => (int) ($row['panchayat_id'] ?? 0),
                'name' => (string) ($row['panchayat'] ?? 'Unknown'),
                'total' => (int) ($row['total'] ?? 0),
                'by_status' => [
                    'submitted' => (int) ($row['submitted'] ?? 0),
                    'verified' => (int) ($row['verified'] ?? 0),
                    'approved' => (int) ($row['approved'] ?? 0),
                    'rejected' => (int) ($row['rejected'] ?? 0),
                ],
            ];
        }
        
        Response::ok([
            'level' => 'panchayat',
            'items' => $formatted,
            'parent' => ['type' => 'block', 'id' => $blockId],
        ]);
    }

    public static function villageWise(): never
    {
        $user = ApiAuth::requireAuth();
        $panchayatId = (int) (Request::query('panchayat_id') ?? 0);
        $formId = Request::query('form_id') !== null ? (int) Request::query('form_id') : null;
        
        if ($panchayatId <= 0) {
            Response::validation(['panchayat_id' => ['Panchayat ID is required.']]);
        }
        
        // Verify user has access to this panchayat
        $scope = $user->scope();
        if (!$user->isStateAdmin() && (!empty($scope['panchayat_id']) && (int) $scope['panchayat_id'] !== $panchayatId)) {
            Response::forbidden('You do not have access to this panchayat.');
        }
        
        $pdo = Connection::instance();
        $sql = '
            SELECT u2.village_id, v.name AS village, COUNT(r.id) AS total,
                   SUM(r.status = "submitted") AS submitted,
                   SUM(r.status IN ("block_verified","district_verified")) AS verified,
                   SUM(r.status IN ("approved","published")) AS approved,
                   SUM(r.status = "rejected") AS rejected
            FROM survey_records r
            JOIN users u2 ON u2.id = r.user_id
            LEFT JOIN villages v ON v.id = u2.village_id
            WHERE u2.panchayat_id = :panchayat_id
        ';
        
        $params = ['panchayat_id' => $panchayatId];
        
        [$formClause, $formParams] = (new ReportService())->formAccessClause('r.form_id', $user);
        $sql .= $formClause;
        $params = array_merge($params, $formParams);
        
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        
        $sql .= ' GROUP BY u2.village_id, v.name ORDER BY total DESC';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        $formatted = [];
        foreach ($data as $row) {
            $formatted[] = [
                'id' => (int) ($row['village_id'] ?? 0),
                'name' => (string) ($row['village'] ?? 'Unknown'),
                'total' => (int) ($row['total'] ?? 0),
                'by_status' => [
                    'submitted' => (int) ($row['submitted'] ?? 0),
                    'verified' => (int) ($row['verified'] ?? 0),
                    'approved' => (int) ($row['approved'] ?? 0),
                    'rejected' => (int) ($row['rejected'] ?? 0),
                ],
            ];
        }
        
        Response::ok([
            'level' => 'village',
            'items' => $formatted,
            'parent' => ['type' => 'panchayat', 'id' => $panchayatId],
        ]);
    }
}