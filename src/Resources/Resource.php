<?php

declare(strict_types=1);

namespace GoalApi\Resources;

use GoalApi\Transport;

/**
 * Base for the endpoint groups, one subclass per group.
 *
 * Methods return the decoded envelope (`['success', 'data', 'pagination']`). Rows stay as
 * arrays rather than models -- they are provider-shaped and change.
 *
 * Accepted params and limit ceilings: ../ENDPOINTS.md
 */
abstract class Resource
{
    public function __construct(protected readonly Transport $t)
    {
    }

    protected function seg(mixed $value): string
    {
        return Transport::segment($value);
    }
}
