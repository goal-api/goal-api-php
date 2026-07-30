<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

final class Predictions extends Resource
{
    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    public function list(?array $params = null): array
    {
        return $this->t->get('/predictions', $params);
    }
}
