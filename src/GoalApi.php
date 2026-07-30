<?php

declare(strict_types=1);

namespace GoalApi;

use Generator;
use GoalApi\Exceptions\AuthenticationException;
use GoalApi\Resources;

/**
 * The GOAL API client.
 *
 * ```php
 * $goal = new GoalApi(getenv('GOAL_API_KEY'));
 *
 * foreach ($goal->fixtures->live()['data'] as $match) {
 *     echo "{$match['homeTeam']['name']} {$match['homeScore']}\n";
 * }
 * ```
 *
 * Share one instance: curl connection reuse and the rate-limit snapshot are per-instance.
 * In Laravel or Symfony, register it as a singleton.
 */
final class GoalApi
{
    /** Values accepted by the `status` query param. */
    public const MATCH_STATUSES = [
        'SCHEDULED', 'LIVE', 'FINISHED', 'HALF_TIME', 'AFTER_ET', 'AFTER_PEN',
        'POSTPONED', 'CANCELLED', 'AWARDED', 'ABANDONED', 'SUSPENDED',
    ];

    /** Values accepted by the `type` query param on player endpoints. */
    public const PLAYER_TYPES = ['Goalkeepers', 'Defenders', 'Midfielders', 'Forwards'];

    /** Values accepted as the stat path segment of `/players/top/{stat}`. */
    public const PLAYER_STATS = [
        'goals', 'assists', 'yellowCards', 'redCards', 'rating', 'matchPlayed', 'minutes',
        'saves', 'tackles', 'shotsTotal', 'keyPasses', 'passes', 'interceptions',
        'duelsWon', 'dribbleSucc',
    ];

    /** Values accepted by the `half` query param on fixture statistics. */
    public const HALVES = ['full', '1half', '2half'];

    private const DEFAULT_PAGE_SIZE = 100;

    private readonly Transport $transport;

    /**
     * Unauthenticated status and coverage. Returns bare objects, not the
     * `['success', 'data']` envelope. See {@see Resources\Status}.
     */
    public readonly Resources\Status $status;
    public readonly Resources\Countries $countries;
    public readonly Resources\Leagues $leagues;
    public readonly Resources\Teams $teams;
    public readonly Resources\Fixtures $fixtures;
    public readonly Resources\Standings $standings;
    public readonly Resources\Players $players;
    public readonly Resources\Coaches $coaches;
    public readonly Resources\HeadToHead $h2h;
    public readonly Resources\Results $results;
    public readonly Resources\Videos $videos;
    public readonly Resources\Odds $odds;
    public readonly Resources\Predictions $predictions;

    /**
     * @param string                $apiKey      From https://goal-api.com/dashboard
     * @param float                 $timeout     Per attempt, in seconds
     * @param int                   $maxRetries  Retries on 429, 5xx and network errors
     * @param array<string, string> $headers     Sent on every request
     * @param null|callable(string, string, list<string>, ?string): array{0: int, 1: array<string, string>, 2: string} $httpHandler
     *   Replaces curl with your own transport.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = Transport::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        int $maxRetries = 2,
        array $headers = [],
        ?string $userAgent = null,
        ?callable $httpHandler = null,
    ) {
        $this->transport = new Transport($apiKey, $baseUrl, $timeout, $maxRetries, $headers, $userAgent, $httpHandler);

        $this->status = new Resources\Status($this->transport);
        $this->countries = new Resources\Countries($this->transport);
        $this->leagues = new Resources\Leagues($this->transport);
        $this->teams = new Resources\Teams($this->transport);
        $this->fixtures = new Resources\Fixtures($this->transport);
        $this->standings = new Resources\Standings($this->transport);
        $this->players = new Resources\Players($this->transport);
        $this->coaches = new Resources\Coaches($this->transport);
        $this->h2h = new Resources\HeadToHead($this->transport);
        $this->results = new Resources\Results($this->transport);
        $this->videos = new Resources\Videos($this->transport);
        $this->odds = new Resources\Odds($this->transport);
        $this->predictions = new Resources\Predictions($this->transport);
    }

    /**
     * Quota from the last response. All null until the first authenticated call.
     */
    public function rateLimit(): RateLimit
    {
        return $this->transport->rateLimit();
    }

    public function baseUrl(): string
    {
        return $this->transport->baseUrl();
    }

    /**
     * For endpoints not wrapped here yet.
     *
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function request(string $path, ?array $params = null): array
    {
        return $this->transport->get($path, $params);
    }

    /**
     * Walks pages, yielding rows.
     *
     * ```php
     * foreach ($goal->paginate(fn (array $p) => $goal->leagues->teams($leagueId, $p)) as $team) {
     *     echo $team['name'], "\n";
     * }
     * ```
     *
     * 100 is the limit ceiling on most endpoints; `/results` and `/countries` take 500.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fetchPage
     *
     * @return Generator<int, mixed>
     */
    public function paginate(
        callable $fetchPage,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        ?int $maxItems = null,
        int $startOffset = 0,
    ): Generator {
        $offset = $startOffset;
        $yielded = 0;

        while ($maxItems === null || $yielded < $maxItems) {
            $page = $fetchPage(['limit' => $pageSize, 'offset' => $offset]);
            $items = $page['data'] ?? null;
            if (!\is_array($items) || $items === []) {
                return;
            }

            foreach ($items as $item) {
                yield $item;
                $yielded++;
                if ($maxItems !== null && $yielded >= $maxItems) {
                    return;
                }
            }

            // Some endpoints omit pagination; a short page is the only other signal.
            $pagination = $page['pagination'] ?? null;
            if (\is_array($pagination) && \array_key_exists('hasMore', $pagination)) {
                if ($pagination['hasMore'] !== true) {
                    return;
                }
            } elseif (\count($items) < $pageSize) {
                return;
            }

            $offset += \count($items);
        }
    }

    /**
     * {@see paginate()} collected into an array.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $fetchPage
     *
     * @return list<mixed>
     */
    public function collect(
        callable $fetchPage,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        ?int $maxItems = null,
        int $startOffset = 0,
    ): array {
        return iterator_to_array($this->paginate($fetchPage, $pageSize, $maxItems, $startOffset), false);
    }

    /**
     * The live WebSocket endpoint, derived from the base URL.
     *
     * The path is `/ws` on the host root, not `/v1/ws`. nginx routes the socket with
     * `location ^~ /ws`, the only location carrying the Upgrade headers; `/v1/ws` falls
     * into the REST location and silently answers 200 rather than upgrading.
     *
     * No WebSocket client here: PHP-FPM has nowhere to hold a long-lived socket open, so a
     * live feed belongs in a worker (ReactPHP, Swoole, Amp) or in your frontend. Use this
     * URL with {@see mintConnectToken()} and {@see authMessage()}.
     */
    public function webSocketUrl(): string
    {
        $parts = parse_url($this->transport->baseUrl());
        if ($parts === false || !isset($parts['host'])) {
            return $this->transport->baseUrl() . '/ws';
        }

        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'ws' : 'wss';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$parts['host']}{$port}/ws";
    }

    /**
     * The frame that must be sent first on a new connection.
     *
     * Two services authenticate: the gateway authorises the upgrade from the
     * `Authorization` header, then websocket-service requires this frame as the very first
     * message and closes with 4001 if anything else arrives first.
     *
     * Pass a token from {@see mintConnectToken()} for a browser, so the key stays server
     * side; omit it in a PHP worker that already sent the header.
     *
     * @return array{type: string, apiKey?: string, token?: string}
     */
    public function authMessage(?string $token = null): array
    {
        if ($token !== null) {
            return ['type' => 'auth', 'token' => $token];
        }

        return ['type' => 'auth', 'apiKey' => $this->transport->apiKey()];
    }

    /**
     * A short-lived, single-use WebSocket handshake token.
     *
     * The usual PHP path to live data: your backend holds the API key and hands the browser
     * a token, so the key never reaches the client. Consumed on first connect.
     *
     * ```php
     * $token = $goal->mintConnectToken();
     * // JS: new WebSocket(`${wsUrl}?wsToken=${token['token']}`)
     * ```
     *
     * @return array{token: string, expiresIn: int}
     */
    public function mintConnectToken(): array
    {
        $response = $this->transport->post('/ws/token');
        $data = $response['data'] ?? null;

        if (!\is_array($data) || !isset($data['token'])) {
            throw new AuthenticationException('Server did not return a WebSocket connection token');
        }

        return ['token' => (string) $data['token'], 'expiresIn' => (int) ($data['expiresIn'] ?? 0)];
    }
}
