<?php

/**
 * Drop this behind a web server as your webhook endpoint, or run it directly:
 *
 *   GOAL_WEBHOOK_SECRET=... php -S localhost:3100 examples/webhook-receiver.php
 *
 * The important part is that verification runs on the raw request body. `$_POST` is parsed
 * and re-ordered, which changes the bytes and breaks the HMAC; `php://input` is not.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GoalApi\Exceptions\WebhookSignatureException;
use GoalApi\Webhook;

$secret = getenv('GOAL_WEBHOOK_SECRET') ?: '';
if ($secret === '') {
    fwrite(STDERR, "GOAL_WEBHOOK_SECRET is not set.\n");
    exit(2);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $event = Webhook::verify(
        file_get_contents('php://input') ?: '',
        $_SERVER[Webhook::SIGNATURE_HEADER] ?? null,
        $secret,
    );
} catch (WebhookSignatureException $error) {
    error_log('rejected: ' . $error->getMessage());
    http_response_code(400);
    exit;
}

$type = $_SERVER[Webhook::EVENT_HEADER] ?? '';
error_log(sprintf('%s  delivery=%s', $type, $_SERVER[Webhook::DELIVERY_HEADER] ?? '?'));

match ($type) {
    Webhook::EVENT_GOAL_SCORED => error_log(sprintf(
        '  %s %s-%s %s',
        $event['homeTeam']['name'] ?? '?',
        $event['homeScore'] ?? '?',
        $event['awayScore'] ?? '?',
        $event['awayTeam']['name'] ?? '?',
    )),
    Webhook::EVENT_MATCH_FINISHED => error_log('  full time'),
    default => error_log('  ' . substr(json_encode($event) ?: '', 0, 120)),
};

// Ack fast. Retries are ~1m, 5m, 25m, 2h, 10h, so a slow handler earns duplicate
// deliveries; queue the real work instead of doing it here.
http_response_code(200);
