<?php

declare(strict_types=1);

namespace GoalApi\Tests;

use GoalApi\Exceptions\AuthenticationException;
use GoalApi\Exceptions\NotFoundException;
use GoalApi\Exceptions\ValidationException;
use GoalApi\GoalApi;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real API. Skipped unless GOAL_API_KEY is set.
 *
 *   GOAL_API_KEY=... vendor/bin/phpunit
 *
 * Endpoint-by-endpoint coverage lives in ../tools/sweep.py. This checks the parts
 * that are the SDK's job: auth, param encoding, envelope handling, pagination across real
 * pages, error mapping and the quota snapshot.
 */
#[Group('live')]
final class LiveApiTest extends TestCase
{
    private static ?GoalApi $goal = null;

    protected function setUp(): void
    {
        $apiKey = getenv('GOAL_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            self::markTestSkipped('GOAL_API_KEY is not set');
        }

        self::$goal ??= new GoalApi($apiKey, timeout: 30.0);
    }

    private function goal(): GoalApi
    {
        return self::$goal ?? self::fail('client was not initialised');
    }

    public function testStatusReturnsTheBareBody(): void
    {
        $status = $this->goal()->status->get();

        self::assertIsString($status['status']);
        self::assertIsArray($status['components']);
        self::assertArrayNotHasKey('data', $status, '/public/* must not be wrapped');
    }

    public function testCoverageLeaguesPaginatesWithPage(): void
    {
        $page = $this->goal()->status->coverageLeagues(['page' => 1, 'limit' => 5]);

        self::assertIsArray($page['leagues']);
        self::assertLessThanOrEqual(5, \count($page['leagues']));
        self::assertIsInt($page['total']);
        self::assertIsInt($page['pages']);
    }

    public function testLeaguesListReturnsTheEnvelope(): void
    {
        $page = $this->goal()->leagues->list(['isActive' => true, 'limit' => 3]);

        self::assertTrue($page['success']);
        self::assertLessThanOrEqual(3, \count($page['data']));
        self::assertIsInt($page['pagination']['total']);
        self::assertContains($page['source'], ['cache', 'database']);
    }

    public function testQuotaSnapshotIsPopulated(): void
    {
        $this->goal()->leagues->list(['limit' => 1]);
        $quota = $this->goal()->rateLimit();

        self::assertIsInt($quota->limit);
        self::assertLessThanOrEqual($quota->limit, $quota->remaining);
        self::assertContains($quota->type, ['DAILY', 'MONTHLY']);
    }

    public function testFixturesLive(): void
    {
        $page = $this->goal()->fixtures->live();

        self::assertTrue($page['success']);
        self::assertIsArray($page['data']);
    }

    public function testNestedLeagueEndpoints(): void
    {
        $leagues = $this->goal()->leagues->list(['isActive' => true, 'limit' => 1])['data'];
        self::assertNotEmpty($leagues, 'expected at least one active league');
        $leagueId = $leagues[0]['id'];

        self::assertTrue($this->goal()->leagues->teams($leagueId, ['limit' => 5])['success']);
        self::assertTrue($this->goal()->leagues->fixtures($leagueId, ['limit' => 5])['success']);
    }

    public function testPaginateWalksRealPages(): void
    {
        $seen = [];
        foreach ($this->goal()->paginate(
            fn (array $p) => $this->goal()->countries->list($p),
            pageSize: 20,
            maxItems: 45,
        ) as $country) {
            $seen[] = $country['id'];
        }

        self::assertGreaterThan(20, \count($seen), 'expected pagination to cross a page boundary');
        self::assertCount(\count($seen), array_unique($seen), 'pagination returned duplicates');
    }

    public function testPlayersSearch(): void
    {
        $page = $this->goal()->players->search('silva', ['limit' => 3]);

        self::assertTrue($page['success']);
        self::assertLessThanOrEqual(3, \count($page['data']));
    }

    public function testUnknownIdThrowsNotFound(): void
    {
        try {
            $this->goal()->fixtures->get('definitely-not-a-real-id');
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $error) {
            self::assertSame(404, $error->status);
            self::assertSame('FIXTURE_NOT_FOUND', $error->errorCode);
            // The service puts the text in `error`; the SDK normalises it to the message.
            self::assertNotSame('', $error->getMessage());
        }
    }

    public function testBadEnumThrowsValidationErrorWithDetails(): void
    {
        try {
            $this->goal()->players->top('not-a-real-stat');
            self::fail('expected a ValidationException');
        } catch (ValidationException $error) {
            self::assertSame(400, $error->status);
            self::assertSame('VALIDATION_ERROR', $error->errorCode);
            self::assertIsArray($error->details, 'expected express-validator details');
        }
    }

    public function testBadKeyThrowsAuthenticationError(): void
    {
        $bad = new GoalApi('gapi_not_a_real_key', maxRetries: 0);

        $this->expectException(AuthenticationException::class);
        $bad->leagues->list(['limit' => 1]);
    }

    public function testWebSocketUrlAndTokenMinting(): void
    {
        self::assertSame('wss://api.goal-api.com/v1/ws', $this->goal()->webSocketUrl());

        $token = $this->goal()->mintConnectToken();
        self::assertNotSame('', $token['token']);
        self::assertGreaterThan(0, $token['expiresIn']);
    }
}
