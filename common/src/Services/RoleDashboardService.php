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
    public function getChartData(User $viewer, ?string $parentType = null, ?int $parentId = null, ?int $formId = null): array
    {
     
        $pdo = Connection::instance();
        $role = $this->roleOf($viewer);
        $scope = $viewer->scope();
        
        // Determine what level to show based on role and parent scope
        $level = $this->resolveChartLevel($role, $parentType);
        
        // Get the appropriate table and column for this level
        [$table, $idCol, $nameCol] = $this->levelTable($level);
        
        // First, get ALL units at this level (with parent filtering if applicable)
        $unitsSql = "SELECT id, name FROM {$table} WHERE is_active = 1";
        $unitsParams = [];
        
        // Apply parent filter to units query
        if ($parentType && $parentId) {
            $parentColMap = [
                'district' => 'district_id',
                'block' => 'block_id',
                'panchayat' => 'panchayat_id',
            ];
            if (isset($parentColMap[$parentType])) {
                $unitsSql .= " AND {$parentColMap[$parentType]} = :parent_id";
                $unitsParams['parent_id'] = $parentId;
            }
        }
        
        // Apply user's scope to units query (non-state-admin users only see their hierarchy)
        if (!$viewer->isStateAdmin()) {
            if ($level === 'district' && !empty($scope['district_id'])) {
                $unitsSql .= " AND id = :district_id";
                $unitsParams['district_id'] = (int) $scope['district_id'];
            } elseif ($level === 'block' && !empty($scope['district_id'])) {
                $unitsSql .= " AND district_id = :district_id";
                $unitsParams['district_id'] = (int) $scope['district_id'];
            } elseif ($level === 'panchayat' && !empty($scope['block_id'])) {
                $unitsSql .= " AND block_id = :block_id";
                $unitsParams['block_id'] = (int) $scope['block_id'];
            } elseif ($level === 'village' && !empty($scope['panchayat_id'])) {
                $unitsSql .= " AND panchayat_id = :panchayat_id";
                $unitsParams['panchayat_id'] = (int) $scope['panchayat_id'];
            }
        }
        
        $unitsSql .= " ORDER BY name";
        
        $stmt = $pdo->prepare($unitsSql);
        $stmt->execute($unitsParams);
        $allUnits = $stmt->fetchAll();
        
        // Build WHERE clause for records query
        [$where, $params] = $this->buildChartWhere($viewer, $level, $parentType, $parentId, $formId);
        
        // Query aggregated data for units that have records
        // Use location from survey_answers (field_key = 'location') for accurate grouping
        
        // Determine the JSON field to group by based on level
        $jsonFieldMap = [
            'district' => 'district_id',
            'block' => 'block_id',
            'panchayat' => 'panchayat_id',
            'village' => 'village_id',
        ];
        $groupByField = $jsonFieldMap[$level] ?? 'district_id';
        $joinTable = $table; // districts, blocks, panchayats, villages
        $joinIdCol = $idCol;
        
        $dataSql = "
            SELECT 
                JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.{$groupByField}')) AS unit_id,
                {$joinTable}.name AS unit_name,
                COUNT(r.id) AS total,
                SUM(r.status = 'submitted') AS submitted,
                SUM(r.status IN ('block_verified','district_verified')) AS verified,
                SUM(r.status IN ('approved','published')) AS approved,
                SUM(r.status = 'rejected') AS rejected
            FROM survey_records r
            JOIN survey_answers sa ON sa.record_id = r.id AND sa.field_key = 'location'
            LEFT JOIN {$joinTable} ON {$joinTable}.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.{$groupByField}')) AS UNSIGNED)
            WHERE {$where}
            GROUP BY unit_id, {$joinTable}.name
        ";
        
        $stmt = $pdo->prepare($dataSql);
        $stmt->execute($params);
        $dataRows = $stmt->fetchAll();
        
        // Map aggregated data by unit_id
        $dataMap = [];
        foreach ($dataRows as $row) {
            $dataMap[(int) $row['unit_id']] = $row;
        }
        
        // Build results including ALL units (with 0 if no data)
        $labels = [];
        $data = [];
        $byStatus = [
            'submitted' => [],
            'verified' => [],
            'approved' => [],
            'rejected' => [],
        ];
        $items = [];
        
        foreach ($allUnits as $unit) {
            $unitId = (int) $unit['id'];
            $unitName = (string) $unit['name'];
            $unitData = $dataMap[$unitId] ?? null;
            
            $labels[] = $unitName;
            $total = $unitData ? (int) ($unitData['total'] ?? 0) : 0;
            $data[] = $total;
            
            $submitted = $unitData ? (int) ($unitData['submitted'] ?? 0) : 0;
            $verified = $unitData ? (int) ($unitData['verified'] ?? 0) : 0;
            $approved = $unitData ? (int) ($unitData['approved'] ?? 0) : 0;
            $rejected = $unitData ? (int) ($unitData['rejected'] ?? 0) : 0;
            
            $byStatus['submitted'][] = $submitted;
            $byStatus['verified'][] = $verified;
            $byStatus['approved'][] = $approved;
            $byStatus['rejected'][] = $rejected;
            
            $items[] = [
                'id' => $unitId,
                'name' => $unitName,
                'total' => $total,
                'by_status' => [
                    'submitted' => $submitted,
                    'verified' => $verified,
                    'approved' => $approved,
                    'rejected' => $rejected,
                ],
            ];
        }
        
        return [
            'level' => $level,
            'labels' => $labels,
            'data' => $data,
            'by_status' => $byStatus,
            'items' => $items,
            'form_id' => $formId,
        ];
    }
    
    /**
     * Resolve which level to show based on user role and parent scope.
     */
    private function resolveChartLevel(string $role, ?string $parentType): string
    {
        if ($role === 'state_admin') {
            // State admin drills down: district -> block -> panchayat -> village
            if ($parentType === 'district') return 'block';
            if ($parentType === 'block') return 'panchayat';
            if ($parentType === 'panchayat') return 'village';
            return 'district';
        }
        if ($role === 'district') {
            if ($parentType === 'block') return 'panchayat';
            if ($parentType === 'panchayat') return 'village';
            return 'block';
        }
        if ($role === 'block') {
            if ($parentType === 'panchayat') return 'village';
            return 'panchayat';
        }
        if ($role === 'panchayat') {
            return 'village';
        }
        if ($role === 'village') {
            return 'village';
        }
        return 'district'; // default fallback
    }
    
    /**
     * Build WHERE clause for chart queries based on viewer scope and parent.
     * Uses JSON_EXTRACT on sa.value_json for location-based filtering.
     */
    private function buildChartWhere(User $viewer, string $level, ?string $parentType, ?int $parentId, ?int $formId): array
    {
        $scope = $viewer->scope();
        $conditions = ['1=1'];
        $params = [];
        
        // Apply user's natural scope using JSON_EXTRACT on sa.value_json
        if (!$viewer->isStateAdmin()) {
            if (!empty($scope['district_id'])) {
                $conditions[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.district_id')) AS UNSIGNED) = :district_id";
                $params['district_id'] = (int) $scope['district_id'];
            }
            if (!empty($scope['block_id'])) {
                $conditions[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) AS UNSIGNED) = :block_id";
                $params['block_id'] = (int) $scope['block_id'];
            }
            if (!empty($scope['panchayat_id'])) {
                $conditions[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.panchayat_id')) AS UNSIGNED) = :panchayat_id";
                $params['panchayat_id'] = (int) $scope['panchayat_id'];
            }
            if (!empty($scope['village_id'])) {
                $conditions[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.village_id')) AS UNSIGNED) = :village_id";
                $params['village_id'] = (int) $scope['village_id'];
            }
        }
        
        // Apply parent filter if provided
        if ($parentType && $parentId) {
            $parentColMap = [
                'district' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.district_id')) AS UNSIGNED)",
                'block' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.block_id')) AS UNSIGNED)",
                'panchayat' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(sa.value_json, '\$.panchayat_id')) AS UNSIGNED)",
            ];
            if (isset($parentColMap[$parentType])) {
                $conditions[] = "{$parentColMap[$parentType]} = :parent_id";
                $params['parent_id'] = $parentId;
            }
        }
        
        // Apply explicit form filter (only when a specific form is selected, not 0)
        if ($formId !== null && $formId > 0) {
            $conditions[] = 'r.form_id = :form_id';
            $params['form_id'] = $formId;
        } else {
            // Apply form access clause only when no explicit form filter
            [$formClause, $formParams] = $this->formAccessClause('r.form_id', $viewer);
            if ($formClause !== '') {
                $conditions[] = $formClause;
                $params = array_merge($params, $formParams);
            }
        }
        
        return [implode(' AND ', $conditions), $params];
    }
    
    /**
     * Build form access clause (copied from ReportService).
     */
    private function formAccessClause(string $column, ?User $viewer): array
    {
        if ($viewer === null || $viewer->isStateAdmin()) {
            return ['', []];
        }
        $formIds = $viewer->assignedFormIds();
        if ($formIds === []) {
            return ["{$column} = 0", []];
        }
        $placeholders = [];
        $params = [];
        foreach ($formIds as $i => $id) {
            $key = ':fid_' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        return [$column . ' IN (' . implode(',', $placeholders) . ')', $params];
    }
    
    /**
     * Get table and column info for a hierarchy level.
     */
    private function levelTable(string $level): array
    {
        return match ($level) {
            'district' => ['districts', 'district_id', 'name'],
            'block' => ['blocks', 'block_id', 'name'],
            'panchayat' => ['panchayats', 'panchayat_id', 'name'],
            'village' => ['villages', 'village_id', 'name'],
            default => ['districts', 'district_id', 'name'],
        };
    }
}
