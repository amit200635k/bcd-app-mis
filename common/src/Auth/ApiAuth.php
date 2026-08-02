<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Request;
use App\Http\Response;
use App\Models\User;
use App\Security\Jwt;
use RuntimeException;

/**
 * Token (JWT) authentication for the REST API layer.
 */
final class ApiAuth
{
    private static ?User $user = null;

    /**
     * Resolve the authenticated user from the Bearer token.
     * Responds with 401 and exits if the token is missing/invalid.
     */
    public static function requireAuth(): User
    {
        $user = self::user();
        if ($user === null) {
            Response::unauthorized('Authentication required.');
        }
        return $user;
    }

    public static function user(): ?User
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $token = Request::bearerToken();
        if ($token === null) {
            return null;
        }
        try {
            $claims = Jwt::decode($token);
        } catch (RuntimeException) {
            return null;
        }
        $user = isset($claims['sub']) ? User::find((int) $claims['sub']) : null;
        if ($user === null || $user->get('status') !== 'active') {
            return null;
        }
        return self::$user = $user;
    }

    /** Issue a JWT access token + opaque refresh token. */
    public static function issueTokens(User $user, ?string $deviceId = null): array
    {
        $access = Jwt::encode(['sub' => $user->id()], (int) config('jwt.ttl'));

        $refreshToken = bin2hex(random_bytes(32));
        $stmt = \App\Database\Connection::instance()->prepare(
            'INSERT INTO refresh_tokens (user_id, device_id, token_hash, expires_at) VALUES (:uid, :dev, :hash, :exp)'
        );
        $stmt->execute([
            'uid' => $user->id(),
            'dev' => $deviceId,
            'hash' => hash('sha256', $refreshToken),
            'exp' => date('Y-m-d H:i:s', time() + (int) config('jwt.refresh_ttl')),
        ]);

        return [
            'access_token'  => $access,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) config('jwt.ttl'),
            'user'          => $user->toArray(),
        ];
    }

    public static function rotateRefreshToken(string $refreshToken): ?array
    {
        $hash = hash('sha256', $refreshToken);
        $pdo = \App\Database\Connection::instance();

        $stmt = $pdo->prepare(
            'SELECT * FROM refresh_tokens WHERE token_hash = :h AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        // Revoke and re-issue (rotation).
        $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = :id')->execute(['id' => $row['id']]);
        $user = User::find((int) $row['user_id']);
        if ($user === null) {
            return null;
        }
        return self::issueTokens($user, $row['device_id'] ?? null);
    }
}
