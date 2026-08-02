<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;
use App\Services\RecordService;
use App\Support\Validator;

final class RecordController
{
    private static function service(): RecordService
    {
        return new RecordService();
    }

    /** Sync one record (upsert). */
    public static function store(): never
    {
        $user = ApiAuth::requireAuth();
        $data = Request::all();

        $v = Validator::make($data, [
            'form_id'         => 'required|integer',
            'form_version_id' => 'required|integer',
            'record_uuid'     => 'string|max_length:36',
        ]);
        if ($v->fails()) {
            Response::validation($v->errors());
        }

        // Validate the version belongs to the form.
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'SELECT id FROM survey_versions WHERE id = :vid AND form_id = :fid LIMIT 1'
        );
        $stmt->execute(['vid' => $data['form_version_id'], 'fid' => $data['form_id']]);
        if ($stmt->fetch() === false) {
            Response::validation(['form_version_id' => ['Version does not belong to the form.']]);
        }

        // The user must have access to the form.
        if (!$user->canAccessForm((int) $data['form_id'])) {
            Response::forbidden('You do not have access to this survey form.');
        }

        try {
            $result = self::service()->upsert($user->id(), $data);
            Response::created($result);
        } catch (\Throwable $e) {
            Response::error('Failed to save record: ' . exception_message($e), 500);
        }
    }

    /** List records. */
    public static function index(): never
    {
        ApiAuth::requireAuth();
        $page = max(1, (int) Request::query('page', 1));
        $perPage = min(100, max(1, (int) Request::query('per_page', 50)));
        $formId = Request::query('form_id') !== null ? (int) Request::query('form_id') : null;
        $status = (string) Request::query('status', '');

        Response::ok(self::service()->listRecords($formId, $status, $page, $perPage));
    }

    /** Transition a record's workflow status. */
    public static function transition(array $params): never
    {
        $user = ApiAuth::requireAuth();
        $toStatus = (string) Request::input('status', '');
        $remark = Request::input('remark');

        try {
            self::service()->transition((int) $params['id'], $user->id(), $toStatus, $remark);
            Response::ok(['message' => 'Status updated.', 'status' => $toStatus]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /** Download a single record by id or uuid, with answers + images. */
    public static function show(array $params): never
    {
        $user = ApiAuth::requireAuth();
        $identifier = (string) $params['identifier'];
        $pdo = Connection::instance();

        $stmt = $pdo->prepare('SELECT * FROM survey_records WHERE id = :id OR record_uuid = :u LIMIT 1');
        $stmt->execute(['id' => ctype_digit($identifier) ? (int) $identifier : 0, 'u' => $identifier]);
        $record = $stmt->fetch();

        if ($record === false) {
            Response::notFound('Record not found.');
        }
        if (!$user->canAccessForm((int) $record['form_id'])) {
            Response::forbidden('You do not have access to this record\'s survey form.');
        }

        $answers = $pdo->prepare('SELECT field_key, value_text, value_number, value_date, value_json FROM survey_answers WHERE record_id = :id');
        $answers->execute(['id' => $record['id']]);
        $images = $pdo->prepare('SELECT id, file_path, original_name, category, created_at FROM survey_images WHERE record_id = :id');
        $images->execute(['id' => $record['id']]);

        Response::ok([
            'record'  => $record,
            'answers' => $answers->fetchAll(),
            'images'  => $images->fetchAll(),
        ]);
    }

    /** Upload photo(s) for a record. Supports multipart "files[]" and "category". */
    public static function photos(array $params): never
    {
        $user = ApiAuth::requireAuth();
        $recordId = (int) $params['id'];
        $pdo = Connection::instance();

        $stmt = $pdo->prepare('SELECT * FROM survey_records WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $recordId]);
        $record = $stmt->fetch();
        if ($record === false) {
            Response::notFound('Record not found.');
        }
        if (!$user->canAccessForm((int) $record['form_id'])) {
            Response::forbidden('You do not have access to this record\'s survey form.');
        }

        $category = (string) Request::input('category', 'photo');
        if (!in_array($category, ['photo', 'signature', 'file', 'barcode', 'qr'], true)) {
            $category = 'photo';
        }

        if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) {
            Response::validation(['files' => ['No files uploaded. Use multipart "files[]" field.']]);
        }

        $saved = [];
        $uploadDir = base_path('uploads/survey/' . $recordId);
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            Response::error('Could not create upload directory.', 500);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'application/json'];
        $insert = $pdo->prepare(
            'INSERT INTO survey_images (record_id, file_path, original_name, mime_type, size_bytes, category)
             VALUES (:rid, :path, :name, :mime, :size, :cat)'
        );

        foreach ($_FILES['files']['name'] as $i => $name) {
            if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = $_FILES['files']['tmp_name'][$i];
            $mime = (string) ($_FILES['files']['type'][$i] ?? 'application/octet-stream');
            if (!in_array($mime, $allowed, true)) {
                continue;
            }
            $ext = match ($mime) {
                'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
                'application/pdf' => 'pdf', 'application/json' => 'json',
                default => 'jpg',
            };
            $filename = $category . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $uploadDir . '/' . $filename;
            if (!move_uploaded_file($tmp, $dest)) {
                continue;
            }
            $size = (int) ($_FILES['files']['size'][$i] ?? 0);
            $insert->execute([
                'rid'  => $recordId,
                'path' => 'uploads/survey/' . $recordId . '/' . $filename,
                'name' => (string) $name,
                'mime' => $mime,
                'size' => $size,
                'cat'  => $category,
            ]);
            $saved[] = ['id' => (int) $pdo->lastInsertId(), 'file_path' => 'uploads/survey/' . $recordId . '/' . $filename];
        }

        if ($saved === []) {
            Response::error('No valid files could be saved.', 422);
        }
        Response::created(['images' => $saved]);
    }

    /** Pending sync queue status for the calling user's devices. */
    public static function syncStatus(): never
    {
        $user = ApiAuth::requireAuth();
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'SELECT sq.status, COUNT(*) AS c
             FROM sync_queue sq
             JOIN devices d ON d.id = sq.device_id
             WHERE sq.user_id = :uid
             GROUP BY sq.status'
        );
        $stmt->execute(['uid' => $user->id()]);
        $byStatus = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM sync_queue WHERE user_id = :uid AND status = "pending"'
        );
        $stmt->execute(['uid' => $user->id()]);

        Response::ok([
            'user_id'      => $user->id(),
            'pending'      => (int) $stmt->fetchColumn(),
            'by_status'    => $byStatus,
            'server_time'  => date('c'),
        ]);
    }
}
