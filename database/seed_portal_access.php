<?php

declare(strict_types=1);

/**
 * Backfill user_portal_access grants for admin + demo users.
 *
 * Idempotent — safe to run repeatedly.
 * Usage: php database/seed_portal_access.php
 */

require __DIR__ . '/../common/bootstrap.php';

use App\Database\Connection;

$pdo = Connection::instance();

$stmt = $pdo->prepare('INSERT IGNORE INTO user_portal_access (user_id, portal, granted_by) VALUES (:u, :p, 1)');

$users = $pdo->query(
    "SELECT id, username FROM users WHERE username IN ('admin','dh_surveyor','rk_surveyor','jb_block','sk_district')"
)->fetchAll();

$count = 0;
foreach ($users as $u) {
    $uid = (int) $u['id'];
    // State admin gets both portals; everyone else gets the MIS portal.
    $portals = $u['username'] === 'admin' ? ['mis', 'admin'] : ['mis'];
    foreach ($portals as $portal) {
        $stmt->execute(['u' => $uid, 'p' => $portal]);
        $count++;
    }
}

echo "Granted {$count} portal access grants." . PHP_EOL;
