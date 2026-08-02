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
