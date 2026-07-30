<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Coaches extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/coaches', $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function search(string $query, ?array $params = null): array
    {
        return $this->t->get('/coaches/search', array_merge($params ?? [], ['q' => $query]));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function byCountry(string $country, ?array $params = null): array
    {
        return $this->t->get('/coaches/country/' . $this->seg($country), $params);
    }

    /** @return array<string, mixed> */
    public function byTeam(string|int $teamId): array
    {
        return $this->t->get('/coaches/team/' . $this->seg($teamId));
    }

    /** @return array<string, mixed> */
    public function get(string|int $coachId): array
    {
        return $this->t->get('/coaches/' . $this->seg($coachId));
    }
}
