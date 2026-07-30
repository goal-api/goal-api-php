<?php

declare(strict_types=1);

namespace GoalApi\Exceptions;

/**
 * No HTTP response at all: DNS, TLS, socket.
 */
class ConnectionException extends GoalApiException
{
}
