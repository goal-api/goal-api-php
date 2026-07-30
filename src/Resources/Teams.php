<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Teams extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/teams', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function get(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/teams/' . $this->seg($teamId), $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function players(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/teams/' . $this->seg($teamId) . '/players', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function fixtures(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/teams/' . $this->seg($teamId) . '/fixtures', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function results(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/teams/' . $this->seg($teamId) . '/results', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function statistics(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/teams/' . $this->seg($teamId) . '/statistics', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function upcoming(string|int $teamId, ?array $params = null): array
    {
        return $this->t->get('/teams/' . $this->seg($teamId) . '/upcoming', $params);
    }
}
