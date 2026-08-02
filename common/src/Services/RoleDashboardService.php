<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\User;

/**
 * Role-aware dashboard stats for the MIS landing pages
 * (district / block / panchayat / village / surveyor).
 */
final class RoleDashboardService
{
    public const HIERARCHY = ['district', 'block', 'panchayat', 'village'];

    /** Highest-priority role used for routing a user to their landing page. */
    public function roleOf(User $viewer): string
    {
        if ($viewer->isStateAdmin()) {
            return 'state_admin';
        }
        foreach (self::HIERARCHY as $r) {
            if ($viewer->hasRole($r)) {
                return $r;
            }
        }
        if ($viewer->hasRole('surveyor')) {
            return 'surveyor';
        }
        if ($viewer->hasRole('department_admin')) {
            return 'department_admin';
        }
        return 'viewer';
    }

    /**
     * Scoped dashboard aggregates for the viewer.
     *
     * @return array<string,mixed> keys: role, unit, unit_type, children,
     *                             users{total,surveyors}, records{total,by_status,per_form,latest}, forms
     */
    public function stats(User $viewer): array
    {
        $pdo = Connection::instance();
        $role = $this->roleOf($viewer);
        $scope = $viewer->scope();

        $unit = '';
        $unitType = null;
        $children = [];
        $users = ['total' => 0, 'surveyors' => 0];
        $ids = [$viewer->id()];

        if ($viewer->isStateAdmin()) {
            $ids = [];
            $unitType = 'state';
        } elseif ($role === 'surveyor') {
            // Surveyors only ever see their own submissions.
        } else {
            [$unitType, $id] = $this->lowestScope($scope);
            if ($unitType !== null) {
                $unit = $this->unitName($unitType, (int) $id);
                $cond = $this->unitCond($unitType, (int) $id);
                $users['total'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM users u WHERE u.status='active' AND u.deleted_at IS NULL AND {$cond}"
                )->fetchColumn();
                $users['surveyors'] = (int) $pdo->query(
                    "SELECT COUNT(DISTINCT u.id) FROM users u
                     JOIN user_roles ur ON ur.user_id = u.id
                     JOIN roles r ON r.id = ur.role_id
                     WHERE r.code = 'surveyor' AND u.status='active' AND u.deleted_at IS NULL AND {$cond}"
                )->fetchColumn();
                $children = $this->children($unitType, (int) $id);
                $ids = RecordService::scopeUserIds($viewer);
            }
        }

        $records = ['total' => 0, 'by_status' => [], 'per_form' => [], 'latest' => []];
        $in = $ids === [] ? null : implode(',', array_map('intval', $ids));
        if ($ids === [] || $in !== '') {
            $where = $in === null ? '1=1' : 'r.user_id IN (' . $in . ')';
            $records['total'] = (int) $pdo->query("SELECT COUNT(*) FROM survey_records r WHERE {$where}")->fetchColumn();
            $records['by_status'] = $pdo->query(
                "SELECT r.status, COUNT(*) AS c FROM survey_records r WHERE {$where} GROUP BY r.status ORDER BY c DESC"
            )->fetchAll();
            $records['per_form'] = $pdo->query(
                "SELECT f.title AS form_title, COUNT(r.id) AS total FROM survey_records r
                 JOIN survey_forms f ON f.id = r.form_id
                 WHERE {$where} GROUP BY f.id, f.title ORDER BY total DESC LIMIT 5"
            )->fetchAll();
            $records['latest'] = $pdo->query(
                "SELECT r.id, r.record_uuid, r.status, r.created_at, f.title AS form_title, u2.full_name AS submitter
                 FROM survey_records r
                 JOIN survey_forms f ON f.id = r.form_id
                 LEFT JOIN users u2 ON u2.id = r.submitted_by
                 WHERE {$where} ORDER BY r.created_at DESC LIMIT 5"
            )->fetchAll();
        }

        $forms = 0;
        foreach ($pdo->query("SELECT id FROM survey_forms WHERE status = 'published' AND is_active = 1")->fetchAll() as $row) {
            if ($viewer->canAccessForm((int) $row['id'])) {
                $forms++;
            }
        }

        return [
            'role' => $role,
            'unit' => $unit,
            'unit_type' => $unitType,
            'children' => $children,
            'users' => $users,
            'records' => $records,
            'forms' => $forms,
        ];
    }

    /** Lowest non-null admin unit in the viewer's scope. @return array{string|null,int|null} */
    private function lowestScope(array $scope): array
    {
        foreach (['village_id' => 'village', 'panchayat_id' => 'panchayat', 'block_id' => 'block', 'district_id' => 'district'] as $col => $type) {
            if (!empty($scope[$col])) {
                return [$type, (int) $scope[$col]];
            }
        }
        return [null, null];
    }

    private function unitName(string $type, int $id): string
    {
        $table = ['district' => 'districts', 'block' => 'blocks', 'panchayat' => 'panchayats', 'village' => 'villages'][$type];
        $stmt = Connection::instance()->prepare("SELECT name FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $name = $stmt->fetchColumn();
        return $name === false ? '' : (string) $name;
    }

    private function unitCond(string $type, int $id): string
    {
        $col = ['district' => 'u.district_id', 'block' => 'u.block_id', 'panchayat' => 'u.panchayat_id', 'village' => 'u.village_id'][$type];
        return "{$col} = {$id}";
    }

    /** @return list<array{type:string,label:string,count:int}> */
    private function children(string $unitType, int $id): array
    {
        $pdo = Connection::instance();
        $out = [];
        if ($unitType === 'district') {
            $out[] = ['type' => 'block', 'label' => 'Blocks', 'count' => (int) $pdo->query("SELECT COUNT(*) FROM blocks WHERE district_id = {$id}")->fetchColumn()];
            $out[] = ['type' => 'panchayat', 'label' => 'Panchayats', 'count' => (int) $pdo->query("SELECT COUNT(*) FROM panchayats p JOIN blocks b ON b.id = p.block_id WHERE b.district_id = {$id}")->fetchColumn()];
            $out[] = ['type' => 'village', 'label' => 'Villages', 'count' => (int) $pdo->query("SELECT COUNT(*) FROM villages v JOIN panchayats p ON p.id = v.panchayat_id JOIN blocks b ON b.id = p.block_id WHERE b.district_id = {$id}")->fetchColumn()];
        } elseif ($unitType === 'block') {
            $out[] = ['type' => 'panchayat', 'label' => 'Panchayats', 'count' => (int) $pdo->query("SELECT COUNT(*) FROM panchayats WHERE block_id = {$id}")->fetchColumn()];
            $out[] = ['type' => 'village', 'label' => 'Villages', 'count' => (int) $pdo->query("SELECT COUNT(*) FROM villages v JOIN panchayats p ON p.id = v.panchayat_id WHERE p.block_id = {$id}")->fetchColumn()];
        } elseif ($unitType === 'panchayat') {
            $out[] = ['type' => 'village', 'label' => 'Villages', 'count' => (int) $pdo->query("SELECT COUNT(*) FROM villages WHERE panchayat_id = {$id}")->fetchColumn()];
        }
        return $out;
    }
}
