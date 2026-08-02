<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Http\Request;
use App\Http\Response;
use App\Services\NotificationService;
use App\Services\ReplicationService;

final class SystemController
{
    public static function notifications(): never
    {
        $user = ApiAuth::requireAuth();
        Response::ok((new NotificationService())->forUser($user->id()));
    }

    public static function unreadNotifications(): never
    {
        $user = ApiAuth::requireAuth();
        Response::ok(['unread' => (new NotificationService())->unreadCount($user->id())]);
    }

    public static function markNotificationRead(array $params): never
    {
        $user = ApiAuth::requireAuth();
        (new NotificationService())->markRead((int) $params['id'], $user->id());
        Response::ok(['message' => 'Marked as read.']);
    }

    public static function replicationStats(): never
    {
        ApiAuth::requireAuth();
        Response::ok(['stats' => (new ReplicationService())->stats()]);
    }
}
