<?php

declare(strict_types=1);

/**
 * Replication worker (CLI).
 *
 * Usage:
 *   php replication/worker.php              # process jobs until queue empty
 *   php replication/worker.php --once       # process a single job
 *   php replication/worker.php --daemon     # loop forever, sleeping between batches
 */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Services\ReplicationService;

$flags = array_slice($argv, 1);
$once = in_array('--once', $flags, true);
$daemon = in_array('--daemon', $flags, true);

$service = new ReplicationService();

if ($once) {
    $processed = $service->processOne();
    echo $processed ? "Job processed.\n" : "Queue empty.\n";
    exit(0);
}

echo "Replication worker started at " . date('c') . "\n";
while (true) {
    $processed = $service->processOne();
    if (!$processed && !$daemon) {
        echo "Queue empty.\n";
        break;
    }
    if (!$processed) {
        sleep(5);
    }
    if ($daemon && $service->nextPending() === null) {
        // re-queue was consumed by processOne; just keep polling
        sleep(5);
    }
}
