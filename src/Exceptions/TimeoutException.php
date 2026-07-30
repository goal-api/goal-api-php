<?php

declare(strict_types=1);

namespace GoalApi\Exceptions;

/**
 * The request exceeded the configured timeout.
 */
final class TimeoutException extends ConnectionException
{
}
