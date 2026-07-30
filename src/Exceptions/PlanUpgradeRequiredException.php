<?php

declare(strict_types=1);

namespace GoalApi\Exceptions;

/**
 * 402: endpoint not included in the current plan.
 */
final class PlanUpgradeRequiredException extends GoalApiException
{
}
