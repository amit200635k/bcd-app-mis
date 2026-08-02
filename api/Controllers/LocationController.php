<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;

/**
 * Dependent location dropdowns (District → Block → Panchayat → Village)
 * used by location_cascade fields on mobile + MIS preview.
 */
final class LocationController
{
    /** Children for a cascade level given its parent id (or all districts). */
    public static function children(): never
    {
        $user = ApiAuth::requireAuth();
        $level = (string) Request::query('level', '');
        $parentId = (int) Request::query('parent_id', 0);
        $pdo = Connection::instance();

        $items = match ($level) {
            'district'  => $pdo->query('SELECT id, name FROM districts WHERE is_active = 1 ORDER BY name')->fetchAll(),
            'block'     => self::queryChildren($pdo, 'blocks', 'district_id', $parentId),
            'panchayat' => self::queryChildren($pdo, 'panchayats', 'block_id', $parentId),
            'village'   => self::queryChildren($pdo, 'villages', 'panchayat_id', $parentId),
            default     => [],
        };

        Response::ok([
            'scope' => self::scopeArray($user),
            'items' => $items,
        ]);
    }

    /** Current user's fixed location scope for auto-populating cascades. */
    public static function scope(): never
    {
        $user = ApiAuth::requireAuth();
        Response::ok(['scope' => self::scopeArray($user)]);
    }

    /** @return list<array{id:int, name:string}> */
    private static function queryChildren(\PDO $pdo, string $table, string $parentCol, int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT id, name FROM {$table} WHERE {$parentCol} = :p AND is_active = 1 ORDER BY name"
        );
        $stmt->execute(['p' => $parentId]);
        return $stmt->fetchAll();
    }

    /** @return array{district_id:?int, block_id:?int, panchayat_id:?int, village_id:?int} */
    private static function scopeArray(\App\Models\User $user): array
    {
        $s = $user->scope();
        return [
            'district_id'  => $s['district_id']  !== null ? (int) $s['district_id'] : null,
            'block_id'     => $s['block_id']     !== null ? (int) $s['block_id'] : null,
            'panchayat_id' => $s['panchayat_id'] !== null ? (int) $s['panchayat_id'] : null,
            'village_id'   => $s['village_id']   !== null ? (int) $s['village_id'] : null,
        ];
    }
}
