<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Countries extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/countries', $params);
    }

    /** @return array<string, mixed> */
    public function get(string|int $countryId): array
    {
        return $this->t->get('/countries/' . $this->seg($countryId));
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function leagues(string|int $countryId, ?array $params = null): array
    {
        return $this->t->get('/countries/' . $this->seg($countryId) . '/leagues', $params);
    }
}
