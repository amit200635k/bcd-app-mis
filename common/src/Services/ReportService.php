<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\User;

/**
 * Aggregate report queries, scoped to the viewer's data hierarchy.
 */
final class ReportService
{
    /**
     * Build a SQL fragment restricting records to the viewer's own +
     * sub-user data. Returns ['', []] for state admins (no restriction).
     *
     * @return array{string, array<string,int|string>}
     */
    private function scopeClause(?User $viewer): array
    {
        if ($viewer === null || $viewer->isStateAdmin()) {
            return ['', []];
        }
        $ids = RecordService::scopeUserIds($viewer);
        if ($ids === []) {
            $ids = [$viewer->id()];
        }
        return [' AND r.user_id IN (' . implode(',', array_map('intval', $ids)) . ')', []];
    }

    public function surveyWise(?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = 'SELECT f.id, f.title, f.code,
                    COUNT(r.id) AS total,
                    SUM(r.status = "submitted") AS submitted,
                    SUM(r.status = "block_verified") AS block_verified,
                    SUM(r.status = "district_verified") AS district_verified,
                    SUM(r.status = "approved") AS approved,
                    SUM(r.status = "published") AS published,
                    SUM(r.status = "rejected") AS rejected
             FROM survey_forms f
             LEFT JOIN survey_records r ON r.form_id = f.id' . $scope . '
             GROUP BY f.id, f.title, f.code
             ORDER BY total DESC';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function userWise(?int $formId = null, ?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = 'SELECT u.full_name, u.username, COUNT(r.id) AS total,
                       SUM(r.status = "submitted") AS submitted,
                       SUM(r.status = "published") AS published
                FROM users u
                LEFT JOIN survey_records r ON r.user_id = u.id' . $scope;
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' GROUP BY u.id, u.full_name, u.username ORDER BY total DESC';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function districtWise(?int $formId = null, ?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = 'SELECT u2.district_id, d.name AS district, COUNT(r.id) AS total
                FROM survey_records r
                JOIN users u2 ON u2.id = r.user_id
                LEFT JOIN districts d ON d.id = u2.district_id
                WHERE 1=1' . $scope;
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' GROUP BY u2.district_id, d.name ORDER BY total DESC';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function dailyProgress(?int $formId = null, int $days = 30, ?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = 'SELECT DATE(r.created_at) AS day, COUNT(*) AS total
                FROM survey_records r
                WHERE 1=1' . $scope;
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' GROUP BY DATE(r.created_at) ORDER BY day DESC LIMIT :days';
        $stmt = Connection::instance()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function gpsMissing(?int $formId = null, ?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = 'SELECT r.id, r.record_uuid, f.title, r.status, r.created_at
                FROM survey_records r
                JOIN survey_forms f ON f.id = r.form_id
                LEFT JOIN gps_logs g ON g.record_id = r.id
                WHERE g.id IS NULL' . $scope;
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' ORDER BY r.created_at DESC LIMIT 200';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function duplicates(?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = 'SELECT record_uuid, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
                FROM survey_records r
                WHERE 1=1' . $scope . '
                GROUP BY record_uuid
                HAVING cnt > 1
                ORDER BY cnt DESC';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function statusSummary(?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = 'SELECT status, COUNT(*) AS c FROM survey_records r WHERE 1=1' . $scope . ' GROUP BY status ORDER BY c DESC';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // -----------------------------------------------------------------------
    // Detailed, filterable records report (pivoted answers) + KPIs.
    // -----------------------------------------------------------------------

    public const DETAIL_COLUMNS = [
        'building_name' => 'Building Name',
        'building_code' => 'Building Code',
        'district' => 'District',
        'block' => 'Block',
        'department' => 'Department',
        'dept_owner' => 'Dept Owner',
        'building_category' => 'Building Category',
        'building_subcategory' => 'Sub Category',
        'office_type' => 'Office Type',
        'ownership_type' => 'Ownership',
        'occupancy_status' => 'Occupancy',
        'construction_year' => 'Year Built',
        'num_floors' => 'Floors',
        'built_up_area' => 'Built-up (sqm)',
        'num_rooms' => 'Rooms',
        'contact_number' => 'Contact',
    ];

    /** How each additional column may be filtered (exact / text / range). */
    public const DETAIL_FILTERS = [
        'department'          => ['type' => 'exact', 'label' => 'Department'],
        'dept_owner'          => ['type' => 'exact', 'label' => 'Dept Owner'],
        'building_category'   => ['type' => 'exact', 'label' => 'Building Category'],
        'building_subcategory' => ['type' => 'exact', 'label' => 'Sub Category'],
        'office_type'         => ['type' => 'exact', 'label' => 'Office Type'],
        'ownership_type'      => ['type' => 'exact', 'label' => 'Ownership'],
        'occupancy_status'    => ['type' => 'exact', 'label' => 'Occupancy'],
        'building_name'       => ['type' => 'text', 'label' => 'Building Name'],
        'building_code'       => ['type' => 'text', 'label' => 'Building Code'],
        'contact_number'      => ['type' => 'text', 'label' => 'Contact'],
        'construction_year'   => ['type' => 'range', 'label' => 'Year Built'],
        'num_floors'          => ['type' => 'range', 'label' => 'Floors'],
        'built_up_area'       => ['type' => 'range', 'label' => 'Built-up Area'],
        'num_rooms'           => ['type' => 'range', 'label' => 'Rooms'],
    ];

    /** Curated detail columns that actually exist in the given form. */
    public function detailColumns(int $formId): array
    {
        $pdo = Connection::instance();
        $keys = array_values(array_unique(array_merge(array_keys(self::DETAIL_COLUMNS), ['location'])));
        $holders = implode(',', array_map(static fn ($i) => ':k' . $i, range(0, count($keys) - 1)));
        $sql = 'SELECT DISTINCT f.field_key FROM survey_fields f
                JOIN survey_sections s ON s.id = f.section_id
                WHERE s.form_version_id = COALESCE(
                        (SELECT id FROM survey_versions WHERE form_id = :f AND status = "published" ORDER BY version DESC LIMIT 1),
                        (SELECT id FROM survey_versions WHERE form_id = :f2 ORDER BY version DESC LIMIT 1)
                      )
                  AND f.field_key IN (' . $holders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':f', $formId, \PDO::PARAM_INT);
        $stmt->bindValue(':f2', $formId, \PDO::PARAM_INT);
        foreach ($keys as $i => $k) {
            $stmt->bindValue(':k' . $i, $k);
        }
        $stmt->execute();
        $present = array_flip($stmt->fetchAll(\PDO::FETCH_COLUMN));

        $out = [];
        foreach (self::DETAIL_COLUMNS as $k => $label) {
            if (in_array($k, ['district', 'block'], true)) {
                if (isset($present['location'])) {
                    $out[$k] = $label;
                }
            } elseif (isset($present[$k])) {
                $out[$k] = $label;
            }
        }
        return $out;
    }

    /**
     * Shared WHERE for the detail report + KPIs.
     * NOTE: requires the alias `r` for survey_records and `u` for users.
     *
     * @return array{string, array<string,int|string>}
     */
    private function detailWhere(array $filters): array
    {
        $viewer = $filters['viewer'] ?? null;
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = ' r.form_id = :form_id' . $scope;
        $params['form_id'] = (int) ($filters['form_id'] ?? 0);

        if (!empty($filters['status'])) {
            $sql .= ' AND r.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND DATE(r.created_at) >= :date_from';
            $params['date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND DATE(r.created_at) <= :date_to';
            $params['date_to'] = (string) $filters['date_to'];
        }
        if (!empty($filters['surveyor_id'])) {
            $sql .= ' AND r.submitted_by = :surveyor_id';
            $params['surveyor_id'] = (int) $filters['surveyor_id'];
        }

        $kw = trim((string) ($filters['keyword'] ?? ''));
        if ($kw !== '') {
            $like = '%' . $kw . '%';
            $sql .= " AND (r.record_uuid LIKE :kw0 OR u.full_name LIKE :kw1 OR r.id = :kwid
                     OR EXISTS (SELECT 1 FROM survey_answers ak
                                WHERE ak.record_id = r.id
                                  AND ak.field_key IN ('building_name','building_code')
                                  AND COALESCE(ak.value_text, ak.value_number) LIKE :kw2))";
            $params['kw0'] = $like;
            $params['kw1'] = $like;
            $params['kw2'] = $like;
            $params['kwid'] = ctype_digit($kw) ? (int) $kw : 0;
        }

        // District / block live inside the location-cascade answer's JSON.
        $location = [
            'district' => 'district_name',
            'block'    => 'block_name',
        ];
        foreach ($location as $name => $json) {
            $val = trim((string) ($filters[$name] ?? ''));
            if ($val === '') {
                continue;
            }
            $sql .= ' AND EXISTS (SELECT 1 FROM survey_answers ap
                                   WHERE ap.record_id = r.id AND ap.field_key = :' . $name . '_k
                                     AND JSON_UNQUOTE(JSON_EXTRACT(ap.value_json, \'$.' . $json . '\')) = :' . $name . '_v)';
            $params[$name . '_k'] = 'location';
            $params[$name . '_v'] = $val;
        }

        // Every other key column filters on its own answer value.
        foreach (self::DETAIL_FILTERS as $key => $def) {
            $type = $def['type'];
            if ($type === 'range') {
                $min = trim((string) ($filters[$key . '_min'] ?? ''));
                $max = trim((string) ($filters[$key . '_max'] ?? ''));
                if ($min === '' && $max === '') {
                    continue;
                }
                $sql .= ' AND EXISTS (SELECT 1 FROM survey_answers ap
                                       WHERE ap.record_id = r.id AND ap.field_key = :' . $key . '_k
                                         AND COALESCE(ap.value_number, ap.value_text) REGEXP \'^[0-9]+([.][0-9]+)?$\'';
                $params[$key . '_k'] = $key;
                if ($min !== '') {
                    $sql .= ' AND CAST(COALESCE(ap.value_number, ap.value_text) AS DECIMAL(12,2)) >= :' . $key . '_min';
                    $params[$key . '_min'] = (float) $min;
                }
                if ($max !== '') {
                    $sql .= ' AND CAST(COALESCE(ap.value_number, ap.value_text) AS DECIMAL(12,2)) <= :' . $key . '_max';
                    $params[$key . '_max'] = (float) $max;
                }
                $sql .= ')';
                continue;
            }

            $val = trim((string) ($filters[$key] ?? ''));
            if ($val === '') {
                continue;
            }
            $sql .= ' AND EXISTS (SELECT 1 FROM survey_answers ap
                                   WHERE ap.record_id = r.id AND ap.field_key = :' . $key . '_k
                                     AND COALESCE(ap.value_text, ap.value_number)';
            $sql .= $type === 'text' ? ' LIKE :' . $key . '_v' : ' = :' . $key . '_v';
            $sql .= ')';
            $params[$key . '_k'] = $key;
            $params[$key . '_v'] = $type === 'text' ? '%' . $val . '%' : $val;
        }

        return [$sql, $params];
    }

    /** One row per record with pivoted answers (scoped). */
    public function detailReport(array $filters, int $limit = 500, int $offset = 0): array
    {
        $formId = (int) ($filters['form_id'] ?? 0);
        [$where, $params] = $this->detailWhere($filters);
        $pdo = Connection::instance();
        $cols = $this->detailColumns($formId);

        $joinKeys = [];
        foreach (array_keys($cols) as $k) {
            $joinKeys[$k === 'district' || $k === 'block' ? 'location' : $k] = 1;
        }
        $inList = implode(',', array_map(static fn ($k) => $pdo->quote($k), array_keys($joinKeys)));

        $selects = ['r.id', 'r.record_uuid', 'r.status', 'r.created_at', 'u.full_name AS surveyor'];
        foreach ($cols as $k => $label) {
            if ($k === 'district') {
                $selects[] = "MAX(CASE WHEN a.field_key = 'location' THEN JSON_UNQUOTE(JSON_EXTRACT(a.value_json, '\$.district_name')) END) AS district";
            } elseif ($k === 'block') {
                $selects[] = "MAX(CASE WHEN a.field_key = 'location' THEN JSON_UNQUOTE(JSON_EXTRACT(a.value_json, '\$.block_name')) END) AS block";
            } else {
                $selects[] = "MAX(CASE WHEN a.field_key = '{$k}' THEN COALESCE(a.value_text, a.value_number) END) AS `{$k}`";
            }
        }

        $sql = 'SELECT ' . implode(', ', $selects) . "
                FROM survey_records r
                LEFT JOIN survey_answers a ON a.record_id = r.id AND a.field_key IN ({$inList})
                LEFT JOIN users u ON u.id = r.submitted_by
                WHERE {$where}
                GROUP BY r.id, r.record_uuid, r.status, r.created_at, u.full_name
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT :lim OFFSET :off";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** KPI aggregates over the same scope + filters as the detail report. */
    public function detailKpis(array $filters): array
    {
        [$where, $params] = $this->detailWhere($filters);
        $pdo = Connection::instance();
        $sql = "SELECT COUNT(*) AS total,
                SUM(r.status = 'submitted') AS submitted,
                SUM(r.status IN ('block_verified','district_verified')) AS verified,
                SUM(r.status IN ('approved','published')) AS approved,
                SUM(r.status = 'rejected') AS rejected,
                COALESCE(SUM(CASE WHEN COALESCE(a_bua.value_text, a_bua.value_number) REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                    THEN CAST(COALESCE(a_bua.value_text, a_bua.value_number) AS DECIMAL(12,2)) END), 0) AS built_up_total,
                COALESCE(SUM(CASE WHEN COALESCE(a_rooms.value_text, a_rooms.value_number) REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                    THEN CAST(COALESCE(a_rooms.value_text, a_rooms.value_number) AS DECIMAL(12,2)) END), 0) AS rooms_total,
                COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(a_loc.value_json, '\$.district_name'))) AS districts,
                COUNT(DISTINCT a_dept.value_text) AS departments,
                COUNT(DISTINCT a_cat.value_text) AS categories
                FROM survey_records r
                LEFT JOIN users u ON u.id = r.submitted_by
                LEFT JOIN survey_answers a_bua ON a_bua.record_id = r.id AND a_bua.field_key = 'built_up_area'
                LEFT JOIN survey_answers a_rooms ON a_rooms.record_id = r.id AND a_rooms.field_key = 'num_rooms'
                LEFT JOIN survey_answers a_loc ON a_loc.record_id = r.id AND a_loc.field_key = 'location'
                LEFT JOIN survey_answers a_dept ON a_dept.record_id = r.id AND a_dept.field_key = 'department'
                LEFT JOIN survey_answers a_cat ON a_cat.record_id = r.id AND a_cat.field_key = 'building_category'
                WHERE {$where}";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        $row['total'] = (int) ($row['total'] ?? 0);
        $row['submitted'] = (int) ($row['submitted'] ?? 0);
        $row['verified'] = (int) ($row['verified'] ?? 0);
        $row['approved'] = (int) ($row['approved'] ?? 0);
        $row['rejected'] = (int) ($row['rejected'] ?? 0);
        $row['built_up_total'] = (float) ($row['built_up_total'] ?? 0);
        $row['rooms_total'] = (int) round((float) ($row['rooms_total'] ?? 0));
        $row['avg_built_up'] = $row['total'] > 0 ? round($row['built_up_total'] / $row['total'], 1) : 0;
        $row['departments'] = (int) ($row['departments'] ?? 0);
        $row['categories'] = (int) ($row['categories'] ?? 0);
        return $row;
    }

    /** Distinct non-null answer values for a field (filter dropdown). */
    public function detailFieldDistinct(string $fieldKey, ?int $formId = null, ?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $sql = "SELECT DISTINCT COALESCE(a.value_text, a.value_number) AS v
                FROM survey_answers a JOIN survey_records r ON r.id = a.record_id
                WHERE a.field_key = :k AND COALESCE(a.value_text, a.value_number) IS NOT NULL
                  AND COALESCE(a.value_text, a.value_number) <> ''" . $scope;
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' ORDER BY v';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->bindValue(':k', $fieldKey);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return array_map(static fn ($r) => (string) $r['v'], $stmt->fetchAll());
    }

    /** Distinct district/block names from the location-cascade answer. */
    public function detailLocationDistinct(string $level, ?int $formId = null, ?User $viewer = null): array
    {
        [$scope, $params] = $this->scopeClause($viewer);
        $path = '$.' . ($level === 'block' ? 'block_name' : 'district_name');
        $sql = "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(a.value_json, :path)) AS v
                FROM survey_answers a JOIN survey_records r ON r.id = a.record_id
                WHERE a.field_key = 'location' AND a.value_json IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(a.value_json, :path2)) IS NOT NULL" . $scope;
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' ORDER BY v';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->bindValue(':path', $path);
        $stmt->bindValue(':path2', $path);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return array_map(static fn ($r) => (string) $r['v'], $stmt->fetchAll());
    }

    /** Data contributors within the viewer's scope (surveyors + hierarchy roles). */
    public function detailSurveyors(?int $formId = null, ?User $viewer = null): array
    {
        $pdo = Connection::instance();
        $roleCodes = "'surveyor','district','block','panchayat','village','department_admin'";
        if ($viewer !== null && !$viewer->isStateAdmin()) {
            $ids = RecordService::scopeUserIds($viewer);
            $ids = $ids === [] ? [$viewer->id()] : $ids;
            $sql = 'SELECT u.id, u.full_name, u.username FROM users u
                    JOIN user_roles ur ON ur.user_id = u.id JOIN roles rl ON rl.id = ur.role_id
                    WHERE u.deleted_at IS NULL AND u.id IN (' . implode(',', array_map('intval', $ids)) . ')
                      AND rl.code IN (' . $roleCodes . ')
                    ORDER BY u.full_name';
        } else {
            $sql = 'SELECT u.id, u.full_name, u.username FROM users u
                    JOIN user_roles ur ON ur.user_id = u.id JOIN roles rl ON rl.id = ur.role_id
                    WHERE u.deleted_at IS NULL AND rl.code IN (' . $roleCodes . ')
                    ORDER BY u.full_name';
        }
        return $pdo->query($sql)->fetchAll();
    }

    /** Convert a report array to CSV string. */
    public function toCsv(array $rows, array $headers): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
