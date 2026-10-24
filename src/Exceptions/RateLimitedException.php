<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** Too many requests (HTTP 429). See $retryAfter. */
final class RateLimitedException extends CaptchaNoException
{
    public const CODE = 'rate_limited';
}
