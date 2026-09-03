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

    public static function forgotPassword(): never
    {
        $data = Request::all();
        $v = Validator::make($data, [
            'username' => 'required|string',
        ]);
        if ($v->fails()) {
            Response::validation($v->errors());
        }

        $username = (string) $data['username'];
        $user = \App\Models\User::findByUsername($username);
        $email = $user?->email();

        // Security: always succeed whether or not the account/email exists, so
        // the endpoint cannot be used to enumerate valid usernames.
        if ($user !== null && $email !== null && $user->get('status') === 'active') {
            $newPassword = \App\Security\Password::generate();
            $pdo = \App\Database\Connection::instance();
            $pdo->prepare('UPDATE users SET password_hash = :p, plain_password = :plain, must_change_password = 1 WHERE id = :id')
                ->execute([
                    'p' => \App\Security\Password::hash($newPassword),
                    'plain' => config('app.env') !== 'production' ? $newPassword : null,
                    'id' => $user->id(),
                ]);

            $appName = (string) config('app.name', 'BCD Survey Platform');
            $body = "<p>Hello " . e($user->fullName() ?: $username) . ",</p>"
                . "<p>Your password for <strong>" . e($appName) . "</strong> has been reset.</p>"
                . "<p>Your new password is:</p>"
                . "<p style=\"font-size:18px;font-weight:bold;\">" . e($newPassword) . "</p>"
                . "<p>Please sign in with this password. The system may ask you to change it on your next sign-in.</p>"
                . "<p>If you did not request this, please contact your administrator.</p>";

            \App\Support\Mail::send($email, "Password reset – {$appName}", $body);
        }

        Response::ok(['message' => 'If an account with that username exists and has a valid e-mail address, a new password has been sent to it.']);
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
