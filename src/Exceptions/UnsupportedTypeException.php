<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** Unknown challenge type, or a required field such as url is missing. */
final class UnsupportedTypeException extends CaptchaNoException
{
    public const CODE = 'unsupported_type';
}
