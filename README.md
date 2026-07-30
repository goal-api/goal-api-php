# goal-api/sdk

PHP SDK for the [GOAL API](https://goal-api.com): football fixtures, live scores,
standings, player stats and odds.

No framework dependency and no Guzzle. PHP 8.1+ with the curl and json extensions, so it
installs into a project that already pins a conflicting HTTP client.

```bash
composer require goal-api/sdk
```

## Quick start

```php
use GoalApi\GoalApi;

$goal = new GoalApi(getenv('GOAL_API_KEY'));

foreach ($goal->fixtures->live()['data'] as $match) {
    printf(
        "%s %d-%d %s\n",
        $match['homeTeam']['name'],
        $match['homeScore'],
        $match['awayScore'],
        $match['awayTeam']['name'],
    );
}
```

Get a key at [goal-api.com/signup](https://goal-api.com/signup).

Share one client: curl connection reuse and the rate-limit snapshot are per-instance. In
Laravel or Symfony, register it as a singleton.

## Client options

Named arguments, so you only pass what you're changing:

```php
$goal = new GoalApi(
    apiKey: getenv('GOAL_API_KEY'),
    timeout: 15.0,                 // per attempt, seconds; default 30
    maxRetries: 3,                 // 429 + 5xx + network errors; default 2
    headers: ['X-My-App' => 'scoreboard'],
);
```

Retries use exponential backoff with full jitter and always honour a server-sent
`Retry-After`.

### Using your own HTTP stack

If your app mandates Guzzle, a PSR-18 client, or an instrumented transport, pass an
`$httpHandler` and the SDK routes every request through it instead of curl:

```php
$goal = new GoalApi(
    apiKey: $key,
    httpHandler: function (string $method, string $url, array $headers, ?string $body): array {
        $response = $myClient->request($method, $url, [...]);
        return [$response->getStatusCode(), $normalisedHeaders, (string) $response->getBody()];
    },
);
```

Response header keys must be lowercased. This is the same seam the SDK's own tests use.

## Endpoints

Grouped by resource. Params are a plain array; nulls and empty strings are dropped, so you
can build one unconditionally. Full reference in [`ENDPOINTS.md`](ENDPOINTS.md).

```php
$goal->status->get();                                  // no API key needed
$goal->countries->list(['search' => 'spa']);
$goal->leagues->list(['isActive' => true, 'limit' => 100]);
$goal->leagues->standings($leagueId);
$goal->leagues->topScorers($leagueId, ['limit' => 10]);
$goal->teams->get($teamId, ['includePlayers' => true]);
$goal->teams->statistics($teamId, ['season' => '2025-2026']);
$goal->fixtures->list(['from' => '2026-08-01', 'to' => '2026-08-07', 'status' => 'SCHEDULED']);
$goal->fixtures->byDate('2026-08-15', ['leagueId' => $leagueId]);
$goal->fixtures->lineups($fixtureId);
$goal->fixtures->statistics($fixtureId, ['half' => '1half']);
$goal->standings->form($leagueId);
$goal->players->search('haaland', ['limit' => 5]);
$goal->players->compare([$playerA, $playerB]);
$goal->players->top('goals', ['limit' => 20]);
$goal->coaches->byTeam($teamId);
$goal->h2h->stats($teamA, $teamB);
$goal->results->today();
$goal->videos->recent(['leagueId' => $leagueId, 'limit' => 10]);
$goal->odds->list(['bookmaker' => 'bet365']);
$goal->predictions->list(['matchId' => $matchId]);
```

Enum values are constants: `GoalApi::MATCH_STATUSES`, `::PLAYER_TYPES`, `::PLAYER_STATS`,
`::HALVES`.

Every method returns the raw decoded envelope, so `pagination` and `source` stay reachable:

```php
$page = $goal->teams->list(['leagueId' => $leagueId, 'limit' => 50]);
$page['data'];                  // array of teams
$page['pagination']['hasMore']; // bool
$page['source'];                // 'cache' | 'database'
```

### One exception: `$goal->status`

The five `/public/*` endpoints don't use the `['success', 'data']` envelope. They return
bare objects, so read them directly with no `['data']`:

```php
$status = $goal->status->get();
$status['status'];      // 'operational'
$status['components'];  // array of ['name', 'status', 'uptime']
```

They also paginate with `page`/`limit` instead of `limit`/`offset`, so `paginate()` does not
apply to `coverageLeagues`.

## Pagination

`paginate()` is a generator, so memory stays flat however large the collection:

```php
foreach ($goal->paginate(fn (array $p) => $goal->leagues->teams($leagueId, $p)) as $team) {
    echo $team['name'], "\n";
}

// Or collect, with a cap. /results accepts limit up to 500:
$recent = $goal->collect(
    fn (array $p) => $goal->results->list(['leagueId' => $leagueId, ...$p]),
    pageSize: 500,
    maxItems: 500,
);
```

Default `pageSize` is 100, the limit ceiling on most endpoints.

## Errors

Everything thrown extends `GoalApiException`. Branch only where you'd actually behave
differently:

```php
use GoalApi\Exceptions\{GoalApiException, NotFoundException, RateLimitException, ValidationException};

try {
    $fixture = $goal->fixtures->get($fixtureId);
} catch (NotFoundException) {
    $fixture = null;
} catch (RateLimitException $error) {
    logger()->warning("Quota exhausted ({$error->rateLimitType}), retry in {$error->retryAfter}s");
    throw $error;
} catch (ValidationException $error) {
    logger()->error('Server rejected the request', ['details' => $error->details]);
    throw $error;
} catch (GoalApiException $error) {
    logger()->error($error->getMessage(), [
        'code' => $error->errorCode,
        'correlationId' => $error->correlationId,
    ]);
    throw $error;
}
```

Types: `ValidationException`, `AuthenticationException`, `PermissionException`,
`PlanUpgradeRequiredException`, `NotFoundException`, `ConflictException`,
`RateLimitException`, `ServiceUnavailableException`, `ServerException`,
`ConnectionException`, `TimeoutException`.

> `$error->errorCode` holds the API's code, e.g. `VALIDATION_ERROR`. It is named that
> rather than `code` because `Exception::$code` is an int in PHP and already carries the
> HTTP status.
### Two error shapes

The API answers with one of two bodies, and the SDK normalises both:

| | Gateway (auth, routing, rate limits) | Football service (most endpoints) |
|---|---|---|
| text | `message` | `error` |
| `code` | yes | yes |
| `category` | yes | no |
| `correlationId` | yes | **no** |
| `details` | object | array, on validation errors |

So `$error->getMessage()` and `$error->errorCode` are always populated, and `$error->correlationId` is
only set on gateway errors. Quote it in a support ticket when you have it.

### Rate limits

```php
$goal->fixtures->live();

$quota = $goal->rateLimit();
$quota->remaining;   // ?int
$quota->reset;       // unix seconds
$quota->type;        // 'DAILY' | 'MONTHLY'
```

## Live updates

There is no WebSocket client here: PHP-FPM has nowhere to hold a long-lived socket open,
so a live feed belongs in a worker (ReactPHP, Swoole, Amp) or in your frontend.

The intended path is for your backend to hold the API key and hand the browser a
short-lived, single-use token, so the key never reaches the client:

```php
// GET /api/live-token  (behind your own auth)
$token = $goal->mintConnectToken();

return response()->json([
    'url' => $goal->webSocketUrl(),   // wss://api.goal-api.com/v1/ws
    'token' => $token['token'],
    'expiresIn' => $token['expiresIn'],
]);
```

```js
// Frontend
const { url, token } = await fetch('/api/live-token').then((r) => r.json());
const socket = new WebSocket(`${url}?wsToken=${token}`);

socket.onopen = () => socket.send(JSON.stringify({ type: 'subscribe', resource: 'match', matchId }));
socket.onmessage = (event) => {
    const message = JSON.parse(event.data);
    if (message.type === 'match_update') { /* ... */ }
};
```

The token is consumed on first connect, so mint one per connection. If you do run a
long-lived PHP worker, `webSocketUrl()` plus an `Authorization: Bearer` header on the
handshake works there and needs no token at all.

The server caps client messages at 60/minute and concurrent subscriptions by plan. Only
`resource: "match"` is supported. Server message types: `match_update`, `auth_success`,
`status`, `pong`, `server_shutdown`, `error`.

## Webhooks

Verify against the **raw** body: `php://input`, not `$_POST`. A decoded-and-re-encoded
array has different bytes and will never match.

```php
use GoalApi\Webhook;
use GoalApi\Exceptions\WebhookSignatureException;

try {
    $event = Webhook::verify(
        file_get_contents('php://input'),
        $_SERVER[Webhook::SIGNATURE_HEADER] ?? null,
        getenv('GOAL_WEBHOOK_SECRET'),
    );
} catch (WebhookSignatureException) {
    http_response_code(400);
    exit;
}

match ($_SERVER[Webhook::EVENT_HEADER] ?? '') {
    Webhook::EVENT_GOAL_SCORED => handleGoal($event),
    Webhook::EVENT_MATCH_FINISHED => handleFinished($event),
    default => null,
};

http_response_code(200);   // ack fast; retries are ~1m, 5m, 25m, 2h, 10h
```

In Laravel, disable CSRF for the route and use `$request->getContent()` for the raw body.

Timestamps outside 300s are rejected as replays. Override with `tolerance:`.

## Escape hatch

For an endpoint this SDK doesn't wrap yet:

```php
$data = $goal->request('/some/new/endpoint', ['limit' => 10]);
```

## Testing

```bash
composer install
vendor/bin/phpunit --exclude-group live      # unit tests, no network
GOAL_API_KEY=... vendor/bin/phpunit          # also runs the live tests
composer lint
php examples/basic.php
```

The live tests skip themselves without a key. Endpoint-by-endpoint coverage of the API
lives in `tools/sweep.py` in the SDK workspace.

## License

MIT
