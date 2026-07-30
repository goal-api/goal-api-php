<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Standings extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function get(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/standings/' . $this->seg($leagueId), $params);
    }

    /** @return array<string, mixed> */
    public function team(string|int $leagueId, string|int $teamId): array
    {
        return $this->t->get('/standings/' . $this->seg($leagueId) . '/team/' . $this->seg($teamId));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function home(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/standings/' . $this->seg($leagueId) . '/home', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function away(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/standings/' . $this->seg($leagueId) . '/away', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function form(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/standings/' . $this->seg($leagueId) . '/form', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function zones(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/standings/' . $this->seg($leagueId) . '/zones', $params);
    }
}
