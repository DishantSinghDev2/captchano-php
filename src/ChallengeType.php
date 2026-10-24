<?php

declare(strict_types=1);

namespace CaptchaNo;

/** Challenge types accepted by POST /v1/solve. */
final class ChallengeType
{
    public const RECAPTCHA_V2 = 'recaptcha_v2';
    public const RECAPTCHA_V3 = 'recaptcha_v3';
    public const RECAPTCHA_ENTERPRISE = 'recaptcha_enterprise';
    public const TURNSTILE = 'turnstile';
    public const DATADOME = 'datadome';
    public const IMAGE = 'image';

    public const ALL = [
        self::RECAPTCHA_V2,
        self::RECAPTCHA_V3,
        self::RECAPTCHA_ENTERPRISE,
        self::TURNSTILE,
        self::DATADOME,
        self::IMAGE,
    ];

    private function __construct()
    {
    }
}
