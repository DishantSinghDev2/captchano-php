<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The challenge could not be solved. Not billed. */
final class UnsolvableException extends CaptchaNoException
{
    public const CODE = 'unsolvable';
}
