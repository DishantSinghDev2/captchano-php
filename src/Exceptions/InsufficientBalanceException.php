<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The balance cannot cover one solve (HTTP 402). Top up at https://captchano.com/dashboard/topup. */
final class InsufficientBalanceException extends CaptchaNoException
{
    public const CODE = 'insufficient_balance';
}
