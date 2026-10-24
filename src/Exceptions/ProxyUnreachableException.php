<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The proxy string is malformed or the proxy refused the connection. */
final class ProxyUnreachableException extends CaptchaNoException
{
    public const CODE = 'proxy_unreachable';
}
