# goal-api/sdk

PHP SDK for the [GOAL API](https://goal-api.com): football fixtures, live scores,
standings, player stats, odds and real-time match updates over WebSocket.

No framework dependency and no Guzzle. PHP 8.1+ with the curl, json and openssl
extensions, so it installs into a project that already pins a conflicting HTTP client.
The live WebSocket client needs php-cli, since PHP-FPM cannot hold a socket open.

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

> The socket is at `wss://api.goal-api.com/ws`, **not** `/v1/ws`. Only nginx's
> `location ^~ /ws` carries the `Upgrade` headers; `/v1/ws` is proxied as ordinary HTTP and
> answers 200 instead of upgrading. The SDK derives the right URL for you.
>
> Two services authenticate: the gateway authorises the upgrade from the header or
> `?wsToken=`, then websocket-service needs an `{"type": "auth", ...}` frame as the very
> first message. The SDK sends it, and treats `auth_success` as the point the connection is
> usable.
>
> **`subscribe` is capped per plan and the cap can be 0.** `auth_success` reports
> `maxSubscriptions`; if it is 0 the socket works but no `match_update` will ever arrive.
> See the known server issue in [`ENDPOINTS.md`](ENDPOINTS.md).

There are two ways in, and which one you want depends on where the socket lives.

### In a php-cli worker

`$goal->live()` is a blocking WebSocket client, written on PHP streams with no
dependencies. It handles the handshake, auth, keepalives and reconnection.

```php
$live = $goal->live();

$live->on('match_update', function (array $message) {
    echo $message['data']['id'], PHP_EOL;
});

$live->connect();           // returns once auth_success arrives
$live->subscribe($matchId);
$live->run();               // blocks
```

Or drive it yourself, which is what you want inside an existing loop:

```php
$live->connect();
$live->subscribe($matchId);

while (($message = $live->receive(timeout: 1.0)) !== null) {
    // ... your own work between messages
}
```

`receive()` returns `null` when the timeout expires with nothing to read, so a quiet feed
never blocks your loop. Handlers registered with `on()` fire from both `receive()` and
`run()`. Subscriptions are replayed after a reconnect, because the server does not
remember a dropped connection's.

**This needs php-cli.** It holds a socket open for as long as you want updates, which is
exactly what PHP-FPM cannot do — the worker is tied to a request and gets recycled out
from under the connection. The constructor throws under any SAPI other than `cli`,
`phpdbg` or `embed`; pass `allowAnySapi: true` only if you know yours can hold a
long-lived socket (Swoole, RoadRunner, a custom embed).

Options: `autoReconnect`, `maxReconnectAttempts`, `pingInterval`, `authTimeout`,
`readTimeout`, `connectTimeout`, `streamContext`, `connectToken`, `url`.

### From a browser

If the live data is headed for a frontend anyway, skip the worker. Your backend holds the
API key and hands the browser a short-lived, single-use token, so the key never reaches
the client:

```php
// GET /api/live-token  (behind your own auth)
$token = $goal->mintConnectToken();

return response()->json([
    'url' => $goal->webSocketUrl(),   // wss://api.goal-api.com/ws
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

The token is consumed on first connect, so mint one per connection.

The server caps client messages at 60/minute and concurrent subscriptions by plan. Only
`resource: "match"` is supported. Server message types: `match_update`, `auth_success`,
`status`, `pong`, `server_shutdown`, `error`, `subscribe_response`,
`unsubscribe_response`, `get_subscriptions_response`. `LiveClient` adds `open` and `close`
for the transport itself, and `*` receives everything.

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

## Examples

| File | Shows |
|---|---|
| [`examples/basic.php`](examples/basic.php) | Status, live fixtures, standings, pagination |
| [`examples/live-worker.php`](examples/live-worker.php) | A php-cli worker on the live socket |
| [`examples/live-token.php`](examples/live-token.php) | Minting a browser token for live data, with the JS to use it |
| [`examples/webhook-receiver.php`](examples/webhook-receiver.php) | Verifying a webhook against `php://input` |
| [`examples/bulk-export.php`](examples/bulk-export.php) | Walking every page of a collection to CSV |

```bash
GOAL_API_KEY=...        php examples/live-worker.php
GOAL_API_KEY=...        php examples/live-token.php
GOAL_WEBHOOK_SECRET=... php -S localhost:3100 examples/webhook-receiver.php
GOAL_API_KEY=...        php examples/bulk-export.php > countries.csv
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

## Other languages

Four more first-party clients over the same API, with the same resource groups, the same
retry and pagination behaviour and the same error types. All five release in lockstep, so
a version number means the same surface everywhere.

| Language | Package | Install |
|---|---|---|
| [JavaScript / TypeScript](https://github.com/goal-api/goal-api-js) | [`@goalapi/sdk`](https://www.npmjs.com/package/@goalapi/sdk) | `npm install @goalapi/sdk` |
| [Python](https://github.com/goal-api/goal-api-python) | [`goal-api`](https://pypi.org/project/goal-api/) | `pip install goal-api` |
| [Go](https://github.com/goal-api/goal-api-go) | [`goal-api-go`](https://pkg.go.dev/github.com/goal-api/goal-api-go) | `go get github.com/goal-api/goal-api-go` |
| [Dart / Flutter](https://github.com/goal-api/goal-api-dart) | [`goal_api`](https://pub.dev/packages/goal_api) | `dart pub add goal_api` |

Each one is its own repository and carries the same [`ENDPOINTS.md`](ENDPOINTS.md), the
API contract derived from the running service.

## Licence

MIT. See [LICENSE](LICENSE).

No runtime dependencies: curl and json are PHP extensions, not composer packages, which is
what keeps `vendor/` at one package. See
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

Security issues: [SECURITY.md](SECURITY.md).
