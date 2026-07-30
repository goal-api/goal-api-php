<?php

/**
 * GOAL_API_KEY=... php examples/basic.php
 *
 * The status section works without a key.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GoalApi\Exceptions\GoalApiException;
use GoalApi\Exceptions\RateLimitException;
use GoalApi\GoalApi;

$apiKey = getenv('GOAL_API_KEY') ?: '';
$goal = new GoalApi($apiKey !== '' ? $apiKey : 'unset');

try {
    // --- public status: no API key required ---------------------------------
    // Note: /public/* returns a bare object, not the ['success', 'data'] envelope.
    $status = $goal->status->get();
    printf("Platform: %s\n", $status['status'] ?? '?');
    foreach ($status['components'] ?? [] as $component) {
        printf(
            "  %-28s %-14s 24h %s%%\n",
            $component['name'],
            $component['status'],
            $component['uptime']['24h'] ?? '?',
        );
    }

    if ($apiKey === '') {
        echo "\nSet GOAL_API_KEY to run the authenticated examples.\n";
        exit(0);
    }

    // --- live matches -------------------------------------------------------
    $live = $goal->fixtures->live()['data'];
    printf("\n%d match(es) in play\n", \count($live));
    foreach (\array_slice($live, 0, 5) as $match) {
        printf(
            "  %s %s-%s %s (%s')\n",
            $match['homeTeam']['name'] ?? '?',
            $match['homeScore'] ?? 0,
            $match['awayScore'] ?? 0,
            $match['awayTeam']['name'] ?? '?',
            $match['minute'] ?? '?',
        );
    }

    // --- pick a league and read its table -----------------------------------
    $leagues = $goal->leagues->list(['isActive' => true, 'limit' => 1])['data'];
    if ($leagues === []) {
        exit(0);
    }
    $league = $leagues[0];

    printf("\nStandings: %s\n", $league['name']);
    $table = $goal->leagues->standings($league['id'])['data'];
    foreach (\array_slice(\is_array($table) ? $table : [], 0, 5) as $row) {
        printf(
            "  %2s. %-24s %s pts\n",
            $row['position'] ?? '?',
            $row['team']['name'] ?? $row['teamName'] ?? '?',
            $row['points'] ?? 0,
        );
    }

    printf("\nTop scorers: %s\n", $league['name']);
    $scorers = $goal->leagues->topScorers($league['id'], ['limit' => 5])['data'];
    foreach (\is_array($scorers) ? $scorers : [] as $scorer) {
        printf("  %-24s %s\n", $scorer['player']['name'] ?? $scorer['name'] ?? '?', $scorer['goals'] ?? 0);
    }

    // --- pagination across every team in the league --------------------------
    $teams = $goal->collect(fn (array $p) => $goal->leagues->teams($league['id'], $p));
    printf("\n%d teams in %s\n", \count($teams), $league['name']);

    $quota = $goal->rateLimit();
    printf(
        "\nQuota: %s/%s remaining (%s, resets at %s)\n",
        $quota->remaining ?? '?',
        $quota->limit ?? '?',
        $quota->type ?? '?',
        $quota->reset !== null ? date('c', $quota->reset) : '?',
    );
} catch (RateLimitException $error) {
    fwrite(STDERR, sprintf("Rate limited (%s). Retry in %ds.\n", $error->rateLimitType, $error->retryAfter));
    exit(1);
} catch (GoalApiException $error) {
    fwrite(STDERR, sprintf("%s: %s [%s]\n", $error->errorCode ?? 'ERROR', $error->getMessage(), $error->correlationId));
    exit(1);
}
