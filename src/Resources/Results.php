<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Results extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/results', $params);
    }

    /** @return array<string, mixed> */
    public function today(): array
    {
        return $this->t->get('/results/today');
    }

    /** @return array<string, mixed> */
    public function yesterday(): array
    {
        return $this->t->get('/results/yesterday');
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function stats(?array $params = null): array
    {
        return $this->t->get('/results/stats', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function highScoring(?array $params = null): array
    {
        return $this->t->get('/results/high-scoring', $params);
    }

    /** @return array<string, mixed> */
    public function byDate(string $date): array
    {
        return $this->t->get('/results/date/' . $this->seg($date));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byLeague(string|int $leagueId, ?array $params = null): array
    {
        return $this->t->get('/results/league/' . $this->seg($leagueId), $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byTeam(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/results/team/' . $this->seg($teamId), $params);
    }
}
