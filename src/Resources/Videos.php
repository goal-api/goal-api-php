<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Videos extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/videos', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function recent(?array $params = null): array
    {
        return $this->t->get('/videos/recent', $params);
    }

    /** @return array<string, mixed> */
    public function byMatch(string|int $matchId): array
    {
        return $this->t->get('/videos/match/' . $this->seg($matchId));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byLeague(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/videos/league/' . $this->seg($leagueId), $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byDate(string $date, ?array $params = null): array
    {
        return $this->t->get('/videos/date/' . $this->seg($date), $params);
    }
}
