<?php

/**
 * GOAL_API_KEY=... php examples/live-worker.php
 *
 * A php-cli worker on the live socket: subscribes to whatever is in play and prints every
 * frame the server sends. Exits non-zero if nothing arrives, so it doubles as a smoke test.
 *
 * This is the worker-side path. If the live data is headed for a browser anyway, mint a
 * token instead and connect from there — see live-token.php.
 *
 * LISTEN_SECONDS=60 changes how long it stays connected.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GoalApi\Exceptions\GoalApiException;
use GoalApi\GoalApi;
use GoalApi\Live\LiveClient;

$apiKey = getenv('GOAL_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "GOAL_API_KEY is not set.\n");
    exit(2);
}

$listenSeconds = (float) (getenv('LISTEN_SECONDS') ?: 30);

$goal = new GoalApi($apiKey);

try {
    $fixtures = $goal->fixtures->live()['data'];
} catch (GoalApiException $error) {
    fwrite(STDERR, "Could not list live fixtures: {$error->getMessage()}\n");
    exit(1);
}

printf("%d match(es) in play\n", \count($fixtures));
if ($fixtures === []) {
    echo "Nothing is live right now, so there is nothing to subscribe to.\n";
    exit(0);
}

// The plan caps concurrent subscriptions, so do not ask for more than a handful here.
$fixtures = \array_slice($fixtures, 0, 5);
foreach ($fixtures as $match) {
    printf("  %s  %s v %s\n", $match['id'], $match['homeTeam']['name'], $match['awayTeam']['name']);
}

$live = $goal->live();
$seen = [];

$live->on(LiveClient::EVENT_ANY, function (array $message) use (&$seen): void {
    $type = $message['type'] ?? '';
    $seen[$type] = ($seen[$type] ?? 0) + 1;
});

$live->on('auth_success', function (array $message): void {
    $plan = $message['data']['plan'] ?? '?';
    $max = $message['data']['maxSubscriptions'] ?? 0;
    printf("\nauthenticated: plan=%s maxSubscriptions=%d\n", $plan, $max);
    if ($max === 0) {
        echo "  this plan allows 0 concurrent subscriptions, so no match_update can arrive\n";
    }
});

// A match_update is the provider's live shape, not the REST fixture shape: scores are
// strings and match_status is the minute. Reading it with the keys /fixtures uses would
// quietly give you nothing.
$live->on('match_update', function (array $message): void {
    $data = $message['data'] ?? [];
    printf(
        "  UPDATE  %-22s %s-%s %-22s  %s'  (%s)\n",
        $data['match_hometeam_name'] ?? '?',
        $data['match_hometeam_score'] ?? '-',
        $data['match_awayteam_score'] ?? '-',
        $data['match_awayteam_name'] ?? '?',
        $data['match_status'] ?? '?',
        // The fixture id we subscribed with; match_id is the provider's.
        $data['id'] ?? '?'
    );
});

$live->on('error', function (array $message): void {
    printf("  ERROR   %s %s\n", $message['code'] ?? '', $message['message'] ?? '');
});

try {
    // connect() returns once the server has accepted the auth frame, so subscribing
    // immediately afterwards is safe.
    $live->connect();

    foreach ($fixtures as $match) {
        $live->subscribe((string) $match['id']);
    }

    printf("\nlistening for %ds ...\n", (int) $listenSeconds);

    $deadline = microtime(true) + $listenSeconds;
    $live->run(static fn (): bool => microtime(true) >= $deadline);
} catch (GoalApiException $error) {
    fwrite(STDERR, "\nLive feed failed: {$error->getMessage()}\n");
    exit(1);
} finally {
    $live->close();
}

echo "\nreceived: " . json_encode($seen, \JSON_THROW_ON_ERROR) . "\n";

if (($seen['auth_success'] ?? 0) === 0) {
    fwrite(STDERR, "Nothing was received from the socket.\n");
    exit(1);
}
