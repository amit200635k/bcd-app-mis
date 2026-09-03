<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Exceptions\ValidationException;
use App\Models\User;
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
     *
     * Every record also receives an additional unique Survey ID (`survey_code`)
     * shaped like JH/{district_id}/{MM}/{YY}/{daywise_count}/{unix_last4},
     * e.g. JH/20/08/26/0003/4821 — generated server-side (drafts included)
     * and preserved unchanged across re-syncs.
     *
     * @param array{record_uuid:string, form_id:int, form_version_id:int, answers:array, gps?:array, images?:array} $payload
     * @return array{record_uuid:string, record_id:int, status:string, survey_code:string}
     */
    public function upsert(int $userId, array $payload): array
    {
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            $uuid = (string) ($payload['record_uuid'] ?? bin2hex(random_bytes(16)));
            $requestedStatus = $payload['status'] ?? 'submitted';
            $status = in_array($requestedStatus, self::STATUSES, true) ? $requestedStatus : 'submitted';

            // The form version definition is needed twice: conditional
            // evaluation of answers and stamping auto_number fields.
            $definition = (new SurveyService())->formDefinition(
                (int) $payload['form_id'],
                (int) $payload['form_version_id']
            );
            $rawAnswers = $payload['answers'] ?? [];

            // Existing record?
            $stmt = $pdo->prepare('SELECT id, survey_code FROM survey_records WHERE record_uuid = :u LIMIT 1');
            $stmt->execute(['u' => $uuid]);
            $existing = $stmt->fetch();

            if ($existing === false) {
                $surveyCode = $this->nextSurveyCode($userId, $rawAnswers);
                $stmt = $pdo->prepare(
                    'INSERT INTO survey_records
                        (record_uuid, survey_code, form_id, form_version_id, user_id, submitted_by, status, current_stage, synced_at)
                     VALUES (:u, :code, :f, :v, :uid, :sid, :s, NULL, NOW())'
                );
                $stmt->execute([
                    'u'    => $uuid,
                    'code' => $surveyCode,
                    'f'    => $payload['form_id'],
                    'v'    => $payload['form_version_id'],
                    'uid'  => $userId,
                    'sid'  => $userId,
                    's'    => $status,
                ]);
                $recordId = (int) $pdo->lastInsertId();
            } else {
                $recordId = (int) $existing['id'];
                $surveyCode = (string) ($existing['survey_code'] ?? '');
                if ($surveyCode === '') {
                    // Legacy row (pre-survey_code) being re-synced: backfill once.
                    $surveyCode = $this->nextSurveyCode($userId, $rawAnswers);
                }
                $stmt = $pdo->prepare(
                    'UPDATE survey_records
                     SET status = :s, submitted_by = COALESCE(submitted_by, :sid), survey_code = :code, synced_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute(['s' => $status, 'sid' => $userId, 'code' => $surveyCode, 'id' => $recordId]);
                // Replace prior answers.
                $pdo->prepare('DELETE FROM survey_answers WHERE record_id = :id')->execute(['id' => $recordId]);
            }

            // Persist condition-evaluated answers for this form version.
            $evaluated = $this->applyConditions($definition, $rawAnswers, $status);

            $this->saveAnswers($recordId, $evaluated['answers']);
            $this->stampAutoNumbers($recordId, $definition, $evaluated['visible'], $surveyCode);
            $this->applyCalculatedFields($recordId, $definition, $evaluated['visible'], $evaluated['answers']);
            if (isset($payload['gps']) && is_array($payload['gps'])) {
                $this->saveGps($recordId, $userId, $payload['gps']);
            }

            $pdo->commit();
            return ['record_uuid' => $uuid, 'record_id' => $recordId, 'status' => $status, 'survey_code' => $surveyCode];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Build the next unique Survey ID: JH/{district}/{MM}/{YY}/{seq}/{unix4}.
     *
     * The daywise segment comes from the atomic `survey_id_sequences` counter
     * (one row per calendar day — the reset is implicit). District resolution:
     * the submitted location_cascade answer's district first, then the
     * submitting user's assigned district, else 00.
     */
    private function nextSurveyCode(int $userId, array $answers): string
    {
        $pdo = Connection::instance();
        $now = time();

        $districtId = 0;
        foreach ($answers as $value) {
            if (is_array($value)) {
                $candidate = (int) ($value['district_id'] ?? 0);
                if ($candidate > 0) {
                    $districtId = $candidate;
                    break;
                }
            }
        }
        if ($districtId === 0) {
            $stmt = $pdo->prepare('SELECT district_id FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $districtId = (int) ($stmt->fetchColumn() ?: 0);
        }

        $today = date('Y-m-d', $now);

$pdo->prepare('INSERT INTO survey_id_sequences (counter_date, `last_value`) VALUES (:d, 1) ON DUPLICATE KEY UPDATE `last_value` = `last_value` + 1' )->execute(['d' => $today]);

$stmt = $pdo->prepare(    'SELECT `last_value`     FROM survey_id_sequences     WHERE counter_date = :d     LIMIT 1');

$stmt->execute(['d' => $today]);

$seq = max(1, (int) $stmt->fetchColumn());


        // $pdo->prepare(
        //     'INSERT INTO survey_id_sequences (counter_date, last_value) VALUES (:d, 1)
        //      ON DUPLICATE KEY UPDATE last_value = last_value + 1'
        // )->execute(['d' => $today]);
        // $stmt = $pdo->prepare('SELECT last_value FROM survey_id_sequences WHERE counter_date = :d LIMIT 1');
        // $stmt->execute(['d' => $today]);
        // $seq = max(1, (int) $stmt->fetchColumn());

        return sprintf(
            'JH/%02s/%02s/%02s/%04d/%04d',
            (string) $districtId,
            date('m', $now),
            date('y', $now),
            $seq,
            $now % 10000
        );
    }

    /**
     * Mirror the authoritative survey_code onto every visible `auto_number`
     * field of the form version, overwriting any client-generated placeholder.
     */
    private function stampAutoNumbers(int $recordId, ?array $definition, array $visible, string $surveyCode): void
    {
        if ($surveyCode === '' || $definition === null) {
            return;
        }
        $pdo = Connection::instance();

        foreach (($definition['sections'] ?? []) as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                if (($field['type'] ?? '') !== 'auto_number') {
                    continue;
                }
                $key = (string) $field['field_key'];
                if (isset($visible[$key]) && !$visible[$key]) {
                    continue; // hidden by conditions — never persisted
                }

                $update = $pdo->prepare(
                    'UPDATE survey_answers SET value_text = :c WHERE record_id = :rid AND field_key = :k'
                );
                $update->execute(['c' => $surveyCode, 'rid' => $recordId, 'k' => $key]);

                if ($update->rowCount() === 0) {
                    // Client did not submit an answer for this field at all.
                    $insert = $pdo->prepare(
                        'INSERT INTO survey_answers (record_id, field_id, field_key, value_text)
                         VALUES (:rid, :fid, :k, :c)'
                    );
                    $insert->execute([
                        'rid' => $recordId,
                        'fid' => (int) $field['id'],
                        'k'   => $key,
                        'c'   => $surveyCode,
                    ]);
                }
            }
        }
    }

    /**
     * Enforce calculated fields server-side (declarative `settings.calc` on
     * the TARGET field: {watch: 'other_key', expr: 'current_year - {other_key}'}).
     *
     * The expression is evaluated with the watched answers substituted for
     * `{key}` tokens and `current_year` for the current year; the sanitized
     * arithmetic result overwrites whatever the client submitted. When the
     * result cannot be computed (watched field empty/non-numeric), any stored
     * answer for the target is removed so stale values never persist.
     */
    private function applyCalculatedFields(int $recordId, ?array $definition, array $visible, array $answers): void
    {
        if ($definition === null) {
            return;
        }
        $pdo = Connection::instance();

        foreach (($definition['sections'] ?? []) as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                $calc = $field['settings']['calc'] ?? null;
                if (!is_array($calc) || empty($calc['watch']) || empty($calc['expr'])) {
                    continue;
                }
                $key = (string) $field['field_key'];
                if (isset($visible[$key]) && !$visible[$key]) {
                    continue; // hidden by conditions — never persisted
                }

                $computed = $this->evaluateCalc((string) $calc['expr'], (string) $calc['watch'], $answers);

                if ($computed === null) {
                    $pdo->prepare('DELETE FROM survey_answers WHERE record_id = :rid AND field_key = :k')
                        ->execute(['rid' => $recordId, 'k' => $key]);
                    continue;
                }

                $update = $pdo->prepare(
                    'UPDATE survey_answers SET value_text = :v WHERE record_id = :rid AND field_key = :k'
                );
                $update->execute(['v' => $computed, 'rid' => $recordId, 'k' => $key]);

                if ($update->rowCount() === 0) {
                    $insert = $pdo->prepare(
                        'INSERT INTO survey_answers (record_id, field_id, field_key, value_text)
                         VALUES (:rid, :fid, :k, :v)'
                    );
                    $insert->execute([
                        'rid' => $recordId,
                        'fid' => (int) $field['id'],
                        'k'   => $key,
                        'v'   => $computed,
                    ]);
                }
            }
        }
    }

    /** Evaluate a calc expression; null when it cannot be computed safely. */
    private function evaluateCalc(string $expr, string $watchKey, array $answers): ?string
    {
        $map = ['current_year' => (string) (int) date('Y')];
        foreach ($answers as $k => $v) {
            $numeric = (!is_array($v) && is_numeric($v)) ? (string) (0 + $v) : 'NaN';
            $map['{' . $k . '}'] = $numeric;
        }
        // The watched value gates the whole calculation.
        if (($map['{' . $watchKey . '}'] ?? 'NaN') === 'NaN' || !array_key_exists('{' . $watchKey . '}', $map)) {
            return null;
        }
        $out = strtr($expr, $map);
        if (str_contains($out, 'NaN') || !preg_match('/^[\d\s+\-*\/().]+$/', $out)) {
            return null;
        }
        try {
            $val = eval("return {$out};");
        } catch (\Throwable) {
            return null;
        }
        if (!is_numeric($val)) {
            return null;
        }
        $val = round((float) $val, 2);
        return ($val == (int) $val) ? (string) (int) $val : (string) $val;
    }

    /**
     * Enqueue a pending mobile-sync item for the user's device(s) so the
     * `/v1/sync/status` endpoint reflects the change. When a client device
     * identifier is supplied and matches an active device of the user, the
     * item is enqueued for that device only; otherwise it is enqueued for all
     * of the user's active devices. Users with no registered active device are
     * skipped (there is nowhere to sync to).
     *
     * Re-storing the same record (a re-sync) replaces any prior pending or
     * in-flight item for that record instead of stacking duplicates.
     *
     * @param array{record_uuid:string, record_id:int, form_id:int, form_version_id:int, status:string, survey_code?:string} $change
     */
    public function enqueueSync(int $userId, ?string $clientDeviceId, array $change): void
    {
        $pdo = Connection::instance();

        $deviceIds = [];
        if ($clientDeviceId !== null && $clientDeviceId !== '') {
            $stmt = $pdo->prepare(
                'SELECT id FROM devices WHERE user_id = :u AND device_id = :d AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(['u' => $userId, 'd' => $clientDeviceId]);
            $deviceId = (int) $stmt->fetchColumn();
            if ($deviceId > 0) {
                $deviceIds[] = $deviceId;
            }
        }
        if ($deviceIds === []) {
            $stmt = $pdo->prepare('SELECT id FROM devices WHERE user_id = :u AND is_active = 1');
            $stmt->execute(['u' => $userId]);
            $deviceIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        }
        if ($deviceIds === []) {
            return;
        }

        $recordUuid = (string) $change['record_uuid'];
        $payload = json_encode([
            'action'          => 'upsert',
            'record_uuid'     => $recordUuid,
            'record_id'       => $change['record_id'] ?? null,
            'survey_code'     => $change['survey_code'] ?? null,
            'form_id'         => $change['form_id'] ?? null,
            'form_version_id' => $change['form_version_id'] ?? null,
            'status'          => $change['status'] ?? 'submitted',
            'server_time'     => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // A re-sync of the same record supersedes any prior pending/processing item.
        $delete = $pdo->prepare(
            "DELETE FROM sync_queue
             WHERE user_id = :u AND record_uuid = :uuid AND action = 'upsert'
               AND status IN ('pending','processing')"
        );
        $delete->execute(['u' => $userId, 'uuid' => $recordUuid]);

        $insert = $pdo->prepare(
            "INSERT INTO sync_queue (device_id, user_id, record_uuid, action, payload_json)
             VALUES (:did, :u, :uuid, 'upsert', :payload)"
        );
        foreach ($deviceIds as $deviceId) {
            $insert->execute([
                'did'     => $deviceId,
                'u'       => $userId,
                'uuid'    => $recordUuid,
                'payload' => $payload,
            ]);
        }
    }

    /**
     * Evaluate the form version's conditional rules against the submitted
     * answers: drop answers for fields hidden by conditions so persisted data
     * always reflects the evaluated form, and (for real submissions, not
     * drafts) reject records missing a visible mandatory / condition-required
     * value.
     *
     * @param array<string, mixed> $answers
     * @return array{answers: array<string, mixed>, visible: array<string, bool>}
     */
    private function applyConditions(?array $definition, array $answers, string $status): array
    {
        if ($definition === null) {
            return ['answers' => $answers, 'visible' => []];
        }
        $sections = $definition['sections'] ?? [];
        if ($sections === []) {
            return ['answers' => $answers, 'visible' => []];
        }

        $evaluated = ConditionEvaluator::evaluate($sections, $answers);

        // Skip hidden fields: answers for conditionally-hidden fields are not
        // persisted (unknown field_keys are kept as-is).
        $answers = array_filter(
            $answers,
            static fn (string $key): bool => !isset($evaluated['visible'][$key]) || $evaluated['visible'][$key],
            ARRAY_FILTER_USE_KEY
        );

        if ($status !== 'draft') {
            $missing = ConditionEvaluator::missingRequired($sections, $answers, $evaluated);
            if ($missing !== []) {
                throw new ValidationException($missing);
            }
        }

        return ['answers' => $answers, 'visible' => $evaluated['visible']];
    }

    private function saveAnswers(int $recordId, array $answers): void
    {
        if ($answers === []) {
            return;
        }
        $pdo = Connection::instance();

        // Preload field map: field_key -> (id, type, settings)
        $fields = [];
        $rows = $pdo->query('SELECT id, field_key, type, settings_json FROM survey_fields')->fetchAll();
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
            $settings = $field !== null ? json_decode((string) $field['settings_json'], true) : null;

            if ($fieldType === 'master') {
                [$text, $num, $date, $json] = $this->normalizeMaster((array) ($settings ?? []), $value);
            } elseif ($fieldType === 'location_cascade') {
                [$text, $num, $date, $json] = $this->normalizeLocation($value);
            } else {
                [$text, $num, $date, $json] = $this->normalizeValue($fieldType, $value);
            }

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

    /**
     * Master-data answer: persist both the selected master item id and its name.
     * @return array{?string, ?string, ?string, ?string} [text=name, null, null, json={master_id,name}]
     */
    private function normalizeMaster(array $settings, mixed $value): array
    {
        $groupId = (int) ($settings['master_group_id'] ?? 0);
        $masterId = null;
        $name = '';

        if (is_array($value)) {
            $masterId = (int) ($value['master_id'] ?? $value['id'] ?? 0);
            $name = trim((string) ($value['name'] ?? $value['option_label'] ?? ''));
        } else {
            $name = trim((string) ($value ?? ''));
            if ($name !== '' && ctype_digit($name)) {
                $masterId = (int) $name;
            }
        }

        if ($groupId > 0) {
            $pdo = Connection::instance();
            if ($masterId > 0) {
                $stmt = $pdo->prepare('SELECT name FROM master_items WHERE id = :i AND group_id = :g AND is_active = 1 LIMIT 1');
                $stmt->execute(['i' => $masterId, 'g' => $groupId]);
                $found = $stmt->fetchColumn();
                if ($found !== false) {
                    $name = (string) $found;
                }
            } elseif ($name !== '') {
                $stmt = $pdo->prepare('SELECT id FROM master_items WHERE name = :n AND group_id = :g AND is_active = 1 LIMIT 1');
                $stmt->execute(['n' => $name, 'g' => $groupId]);
                $found = $stmt->fetchColumn();
                if ($found !== false) {
                    $masterId = (int) $found;
                }
            }
        }

        $json = json_encode(['master_id' => $masterId, 'name' => $name !== '' ? $name : null]);
        return [$name !== '' ? $name : null, null, null, $json];
    }

    /**
     * Location cascade answer: persist each selected level as id + name.
     * @return array{?string, ?string, ?string, ?string} [text="District / Block / ...", null, null, json]
     */
    private function normalizeLocation(mixed $value): array
    {
        if (!is_array($value)) {
            return [null, null, null, null];
        }

        $levels = ['district' => 'district_id', 'block' => 'block_id', 'panchayat' => 'panchayat_id', 'village' => 'village_id'];
        $out = [];
        $names = [];

        foreach ($levels as $level => $idKey) {
            $id = (int) ($value[$idKey] ?? $value[$level . '_id'] ?? 0);
            $name = trim((string) ($value[$level] ?? $value[$level . '_name'] ?? ''));
            if ($id > 0 || $name !== '') {
                $out[$level . '_id'] = $id > 0 ? $id : null;
                $out[$level . '_name'] = $name !== '' ? $name : null;
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $text = $names !== [] ? implode(' / ', $names) : null;
        return [$text, null, null, json_encode($out)];
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

    /**
     * User ids whose records the given viewer may see: themselves plus any
     * users managed under their hierarchy scope. State admins see everyone
     * (returned as [] = "no restriction").
     *
     * @return list<int>
     */
    public static function scopeUserIds(User $viewer): array
    {
        if ($viewer->isStateAdmin()) {
            return [];
        }
        $pdo = Connection::instance();

        // Leaf field collectors only ever see their own submissions.
        if ($viewer->hasRole('surveyor')) {
            return [$viewer->id()];
        }

        $scope = $viewer->scope();
        if (!empty($scope['village_id'])) {
            $cond = 'village_id = ' . (int) $scope['village_id'];
        } elseif (!empty($scope['panchayat_id'])) {
            $cond = 'panchayat_id = ' . (int) $scope['panchayat_id'];
        } elseif (!empty($scope['block_id'])) {
            $cond = 'block_id = ' . (int) $scope['block_id'];
        } elseif (!empty($scope['district_id'])) {
            $cond = 'district_id = ' . (int) $scope['district_id'];
        } elseif ($viewer->hasRole('department_admin') && !empty($scope['department_id'])) {
            $cond = 'department_id = ' . (int) $scope['department_id'];
        } else {
            // Manager without a configured scope — safest to see only own records.
            return [$viewer->id()];
        }

        $ids = array_map(
            'intval',
            array_column($pdo->query("SELECT id FROM users WHERE deleted_at IS NULL AND {$cond}")->fetchAll(), 'id')
        );
        if (!in_array($viewer->id(), $ids, true)) {
            $ids[] = $viewer->id();
        }
        return $ids;
    }

    /** Whether the viewer may see this record (state admins see all). */
    public static function canView(User $viewer, array $record): bool
    {
        if ($viewer->isStateAdmin()) {
            return true;
        }
        return in_array((int) ($record['user_id'] ?? 0), self::scopeUserIds($viewer), true);
    }

    public function listRecords(?int $formId = null, string $status = '', int $page = 1, int $perPage = 50, ?User $viewer = null, string $search = ''): array
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
        if ($viewer !== null && !$viewer->isStateAdmin()) {
            $ids = self::scopeUserIds($viewer);
            if ($ids === []) {
                $ids = [$viewer->id()];
            }
            $where .= ' AND r.user_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        if ($search !== '') {
            $where .= ' AND (r.survey_code LIKE :q_code OR r.record_uuid LIKE :q_uuid OR u.full_name LIKE :q_name)';
            $like = '%' . $search . '%';
            $params['q_code'] = $like;
            $params['q_uuid'] = $like;
            $params['q_name'] = $like;
        }
        $offset = max(0, ($page - 1) * $perPage);

        $totalStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM survey_records r
             LEFT JOIN users u ON u.id = r.submitted_by
             WHERE {$where}"
        );
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

    /** Full record with answers (labelled), images, GPS and workflow history. */
    public function find(int $recordId): ?array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'SELECT r.*, f.title AS form_title, f.code AS form_code, u.full_name AS submitted_by_name
             FROM survey_records r
             JOIN survey_forms f ON f.id = r.form_id
             LEFT JOIN users u ON u.id = r.submitted_by
             WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $recordId]);
        $record = $stmt->fetch();
        if ($record === false) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT a.*, f.label AS field_label, f.type AS field_type
             FROM survey_answers a JOIN survey_fields f ON f.id = a.field_id
             WHERE a.record_id = :id ORDER BY a.id'
        );
        $stmt->execute(['id' => $recordId]);
        $record['answers'] = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM survey_images WHERE record_id = :id ORDER BY id');
        $stmt->execute(['id' => $recordId]);
        $record['images'] = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM gps_logs WHERE record_id = :id ORDER BY id');
        $stmt->execute(['id' => $recordId]);
        $record['gps'] = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT w.*, u.full_name AS actor_name
             FROM record_workflow_logs w LEFT JOIN users u ON u.id = w.acted_by
             WHERE w.record_id = :id ORDER BY w.id'
        );
        $stmt->execute(['id' => $recordId]);
        $record['workflow'] = $stmt->fetchAll();

        return $record;
    }
}
