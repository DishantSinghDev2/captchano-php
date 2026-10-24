<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** No response was received (DNS, reset, timeout, offline). */
final class ConnectionException extends CaptchaNoException
{
    public const CODE = 'connection_error';
}
