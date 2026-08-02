<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validator;

/**
 * Mobile device registration (FCM push tokens, sync tracking).
 */
final class DeviceController
{
    /** Register or update the calling user's device. */
    public static function register(): never
    {
        $user = ApiAuth::requireAuth();
        $data = Request::all();

        $v = Validator::make($data, [
            'device_id'   => 'required|string|min_length:4|max_length:100',
            'device_name' => 'string|max_length:150',
            'platform'    => 'string|max_length:30',
            'os_version'  => 'string|max_length:30',
            'app_version' => 'string|max_length:20',
            'fcm_token'   => 'string|max_length:255',
        ]);
        if ($v->fails()) {
            Response::validation($v->errors());
        }

        $pdo = Connection::instance();
        $pdo->prepare(
            'INSERT INTO devices (user_id, device_id, device_name, platform, os_version, app_version, fcm_token, last_synced_at)
             VALUES (:uid, :did, :dn, :pf, :os, :av, :fcm, NOW())
             ON DUPLICATE KEY UPDATE
                device_name = VALUES(device_name), platform = VALUES(platform),
                os_version = VALUES(os_version), app_version = VALUES(app_version),
                fcm_token = VALUES(fcm_token), is_active = 1, last_synced_at = NOW()'
        )->execute([
            'uid' => $user->id(),
            'did' => (string) $data['device_id'],
            'dn'  => $data['device_name'] ?? null,
            'pf'  => $data['platform'] ?? null,
            'os'  => $data['os_version'] ?? null,
            'av'  => $data['app_version'] ?? null,
            'fcm' => $data['fcm_token'] ?? null,
        ]);

        $stmt = $pdo->prepare('SELECT id, device_id, is_active FROM devices WHERE user_id = :uid AND device_id = :did');
        $stmt->execute(['uid' => $user->id(), 'did' => (string) $data['device_id']]);
        Response::ok(['device' => $stmt->fetch()]);
    }

    /** Deactivate a device (logged out / replaced). */
    public static function deregister(array $params): never
    {
        $user = ApiAuth::requireAuth();
        $stmt = Connection::instance()->prepare(
            'UPDATE devices SET is_active = 0 WHERE user_id = :uid AND device_id = :did'
        );
        $stmt->execute(['uid' => $user->id(), 'did' => (string) $params['device_id']]);
        Response::ok(['message' => 'Device deactivated.', 'device_id' => $params['device_id']]);
    }
}
