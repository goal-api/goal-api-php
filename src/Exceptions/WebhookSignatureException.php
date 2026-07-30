<?php

declare(strict_types=1);

namespace GoalApi\Exceptions;

/**
 * A webhook signature that was missing, malformed, wrong, or too old.
 */
final class WebhookSignatureException extends GoalApiException
{
}
