<?php

/**
 * Full sync of all entity types.
 *
 * Runs accounts, contacts, and activities in the correct dependency order.
 * This is the recommended approach for scheduled (cron) syncs.
 *
 * Usage: php examples/full-sync.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$engine->testConnections();

$results = $engine->fullSync();

foreach ($results->toArray() as $type => $result) {
    $logger->info($result->getSummary(ucfirst($type)));
}

// A step-level failure (adapter fault, misconfiguration, skipped dependency) does
// not throw — fullSync() keeps the healthy steps running — so a scheduled run has
// to check for it explicitly or a total outage would look like a success.
if ($results->hasStepFailures()) {
    foreach ($results->stepFailures as $entityType => $error) {
        $logger->error(sprintf('Sync step %s failed: %s', $entityType, $error));
    }

    $logger->error('Full sync incomplete');
    exit(1);
}

$logger->info('Full sync complete');
