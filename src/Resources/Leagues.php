<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Leagues extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/leagues', $params);
    }

    /** @return array<string, mixed> */
    public function get(string|int $leagueId): array
    {
        return $this->t->get('/leagues/' . $this->seg($leagueId));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function teams(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/leagues/' . $this->seg($leagueId) . '/teams', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function standings(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/leagues/' . $this->seg($leagueId) . '/standings', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function fixtures(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/leagues/' . $this->seg($leagueId) . '/fixtures', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function topScorers(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/leagues/' . $this->seg($leagueId) . '/top-scorers', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function results(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/leagues/' . $this->seg($leagueId) . '/results', $params);
    }
}
