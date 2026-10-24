<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** Solving capacity is temporarily unavailable. Retry with backoff. */
final class CapacityUnavailableException extends CaptchaNoException
{
    public const CODE = 'capacity_unavailable';
}
