<?php

declare(strict_types=1);

namespace CaptchaNo\Models;

/** Response of GET /v1/balance. */
final class Balance
{
    public function __construct(
        /** Remaining prepaid credit as a decimal string, e.g. "4.2500". */
        public readonly string $balance,
        public readonly string $currency,
    ) {
    }

    public function toFloat(): float
    {
        return (float) $this->balance;
    }
}
