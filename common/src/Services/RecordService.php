<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use RuntimeException;

/**
 * Survey record intake (mobile sync) + workflow transitions.
 */
final class RecordService
{
    public const STATUSES = [
        'draft', 'submitted', 'block_verified', 'district_verified',
        'approved', 'published', 'rejected',
    ];

    /**
     * Upsert a survey record with its answers.
     * @param array{record_uuid:string, form_id:int, form_version_id:int, answers:array, gps?:array, images?:array} $payload
     */
    public function upsert(int $userId, array $payload): array
    {
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            $uuid = (string) ($payload['record_uuid'] ?? bin2hex(random_bytes(16)));
            $requestedStatus = $payload['status'] ?? 'submitted';
            $status = in_array($requestedStatus, self::STATUSES, true) ? $requestedStatus : 'submitted';

            // Existing record?
            $stmt = $pdo->prepare('SELECT * FROM survey_records WHERE record_uuid = :u LIMIT 1');
            $stmt->execute(['u' => $uuid]);
            $existing = $stmt->fetch();

            if ($existing === false) {
                $stmt = $pdo->prepare(
                    'INSERT INTO survey_records
                        (record_uuid, form_id, form_version_id, user_id, status, current_stage, synced_at)
                     VALUES (:u, :f, :v, :uid, :s, NULL, NOW())'
                );
                $stmt->execute([
                    'u'   => $uuid,
                    'f'   => $payload['form_id'],
                    'v'   => $payload['form_version_id'],
                    'uid' => $userId,
                    's'   => $status,
                ]);
                $recordId = (int) $pdo->lastInsertId();
            } else {
                $recordId = (int) $existing['id'];
                $stmt = $pdo->prepare(
                    'UPDATE survey_records SET status = :s, synced_at = NOW() WHERE id = :id'
                );
                $stmt->execute(['s' => $status, 'id' => $recordId]);
                // Replace prior answers.
                $pdo->prepare('DELETE FROM survey_answers WHERE record_id = :id')->execute(['id' => $recordId]);
            }

            $this->saveAnswers($recordId, $payload['answers'] ?? []);
            if (isset($payload['gps']) && is_array($payload['gps'])) {
                $this->saveGps($recordId, $userId, $payload['gps']);
            }

            $pdo->commit();
            return ['record_uuid' => $uuid, 'record_id' => $recordId, 'status' => $status];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function saveAnswers(int $recordId, array $answers): void
    {
        if ($answers === []) {
            return;
        }
        $pdo = Connection::instance();

        // Preload field map: field_key -> (id, type)
        $fields = [];
        $rows = $pdo->query('SELECT id, field_key, type FROM survey_fields')->fetchAll();
        foreach ($rows as $r) {
            $fields[$r['field_key']] = $r;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO survey_answers
                (record_id, field_id, field_key, value_text, value_number, value_date, value_json)
             VALUES (:rid, :fid, :k, :t, :n, :d, :j)'
        );

        foreach ($answers as $key => $value) {
            $field = $fields[$key] ?? null;
            $fieldId = $field !== null ? (int) $field['id'] : 0;
            $fieldType = $field['type'] ?? 'textbox';

            [$text, $num, $date, $json] = $this->normalizeValue($fieldType, $value);

            $stmt->execute([
                'rid' => $recordId,
                'fid' => $fieldId,
                'k'   => (string) $key,
                't'   => $text,
                'n'   => $num,
                'd'   => $date,
                'j'   => $json,
            ]);
        }
    }

    /** @return array{?string, ?string, ?string, ?string} [text, number, date, json] */
    private function normalizeValue(string $type, mixed $value): array
    {
        if (is_array($value)) {
            return [null, null, null, json_encode($value)];
        }
        if ($value === null) {
            return [null, null, null, null];
        }
        if (in_array($type, ['number', 'decimal'], true)) {
            return [null, is_numeric($value) ? (string) $value : null, null, null];
        }
        if (in_array($type, ['date'], true)) {
            return [null, null, $value !== '' ? (string) $value : null, null];
        }
        return [(string) $value, null, null, null];
    }

    private function saveGps(int $recordId, int $userId, array $gps): void
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'INSERT INTO gps_logs (record_id, user_id, latitude, longitude, accuracy, altitude, captured_at)
             VALUES (:rid, :uid, :lat, :lng, :acc, :alt, :cap)'
        );
        $stmt->execute([
            'rid' => $recordId,
            'uid' => $userId,
            'lat' => $gps['latitude'] ?? null,
            'lng' => $gps['longitude'] ?? null,
            'acc' => $gps['accuracy'] ?? null,
            'alt' => $gps['altitude'] ?? null,
            'cap' => $gps['captured_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function transition(int $recordId, int $actorId, string $toStatus, ?string $remark = null): bool
    {
        if (!in_array($toStatus, self::STATUSES, true)) {
            throw new RuntimeException('Invalid target status.');
        }
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT status FROM survey_records WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $recordId]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                throw new RuntimeException('Record not found.');
            }

            $pdo->prepare('UPDATE survey_records SET status = :s WHERE id = :id')
                ->execute(['s' => $toStatus, 'id' => $recordId]);

            $pdo->prepare(
                'INSERT INTO record_workflow_logs (record_id, from_stage, to_stage, action, acted_by, remark)
                 VALUES (:rid, :from, :to, :act, :actor, :rem)'
            )->execute([
                'rid'   => $recordId,
                'from'  => (string) $current,
                'to'    => $toStatus,
                'act'   => 'transition',
                'actor' => $actorId,
                'rem'   => $remark,
            ]);
            $pdo->commit();
            \App\Audit\AuditLog::record(
                'record.transition',
                'survey_records',
                'survey_record',
                (string) $recordId,
                ['status' => (string) $current],
                ['status' => $toStatus, 'remark' => $remark],
                $actorId
            );
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function listRecords(?int $formId = null, string $status = '', int $page = 1, int $perPage = 50): array
    {
        $pdo = Connection::instance();
        $where = '1=1';
        $params = [];
        if ($formId !== null) {
            $where .= ' AND r.form_id = :f';
            $params['f'] = $formId;
        }
        if ($status !== '') {
            $where .= ' AND r.status = :s';
            $params['s'] = $status;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_records r WHERE {$where}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT r.*, f.title AS form_title, u.full_name AS submitted_by_name
             FROM survey_records r
             JOIN survey_forms f ON f.id = r.form_id
             LEFT JOIN users u ON u.id = r.submitted_by
             WHERE {$where}
             ORDER BY r.updated_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $records = $stmt->fetchAll();

        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'records' => $records];
    }
}
