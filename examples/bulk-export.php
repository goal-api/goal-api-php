<?php

/**
 * GOAL_API_KEY=... php examples/bulk-export.php > countries.csv
 *
 * Walks every page of a collection and streams it out as CSV. paginate() is a generator,
 * so memory stays flat however large the collection.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GoalApi\GoalApi;

$apiKey = getenv('GOAL_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "GOAL_API_KEY is not set.\n");
    exit(2);
}

$goal = new GoalApi($apiKey);
$out = fopen('php://stdout', 'wb');
fputcsv($out, ['id', 'name', 'code', 'isActive']);

$rows = 0;
// /countries accepts limit up to 500; most endpoints cap at 100.
foreach ($goal->paginate(fn (array $p) => $goal->countries->list($p), pageSize: 500) as $country) {
    fputcsv($out, [
        $country['id'] ?? '',
        $country['name'] ?? '',
        $country['code'] ?? '',
        ($country['isActive'] ?? false) ? 'true' : 'false',
    ]);
    $rows++;
}
fclose($out);

$quota = $goal->rateLimit();
fprintf(STDERR, "\n%d countries exported\n", $rows);
fprintf(STDERR, "quota: %s/%s left\n", $quota->remaining ?? '?', $quota->limit ?? '?');
