<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Database\Connection;
use App\Http\Response;

final class MasterController
{
    /** Full location hierarchy for offline mobile download. */
    public static function locations(): never
    {
        ApiAuth::requireAuth();
        $pdo = Connection::instance();

        $districts = $pdo->query(
            'SELECT id, code, name FROM districts WHERE is_active = 1 ORDER BY name'
        )->fetchAll();

        $blocks = $pdo->query(
            'SELECT id, district_id, code, name FROM blocks WHERE is_active = 1 ORDER BY name'
        )->fetchAll();

        $panchayats = $pdo->query(
            'SELECT id, block_id, code, name FROM panchayats WHERE is_active = 1 ORDER BY name'
        )->fetchAll();

        $villages = $pdo->query(
            'SELECT id, panchayat_id, code, name, latitude, longitude FROM villages WHERE is_active = 1 ORDER BY name'
        )->fetchAll();

        Response::ok([
            'updated_at' => date('c'),
            'districts'  => $districts,
            'blocks'     => $blocks,
            'panchayats' => $panchayats,
            'villages'   => $villages,
        ]);
    }

    /** Generic master data download. */
    public static function index(): never
    {
        ApiAuth::requireAuth();
        $pdo = Connection::instance();

        $groups = $pdo->query('SELECT id, code, name FROM master_groups WHERE is_system = 1 ORDER BY name')->fetchAll();
        $items = $pdo->query(
            'SELECT mi.id, mi.group_id, mi.code, mi.name, mi.parent_id, mi.extra_json
             FROM master_items mi
             WHERE mi.is_active = 1
             ORDER BY mi.group_id, mi.sort_order'
        )->fetchAll();

        Response::ok([
            'updated_at' => date('c'),
            'groups' => $groups,
            'items'  => $items,
        ]);
    }
}
