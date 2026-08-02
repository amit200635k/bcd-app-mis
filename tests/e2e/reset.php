<?php

declare(strict_types=1);

/**
 * Reset demo/test artifacts so the E2E suite is repeatable:
 *  - demo records -> 'submitted'
 *  - remove E2E_* draft forms, e2e_* users, test.% settings,
 *    E2E-% notifications and their recipients.
 *
 * Usage: php tests/e2e/reset.php
 */

require __DIR__ . '/../../common/bootstrap.php';

use App\Database\Connection;

$pdo = Connection::instance();
$pdo->beginTransaction();

try {
    $pdo->exec("UPDATE survey_records SET status = 'submitted' WHERE record_uuid LIKE 'demo-%'");

    $pdo->exec(
        "DELETE FROM survey_versions WHERE form_id IN (SELECT id FROM survey_forms WHERE code LIKE 'E2E_%')"
    );
    $pdo->exec("DELETE FROM survey_records WHERE form_id IN (SELECT id FROM survey_forms WHERE code LIKE 'E2E_%')");
    $pdo->exec("DELETE FROM survey_forms WHERE code LIKE 'E2E_%'");

    $pdo->exec(
        "DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'e2e_%')"
    );
    $pdo->exec("DELETE FROM users WHERE username LIKE 'e2e_%'");

    $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'test.%'");

    // E2E master-data artifacts (items cascade on group delete).
    $pdo->exec("DELETE FROM master_groups WHERE code LIKE 'E2E_GRP_%'");

    $pdo->exec(
        "DELETE FROM notification_recipients WHERE notification_id IN (SELECT id FROM notifications WHERE title LIKE 'E2E-%')"
    );
    $pdo->exec("DELETE FROM notifications WHERE title LIKE 'E2E-%'");

    // Smoke-test artifacts (tests/smoke.php) — purge so they don't drift the demo counts.
    $pdo->exec(
        "DELETE FROM survey_records WHERE form_id IN (SELECT id FROM survey_forms WHERE code LIKE 'SMOKE_%')"
    );
    $pdo->exec(
        "DELETE FROM survey_versions WHERE form_id IN (SELECT id FROM survey_forms WHERE code LIKE 'SMOKE_%')"
    );
    $pdo->exec("DELETE FROM survey_forms WHERE code LIKE 'SMOKE_%'");

    $pdo->exec(
        "DELETE FROM notification_recipients WHERE notification_id IN (SELECT id FROM notifications WHERE title = 'Smoke')"
    );
    $pdo->exec("DELETE FROM notifications WHERE title = 'Smoke'");

    $pdo->exec(
        "DELETE FROM villages WHERE panchayat_id IN (
            SELECT id FROM panchayats WHERE block_id IN (
                SELECT id FROM blocks WHERE district_id = (SELECT id FROM districts WHERE name = 'Test Dist')
            )
        )"
    );
    $pdo->exec(
        "DELETE FROM panchayats WHERE block_id IN (
            SELECT id FROM blocks WHERE district_id = (SELECT id FROM districts WHERE name = 'Test Dist')
        )"
    );
    $pdo->exec("DELETE FROM blocks WHERE district_id = (SELECT id FROM districts WHERE name = 'Test Dist')");
    $pdo->exec("DELETE FROM districts WHERE name = 'Test Dist'");

    $pdo->commit();
    echo "Demo state reset." . PHP_EOL;
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
