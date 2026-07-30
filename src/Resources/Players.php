<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Players extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/players', $params);
    }

    /**
     * @param string                    $query  2-100 characters
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function search(string $query, ?array $params = null): array
    {
        return $this->t->get('/players/search', array_merge($params ?? [], ['q' => $query]));
    }

    /**
     * 2-5 players.
     *
     * @param list<string|int>|string $ids
     *
     * @return array<string, mixed>
     */
    public function compare(array|string $ids): array
    {
        return $this->t->get('/players/compare', ['ids' => Transport::csv($ids)]);
    }

    /**
     * Ranks players by a stat. See GoalApi::PLAYER_STATS.
     *
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    public function top(string $stat, ?array $params = null): array
    {
        return $this->t->get('/players/top/' . $this->seg($stat), $params);
    }

    /** @return array<string, mixed> */
    public function get(string|int $playerId): array
    {
        return $this->t->get('/players/' . $this->seg($playerId));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function statistics(string|int $playerId, ?array $params = null): array
    {
        return $this->t->get('/players/' . $this->seg($playerId) . '/statistics', $params);
    }
}
