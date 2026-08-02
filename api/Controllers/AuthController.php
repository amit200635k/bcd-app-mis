<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Auth\SessionAuth;
use App\Http\Request;
use App\Http\Response;
use App\Models\User;
use App\Support\Validator;

final class AuthController
{
    public static function login(): never
    {
        $data = Request::all();
        $v = Validator::make($data, [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        if ($v->fails()) {
            Response::validation($v->errors());
        }

        $user = User::findByCredentials((string) $data['username'], (string) $data['password']);
        if ($user === null) {
            Response::unauthorized('Invalid credentials or inactive account.');
        }
        if (!$user->hasPermission('mobile.login') && !$user->hasPermission('dashboard.view')) {
            Response::forbidden('Account is not allowed to use the API.');
        }

        $tokens = ApiAuth::issueTokens($user, Request::input('device_id'));
        Response::ok($tokens);
    }

    public static function refresh(): never
    {
        $token = (string) Request::input('refresh_token', '');
        if ($token === '') {
            Response::validation(['refresh_token' => ['The refresh token is required.']]);
        }
        $tokens = ApiAuth::rotateRefreshToken($token);
        if ($tokens === null) {
            Response::unauthorized('Invalid or expired refresh token.');
        }
        Response::ok($tokens);
    }

    public static function logout(): never
    {
        $user = ApiAuth::requireAuth();
        $pdo = \App\Database\Connection::instance();
        $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = :id AND revoked_at IS NULL')
            ->execute(['id' => $user->id()]);
        Response::ok(['message' => 'Logged out successfully.']);
    }

    public static function me(): never
    {
        $user = ApiAuth::requireAuth();
        Response::ok($user->toArray());
    }
}
