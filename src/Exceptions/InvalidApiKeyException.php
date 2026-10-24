<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The API key is missing, revoked or wrong (HTTP 401). */
final class InvalidApiKeyException extends CaptchaNoException
{
    public const CODE = 'invalid_api_key';
}
