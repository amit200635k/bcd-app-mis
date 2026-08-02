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
}
