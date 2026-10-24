<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The sitekey is missing, of the wrong family, or was rejected for the page. */
final class InvalidSitekeyException extends CaptchaNoException
{
    public const CODE = 'invalid_sitekey';
}
