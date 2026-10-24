<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The solve could not be completed. Not billed. */
final class UpstreamErrorException extends CaptchaNoException
{
    public const CODE = 'upstream_error';
}
