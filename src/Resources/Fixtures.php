<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Fixtures extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/fixtures', $params);
    }

    /**
     * Matches in play. Takes an optional `leagueId`.
     *
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function live(?array $params = null): array
    {
        return $this->t->get('/fixtures/live', $params);
    }

    /**
     * @param string                    $date   `YYYY-MM-DD`
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function byDate(string $date, ?array $params = null): array
    {
        return $this->t->get('/fixtures/date/' . $this->seg($date), $params);
    }

    /** @return array<string, mixed> */
    public function get(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId));
    }

    /** @return array<string, mixed> */
    public function events(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/events');
    }

    /** @return array<string, mixed> */
    public function lineups(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/lineups');
    }

    /**
     * Takes an optional `half`: `full`, `1half` or `2half`.
     *
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function statistics(string|int $fixtureId, ?array $params = null): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/statistics', $params);
    }

    /** @return array<string, mixed> */
    public function cards(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/cards');
    }

    /** @return array<string, mixed> */
    public function substitutions(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/substitutions');
    }

    /**
     * Accepts a matchApiId as well as a fixture id, as do the three below.
     *
     * @return array<string, mixed>
     */
    public function odds(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/odds');
    }

    /** @return array<string, mixed> */
    public function predictions(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/predictions');
    }

    /** @return array<string, mixed> */
    public function liveOdds(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/live-odds');
    }

    /** @return array<string, mixed> */
    public function commentary(string|int $fixtureId): array
    {
        return $this->t->get('/fixtures/' . $this->seg($fixtureId) . '/commentary');
    }
}
