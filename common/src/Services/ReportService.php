<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Aggregate report queries.
 */
final class ReportService
{
    public function surveyWise(): array
    {
        return Connection::instance()->query(
            'SELECT f.id, f.title, f.code,
                    COUNT(r.id) AS total,
                    SUM(r.status = "submitted") AS submitted,
                    SUM(r.status = "block_verified") AS block_verified,
                    SUM(r.status = "district_verified") AS district_verified,
                    SUM(r.status = "approved") AS approved,
                    SUM(r.status = "published") AS published,
                    SUM(r.status = "rejected") AS rejected
             FROM survey_forms f
             LEFT JOIN survey_records r ON r.form_id = f.id
             GROUP BY f.id, f.title, f.code
             ORDER BY total DESC'
        )->fetchAll();
    }

    public function userWise(?int $formId = null): array
    {
        $sql = 'SELECT u.full_name, u.username, COUNT(r.id) AS total,
                       SUM(r.status = "submitted") AS submitted,
                       SUM(r.status = "published") AS published
                FROM users u
                LEFT JOIN survey_records r ON r.user_id = u.id';
        $params = [];
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' GROUP BY u.id, u.full_name, u.username ORDER BY total DESC';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function districtWise(?int $formId = null): array
    {
        $sql = 'SELECT d.name AS district, COUNT(r.id) AS total
                FROM districts d
                LEFT JOIN survey_records r ON 1=0';
        // Records do not carry district FK directly; join through user.
        $sql = 'SELECT u2.district_id, d.name AS district, COUNT(r.id) AS total
                FROM survey_records r
                JOIN users u2 ON u2.id = r.user_id
                LEFT JOIN districts d ON d.id = u2.district_id';
        $params = [];
        if ($formId !== null) {
            $sql .= ' WHERE r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' GROUP BY u2.district_id, d.name ORDER BY total DESC';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function dailyProgress(?int $formId = null, int $days = 30): array
    {
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS total
                FROM survey_records';
        $params = [];
        if ($formId !== null) {
            $sql .= ' WHERE form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' GROUP BY DATE(created_at) ORDER BY day DESC LIMIT :days';
        $stmt = Connection::instance()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function gpsMissing(?int $formId = null): array
    {
        $sql = 'SELECT r.id, r.record_uuid, f.title, r.status, r.created_at
                FROM survey_records r
                JOIN survey_forms f ON f.id = r.form_id
                LEFT JOIN gps_logs g ON g.record_id = r.id
                WHERE g.id IS NULL';
        $params = [];
        if ($formId !== null) {
            $sql .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        $sql .= ' ORDER BY r.created_at DESC LIMIT 200';
        $stmt = Connection::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function duplicates(): array
    {
        return Connection::instance()->query(
            'SELECT record_uuid, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
             FROM survey_records
             GROUP BY record_uuid
             HAVING cnt > 1
             ORDER BY cnt DESC'
        )->fetchAll();
    }

    public function statusSummary(): array
    {
        return Connection::instance()->query(
            'SELECT status, COUNT(*) AS c FROM survey_records GROUP BY status ORDER BY c DESC'
        )->fetchAll();
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
