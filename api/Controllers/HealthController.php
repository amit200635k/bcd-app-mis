<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Database\Connection;
use App\Http\Response;

final class HealthController
{
    public static function check(): never
    {
        $db = false;
        try {
            $pdo = Connection::instance();
            $pdo->query('SELECT 1');
            $db = true;
        } catch (\Throwable) {
            $db = false;
        }

        Response::json(
            [
                'status'  => $db ? 'ok' : 'degraded',
                'app'     => config('app.name'),
                'env'     => config('app.env'),
                'time'    => date('c'),
                'checks'  => ['database' => $db],
            ],
            $db ? 200 : 503
        );
    }

    public static function version(): never
    {
        Response::ok([
            'app'   => config('app.name'),
            'version' => '0.1.0',
            'api'   => 'v1',
            'php'   => PHP_VERSION,
        ]);
    }
}
