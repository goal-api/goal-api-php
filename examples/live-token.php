<?php

/**
 * GOAL_API_KEY=... php examples/live-token.php
 *
 * PHP-FPM has nowhere to hold a socket open, so the PHP path to live data is to mint a
 * short-lived single-use token server side and hand it to the browser. The key never
 * reaches the client.
 *
 * In a real app the first half is a JSON endpoint behind your own auth, and the second half
 * is the JavaScript it feeds.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GoalApi\Exceptions\GoalApiException;
use GoalApi\GoalApi;

$apiKey = getenv('GOAL_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "GOAL_API_KEY is not set.\n");
    exit(2);
}

$goal = new GoalApi($apiKey);

try {
    $live = $goal->fixtures->live()['data'];
    printf("%d match(es) in play\n", \count($live));

    $token = $goal->mintConnectToken();
    $payload = [
        'url' => $goal->webSocketUrl(),
        'token' => $token['token'],
        'expiresIn' => $token['expiresIn'],
        'matchIds' => array_column(\array_slice($live, 0, 5), 'id'),
    ];

    echo "\n// what your endpoint returns to the browser\n";
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    echo <<<'JS'

// what the browser then does with it
const { url, token, matchIds } = await fetch('/api/live-token').then((r) => r.json());
const socket = new WebSocket(`${url}?wsToken=${token}`);

socket.onopen = () => {
    // The auth frame must be the first message, or the server closes with 4001.
    socket.send(JSON.stringify({ type: 'auth', token }));
};

socket.onmessage = (event) => {
    const message = JSON.parse(event.data);
    if (message.type === 'auth_success') {
        for (const matchId of matchIds) {
            socket.send(JSON.stringify({ type: 'subscribe', resource: 'match', matchId }));
        }
    } else if (message.type === 'match_update') {
        console.log(message.data);
    }
};

JS;

    printf("\ntoken expires in %ds and is consumed on first connect, so mint one per connection\n",
        $token['expiresIn']);
} catch (GoalApiException $error) {
    fwrite(STDERR, sprintf("%s: %s\n", $error->errorCode ?? 'ERROR', $error->getMessage()));
    exit(1);
}
