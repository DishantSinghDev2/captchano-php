<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The challenge type (DataDome) requires a proxy. */
final class ProxyRequiredException extends CaptchaNoException
{
    public const CODE = 'proxy_required';
}
