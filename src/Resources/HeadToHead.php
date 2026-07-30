<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class HeadToHead extends Resource
{
    /** @return array<string, mixed> */
    public function get(string|int $team1Id, string|int $team2Id): array
    {
        return $this->t->get('/h2h/' . $this->seg($team1Id) . '/' . $this->seg($team2Id));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function direct(string|int $team1Id, string|int $team2Id, ?array $params = null): array
    {
        return $this->t->get('/h2h/' . $this->seg($team1Id) . '/' . $this->seg($team2Id) . '/direct', $params);
    }

    /** @return array<string, mixed> */
    public function stats(string|int $team1Id, string|int $team2Id): array
    {
        return $this->t->get('/h2h/' . $this->seg($team1Id) . '/' . $this->seg($team2Id) . '/stats');
    }
}
