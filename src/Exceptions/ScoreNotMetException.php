<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The reCAPTCHA v3 token did not reach the requested minScore. Not billed. */
final class ScoreNotMetException extends CaptchaNoException
{
    public const CODE = 'score_not_met';
}
