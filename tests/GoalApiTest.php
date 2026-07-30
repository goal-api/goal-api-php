<?php

declare(strict_types=1);

namespace GoalApi\Tests;

use GoalApi\Exceptions\AuthenticationException;
use GoalApi\Exceptions\ConflictException;
use GoalApi\Exceptions\NotFoundException;
use GoalApi\Exceptions\PermissionException;
use GoalApi\Exceptions\PlanUpgradeRequiredException;
use GoalApi\Exceptions\RateLimitException;
use GoalApi\Exceptions\ServerException;
use GoalApi\Exceptions\ServiceUnavailableException;
use GoalApi\Exceptions\ValidationException;
use GoalApi\Exceptions\WebhookSignatureException;
use GoalApi\GoalApi;
use GoalApi\Webhook;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests against an injected $httpHandler. No network.
 *
 * Covers request shape (URL, headers, param encoding), error mapping, retries, pagination
 * and webhook signing.
 */
final class GoalApiTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    private array $seen = [];

    /**
     * @param list<array{0: int, 1: array<string, string>, 2: string}> $responses
     *   Replayed in order; the last one repeats.
     */
    private function client(array $responses, int $maxRetries = 0): GoalApi
    {
        $this->seen = [];
        $seen = &$this->seen;

        $handler = static function (string $method, string $url, array $headers, ?string $body) use ($responses, &$seen): array {
            $seen[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            $index = \count($seen) - 1;

            return $responses[min($index, \count($responses) - 1)];
        };

        return new GoalApi('test-key', maxRetries: $maxRetries, httpHandler: $handler);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private static function json(array $body, int $status = 200, array $headers = []): array
    {
        return [$status, $headers, json_encode($body, JSON_THROW_ON_ERROR)];
    }

    /** @return array<string, string> */
    private function query(int $index = 0): array
    {
        $parsed = parse_url($this->seen[$index]['url'], PHP_URL_QUERY);
        if (!\is_string($parsed)) {
            return [];
        }
        parse_str($parsed, $query);

        /** @var array<string, string> $query */
        return $query;
    }

    // ------------------------------------------------------------ request shape

    public function testRejectsAnEmptyApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GoalApi('');
    }

    public function testSendsBearerHeaderToTheRightUrl(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => [['id' => 'x']]])]);

        $page = $goal->fixtures->live();
        self::assertCount(1, $page['data']);

        self::assertSame('https://api.goal-api.com/v1/fixtures/live', $this->seen[0]['url']);
        self::assertContains('Authorization: Bearer test-key', $this->seen[0]['headers']);
    }

    public function testDropsNullsAndLowercasesBooleans(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => []])]);

        $goal->fixtures->list([
            'leagueId' => 'L1',
            'status' => null,
            'teamId' => '',
            'offset' => 0,
            'live' => false,
        ]);

        $query = $this->query();
        self::assertSame('L1', $query['leagueId']);
        self::assertSame('0', $query['offset']);
        self::assertSame('false', $query['live']); // the API validates the literal strings
        self::assertArrayNotHasKey('status', $query);
        self::assertArrayNotHasKey('teamId', $query);
    }

    public function testEncodesPathSegments(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => []])]);

        $goal->coaches->byCountry('Trinidad And Tobago');
        self::assertSame(
            'https://api.goal-api.com/v1/coaches/country/Trinidad%20And%20Tobago',
            $this->seen[0]['url']
        );
    }

    public function testASlashInAnIdCannotEscapeItsSegment(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => null])]);

        $goal->fixtures->get('../../admin');
        self::assertSame('https://api.goal-api.com/v1/fixtures/..%2F..%2Fadmin', $this->seen[0]['url']);
    }

    public function testAnEmptyPathSegmentIsRejectedBeforeTheRequest(): void
    {
        $goal = $this->client([self::json([])]);

        try {
            $goal->fixtures->get('');
            self::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->seen);
        }
    }

    public function testCompareJoinsIdsWithCommas(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => []])]);

        $goal->players->compare(['p1', 'p2', 'p3']);
        self::assertSame('p1,p2,p3', $this->query()['ids']);
    }

    public function testSearchPutsQInTheQuery(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => []])]);

        $goal->players->search('haaland', ['limit' => 5]);
        $query = $this->query();
        self::assertSame('haaland', $query['q']);
        self::assertSame('5', $query['limit']);
    }

    // ------------------------------------------------------------ public endpoints

    /**
     * /public/* answers with bare objects rather than ['success', 'data'].
     */
    public function testPublicEndpointsReturnTheBareBody(): void
    {
        $goal = $this->client([self::json([
            'status' => 'operational',
            'components' => [['name' => 'API', 'status' => 'operational']],
        ])]);

        $status = $goal->status->get();
        self::assertSame('operational', $status['status']);
        self::assertCount(1, $status['components']);
        self::assertArrayNotHasKey('data', $status);
    }

    public function testCoverageLeaguesPaginatesWithPageNotOffset(): void
    {
        $goal = $this->client([self::json(['leagues' => [], 'total' => 0, 'page' => 2, 'limit' => 50, 'pages' => 1])]);

        $goal->status->coverageLeagues(['page' => 2, 'limit' => 50, 'country' => 'England']);
        $query = $this->query();
        self::assertSame('2', $query['page']);
        self::assertSame('England', $query['country']);
        self::assertArrayNotHasKey('offset', $query);
    }

    // ------------------------------------------------------------ errors

    public function testMaps400ToValidationExceptionAndKeepsEnvelopeFields(): void
    {
        $goal = $this->client([self::json([
            'success' => false,
            'message' => 'League ID is required',
            'code' => 'VALIDATION_ERROR',
            'category' => 'validation',
            'details' => ['field' => 'id'],
            'correlationId' => 'abc-123',
        ], 400)]);

        try {
            $goal->leagues->get('bad');
            self::fail('expected a ValidationException');
        } catch (ValidationException $error) {
            self::assertSame(400, $error->status);
            self::assertSame('VALIDATION_ERROR', $error->errorCode);
            self::assertSame('abc-123', $error->correlationId);
            self::assertSame(['field' => 'id'], $error->details);
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: class-string}>
     */
    public static function statusMappingProvider(): iterable
    {
        yield '401' => [401, AuthenticationException::class];
        yield '402' => [402, PlanUpgradeRequiredException::class];
        yield '403' => [403, PermissionException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '422' => [422, ValidationException::class];
        yield '429' => [429, RateLimitException::class];
        yield '500' => [500, ServerException::class];
        yield '503' => [503, ServiceUnavailableException::class];
    }

    /**
     * @dataProvider statusMappingProvider
     *
     * @param class-string $expected
     */
    public function testMapsStatusCodesToTheRightExceptionTypes(int $status, string $expected): void
    {
        $goal = $this->client([self::json(['success' => false, 'message' => 'nope'], $status)]);

        $this->expectException($expected);
        $goal->fixtures->live();
    }

    public function test429CarriesRetryAfterAndQuotaHeaders(): void
    {
        $goal = $this->client([self::json(
            ['success' => false, 'message' => 'Too many requests', 'code' => 'RATE_LIMIT_EXCEEDED'],
            429,
            [
                'retry-after' => '42',
                'x-ratelimit-limit' => '1000',
                'x-ratelimit-remaining' => '0',
                'x-ratelimit-reset' => '1800000000',
                'x-ratelimit-type' => 'DAILY',
            ],
        )]);

        try {
            $goal->fixtures->live();
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $error) {
            self::assertSame(42, $error->retryAfter);
            self::assertSame(1000, $error->limit);
            self::assertSame('DAILY', $error->rateLimitType);
        }
    }

    public function testAnHtmlErrorBodyStillProducesAUsefulMessage(): void
    {
        $goal = $this->client([[502, [], '<html><body>502 Bad Gateway</body></html>']]);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessageMatches('/502 Bad Gateway/');
        $goal->fixtures->live();
    }

    public function testRecordsRateLimitSnapshotFromASuccess(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => []], 200, [
            'x-ratelimit-limit' => '10000',
            'x-ratelimit-remaining' => '9997',
            'x-ratelimit-reset' => '1800000000',
            'x-ratelimit-type' => 'MONTHLY',
        ])]);

        $goal->results->today();
        self::assertSame(
            ['limit' => 10000, 'remaining' => 9997, 'reset' => 1800000000, 'type' => 'MONTHLY'],
            $goal->rateLimit()->toArray(),
        );
    }

    public function testAPublicResponseWithoutQuotaHeadersLeavesTheSnapshotAlone(): void
    {
        $goal = $this->client([self::json(['status' => 'operational'])]);

        $goal->status->get();
        self::assertNull($goal->rateLimit()->limit);
    }

    // ------------------------------------------------------------ retries

    public function testRetriesA503ThenSucceeds(): void
    {
        $goal = $this->client([
            self::json(['success' => false, 'message' => 'down'], 503),
            self::json(['success' => true, 'data' => [1]]),
        ], maxRetries: 2);

        $page = $goal->fixtures->live();
        self::assertCount(2, $this->seen);
        self::assertSame([1], $page['data']);
    }

    public function testDoesNotRetryA400(): void
    {
        $goal = $this->client([self::json(['success' => false, 'message' => 'bad'], 400)], maxRetries: 3);

        try {
            $goal->fixtures->live();
            self::fail('expected a ValidationException');
        } catch (ValidationException) {
            self::assertCount(1, $this->seen);
        }
    }

    // ------------------------------------------------------------ pagination

    public function testPaginateWalksPagesAndStopsOnHasMoreFalse(): void
    {
        $goal = $this->client([
            self::json([
                'success' => true,
                'data' => [['id' => 1], ['id' => 2]],
                'pagination' => ['total' => 3, 'limit' => 2, 'offset' => 0, 'hasMore' => true],
            ]),
            self::json([
                'success' => true,
                'data' => [['id' => 3]],
                'pagination' => ['total' => 3, 'limit' => 2, 'offset' => 2, 'hasMore' => false],
            ]),
        ]);

        $items = $goal->collect(fn (array $p) => $goal->teams->list($p), pageSize: 2);

        self::assertSame([1, 2, 3], array_column($items, 'id'));
        self::assertSame('2', $this->query(1)['offset']);
    }

    public function testPaginateHonoursMaxItems(): void
    {
        $goal = $this->client([self::json([
            'success' => true,
            'data' => [['id' => 1], ['id' => 2]],
            'pagination' => ['total' => 99, 'limit' => 2, 'offset' => 0, 'hasMore' => true],
        ])]);

        $items = $goal->collect(fn (array $p) => $goal->teams->list($p), pageSize: 2, maxItems: 2);

        self::assertCount(2, $items);
        self::assertCount(1, $this->seen);
    }

    public function testPaginateStopsOnAShortPageWhenPaginationIsAbsent(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => [['id' => 1]]])]);

        $items = $goal->collect(fn (array $p) => $goal->videos->list($p), pageSize: 10);

        self::assertCount(1, $items);
        self::assertCount(1, $this->seen);
    }

    // ------------------------------------------------------------ websocket helpers

    public function testWebSocketUrlDerivesFromTheBaseUrl(): void
    {
        $goal = new GoalApi('k', httpHandler: static fn () => [200, [], '{}']);
        self::assertSame('wss://api.goal-api.com/v1/ws', $goal->webSocketUrl());
    }

    public function testMintConnectTokenReturnsTheToken(): void
    {
        $goal = $this->client([self::json(['success' => true, 'data' => ['token' => 'tok_123', 'expiresIn' => 60]])]);

        self::assertSame(['token' => 'tok_123', 'expiresIn' => 60], $goal->mintConnectToken());
        self::assertSame('POST', $this->seen[0]['method']);
        self::assertSame('https://api.goal-api.com/v1/ws/token', $this->seen[0]['url']);
    }

    // ------------------------------------------------------------ webhooks

    private const SECRET = 'whsec_test';

    private static function sign(string $body, int $timestamp): string
    {
        $digest = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        return "t={$timestamp},v1={$digest}";
    }

    public function testVerifyAcceptsACorrectSignature(): void
    {
        $body = json_encode(['event' => 'goal.scored', 'matchId' => 'm1'], JSON_THROW_ON_ERROR);
        $event = Webhook::verify($body, self::sign($body, time()), self::SECRET);

        self::assertSame(['event' => 'goal.scored', 'matchId' => 'm1'], $event);
    }

    public function testVerifyRejectsATamperedBody(): void
    {
        $signature = self::sign('{"homeScore":1}', time());

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessageMatches('/does not match/');
        Webhook::verify('{"homeScore":9}', $signature, self::SECRET);
    }

    public function testVerifyRejectsAReplayedTimestamp(): void
    {
        $body = json_encode(['event' => 'goal.scored'], JSON_THROW_ON_ERROR);
        $old = time() - 4000;

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessageMatches('/outside the 300s tolerance/');
        Webhook::verify($body, self::sign($body, $old), self::SECRET);
    }

    public function testVerifyAllowsDisablingTheToleranceCheck(): void
    {
        $body = json_encode(['event' => 'goal.scored'], JSON_THROW_ON_ERROR);
        $old = time() - 4000;

        $event = Webhook::verify($body, self::sign($body, $old), self::SECRET, tolerance: 0);
        self::assertSame('goal.scored', $event['event']);
    }

    public function testVerifyRejectsMalformedAndMissingHeaders(): void
    {
        foreach (['garbage', 't=abc,v1=zz', ''] as $header) {
            try {
                Webhook::verify('{}', $header === '' ? null : $header, self::SECRET);
                self::fail("expected a WebhookSignatureException for header '{$header}'");
            } catch (WebhookSignatureException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testVerifyRejectsAWrongSecret(): void
    {
        $body = json_encode(['a' => 1], JSON_THROW_ON_ERROR);

        $this->expectException(WebhookSignatureException::class);
        Webhook::verify($body, self::sign($body, time()), 'whsec_other');
    }

    public function testVerifyRejectsANonJsonBody(): void
    {
        $body = 'not json';

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        Webhook::verify($body, self::sign($body, time()), self::SECRET);
    }
}
