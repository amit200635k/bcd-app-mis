<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Database\Connection;
use App\Exceptions\ValidationException;
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
            'device_id'       => 'string|max_length:100',
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

            // Record the change in the mobile sync queue so /v1/sync/status
            // reports pending work. Best-effort: a queue failure must not
            // reject an already-persisted record.
            try {
                self::service()->enqueueSync($user->id(), (string) ($data['device_id'] ?? ''), [
                    'record_uuid'     => (string) $result['record_uuid'],
                    'record_id'       => (int) $result['record_id'],
                    'survey_code'     => (string) ($result['survey_code'] ?? ''),
                    'form_id'         => (int) $data['form_id'],
                    'form_version_id' => (int) $data['form_version_id'],
                    'status'          => (string) $result['status'],
                ]);
            } catch (\Throwable $e) {
                error_log('sync_queue enqueue failed: ' . exception_message($e));
            }

            Response::created($result);
        } catch (ValidationException $e) {
            Response::validation($e->errors());
        } catch (\Throwable $e) {
            Response::error('Failed to save record: ' . exception_message($e), 500);
        }
    }

    /** List records (scoped to the caller). */
    public static function index(): never
    {
        $user = ApiAuth::requireAuth();
        $page = max(1, (int) Request::query('page', 1));
        $perPage = min(100, max(1, (int) Request::query('per_page', 50)));
        $formId = Request::query('form_id') !== null ? (int) Request::query('form_id') : null;
        $status = (string) Request::query('status', '');

        Response::ok(self::service()->listRecords($formId, $status, $page, $perPage, $user));
    }

    /** Transition a record's workflow status. */
    public static function transition(array $params): never
    {
        $user = ApiAuth::requireAuth();
        $toStatus = (string) Request::input('status', '');
        $remark = Request::input('remark');

        try {
            $svc = self::service();
            $record = $svc->find((int) $params['id']);
            if ($record === null) {
                Response::notFound('Record not found.');
            }
            if (!$svc->canView($user, $record)) {
                Response::forbidden('You do not have access to this record.');
            }
            $svc->transition((int) $params['id'], $user->id(), $toStatus, $remark);
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
        if (!self::service()->canView($user, $record)) {
            Response::forbidden('You do not have access to this record.');
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
        if (!self::service()->canView($user, $record)) {
            Response::forbidden('You do not have access to this record.');
        }

        $category = (string) Request::input('category', 'photo');
        if (!in_array($category, ['photo', 'signature', 'file', 'barcode', 'qr'], true)) {
            $category = 'photo';
        }

        // Optional association: the answer this file belongs to. When only a
        // field_key is given, resolve it to the answer id for this record.
        $fieldKey = Request::input('field_key');
        $answerId = Request::input('answer_id') !== null ? (int) Request::input('answer_id') : null;
        if ($answerId === null && $fieldKey !== null) {
            $stmt = $pdo->prepare('SELECT id FROM survey_answers WHERE record_id = :rid AND field_key = :k LIMIT 1');
            $stmt->execute(['rid' => $recordId, 'k' => (string) $fieldKey]);
            $answerId = (int) ($stmt->fetchColumn() ?: 0);
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
            'INSERT INTO survey_images (record_id, answer_id, file_path, original_name, mime_type, size_bytes, category)
             VALUES (:rid, :aid, :path, :name, :mime, :size, :cat)'
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
            // Burn survey metadata onto uploaded images (survey id, surveyor,
            // building name, timestamp); keeps working when the device-side
            // stamp did not run. PDFs/JSON are left untouched.
            if (str_starts_with($mime, 'image/')) {
                self::stampPhoto($dest, $mime, $record, $pdo);
            }
            $size = (int) ($_FILES['files']['size'][$i] ?? 0);
            $insert->execute([
                'rid'  => $recordId,
                'aid'  => $answerId > 0 ? $answerId : null,
                'path' => 'uploads/survey/' . $recordId . '/' . $filename,
                'name' => (string) $name,
                'mime' => $mime,
                'size' => $size,
                'cat'  => $category,
            ]);
            $imageId = (int) $pdo->lastInsertId();
            $relPath = 'uploads/survey/' . $recordId . '/' . $filename;
            $saved[] = ['id' => $imageId, 'file_path' => $relPath, 'answer_id' => $answerId > 0 ? $answerId : null];

            // Reflect the stored file on the linked answer (if any) so photo
            // fields carry a resolvable path to the persisted file.
            if ($answerId > 0) {
                $meta = json_encode([
                    'image_id' => $imageId,
                    'file_path' => $relPath,
                    'original_name' => (string) $name,
                    'category' => $category,
                ]);
                $pdo->prepare('UPDATE survey_answers SET value_json = :j WHERE id = :id')
                    ->execute(['j' => $meta, 'id' => $answerId]);
            }
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

    /** Survey / building / surveyor etc. metadata used for the image stamp. */
    private static function stampMeta(array $record, \PDO $pdo): array
    {
        $survey = (string) ($record['survey_code'] ?? '');
        $created = (string) ($record['created_at'] ?? date('Y-m-d H:i:s'));

        $stmt = $pdo->prepare(
            "SELECT field_key, value_text, value_json FROM survey_answers
             WHERE record_id = :rid AND field_key IN
             ('survey_id','building_name','office_name','address','geo_address','site_address','geo_location')"
        );
        $stmt->execute(['rid' => $record['id']]);
        $answers = [];
        foreach ($stmt->fetchAll() as $row) {
            $answers[$row['field_key']] = $row;
        }
        $answerText = static function (string $key) use ($answers): string {
            return isset($answers[$key]) && $answers[$key]['value_text'] !== null && $answers[$key]['value_text'] !== ''
                ? (string) $answers[$key]['value_text']
                : '';
        };

        if ($survey === '' && $answerText('survey_id') !== '') {
            $survey = $answerText('survey_id');
        }

        $building = $answerText('building_name');
        if ($building === '') {
            $building = $answerText('office_name');
        }

        $address = $answerText('address');
        if ($address === '') {
            $address = $answerText('geo_address');
        }
        if ($address === '') {
            $address = $answerText('site_address');
        }

        $lat = '';
        $lng = '';
        $geo = isset($answers['geo_location']) ? json_decode((string) $answers['geo_location']['value_json'], true) : null;
        if (is_array($geo)) {
            $lat = (string) ($geo['lat'] ?? $geo['latitude'] ?? '');
            $lng = (string) ($geo['lng'] ?? $geo['longitude'] ?? $geo['long'] ?? $geo['lon'] ?? '');
        }
        $fmtCoord = static function (string $v): string {
            if ($v === '' || !is_numeric($v)) {
                return '—';
            }
            return number_format((float) $v, 5, '.', '');
        };

        $surveyor = '—';
        $surveyorId = $record['submitted_by'] !== null ? (int) $record['submitted_by'] : (int) $record['user_id'];
        if ($surveyorId > 0) {
            $stmt = $pdo->prepare('SELECT full_name, username FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $surveyorId]);
            $u = $stmt->fetch();
            if ($u !== false) {
                $surveyor = ($u['full_name'] !== null && $u['full_name'] !== '') ? (string) $u['full_name'] : (string) $u['username'];
            }
        }

        return [
            'survey'   => $survey !== '' ? $survey : '—',
            'surveyor' => $surveyor,
            'building' => $building !== '' ? $building : '—',
            'address'  => $address !== '' ? $address : '—',
            'created'  => date('d-m-Y H:i:s', strtotime($created)),
            'geo'      => 'lat-' . $fmtCoord($lat) . ', long-' . $fmtCoord($lng),
        ];
    }

    /** Find a usable TrueType font for GD (Windows + common Linux paths). */
    private static function ttfFont(): ?string
    {
        $candidates = [
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ];
        foreach ($candidates as $font) {
            if (is_file($font)) {
                return $font;
            }
        }
        return null;
    }

    /** Draw a bottom-right metadata stamp on the given image file (in place). */
    private static function stampPhoto(string $dest, string $mime, array $record, \PDO $pdo): void
    {
        if (!function_exists('imagecreatefrompng') && !function_exists('imagecreatefromjpeg')) {
            return;
        }
        try {
            $meta = self::stampMeta($record, $pdo);
            $lines = [
                'Survey ID- ' . $meta['survey'],
                'Surveyor Name - ' . $meta['surveyor'],
                'Created Date - ' . $meta['created'],
                'Building Name - ' . $meta['building'],
                'Address - ' . $meta['address'],
                $meta['geo'],
            ];

            $img = match ($mime) {
                'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($dest) : false,
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($dest) : false,
                default      => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($dest) : false,
            };
            if ($img === false) {
                return;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w < 120 || $h < 80) {
                imagedestroy($img);
                return;
            }

            // Resize to a fixed 1000x650 (center-crop cover, aspect preserved).
            $targetW = 1000;
            $targetH = 650;
            $scale = max($targetW / $w, $targetH / $h);
            $rw = max(1, (int) round($w * $scale));
            $rh = max(1, (int) round($h * $scale));
            $resized = imagecreatetruecolor($rw, $rh);
            $trans = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, $trans);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $rw, $rh, $w, $h);
            imagedestroy($img);

            $crop = imagecreatetruecolor($targetW, $targetH);
            imagealphablending($crop, false);
            imagesavealpha($crop, true);
            imagefill($crop, 0, 0, $trans);
            $sx = (int) floor(($rw - $targetW) / 2);
            $sy = (int) floor(($rh - $targetH) / 2);
            $copied = imagecopy($crop, $resized, 0, 0, max(0, $sx), max(0, $sy), min($targetW, $rw), min($targetH, $rh));
            imagedestroy($resized);
            if ($copied === false) {
                imagedestroy($crop);
                return;
            }
            $img = $crop;
            $w = $targetW;
            $h = $targetH;

            $pad = max(8, (int) round($w * 0.02));
            $font = self::ttfFont();

            if ($font !== null) {
                $fontSize = max(14, (int) round($h * 0.032));
                $lineGap = (int) round($fontSize * 1.3);
            } else {
                $fontSize = 5; // GD built-in font size 5
                $lineGap = 14;
            }

            // No dark background: draw the stamp directly over the visible image,
            // using an outline so the text stays readable on any background.
            $white = imagecolorallocate($img, 255, 255, 255);
            $black = imagecolorallocate($img, 0, 0, 0);
            $outline = [[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]];

            $y = $h - $pad;
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if ($font !== null) {
                    $box = imagettfbbox($fontSize, 0, $font, $lines[$i]);
                    $tw = $box !== false ? max(0, $box[2] - $box[0]) : 0;
                    $x = max(0, $w - $pad - $tw);
                    foreach ($outline as [$dx, $dy]) {
                        imagettftext($img, $fontSize, 0, $x + $dx, $y + $dy, $black, $font, $lines[$i]);
                    }
                    imagettftext($img, $fontSize, 0, $x, $y, $white, $font, $lines[$i]);
                } else {
                    $wpx = imagefontwidth(5) * strlen($lines[$i]);
                    $x = max(0, $w - $pad - $wpx);
                    imagestring($img, 5, $x, max(0, $y - 14), $lines[$i], $white);
                }
                $y -= $lineGap;
            }

            // Write the stamped image back over the original.
            if ($mime === 'image/png') {
                imagepng($img, $dest, 8);
            } elseif ($mime === 'image/webp') {
                imagewebp($img, $dest, 80);
            } else {
                imagejpeg($img, $dest, 88);
            }
            imagedestroy($img);
        } catch (\Throwable $e) {
            error_log('stampPhoto failed: ' . exception_message($e));
        }
    }
}
