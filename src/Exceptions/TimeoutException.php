<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** The task was still solving when maxWait ran out. Resume with waitFor($e->taskId). */
final class TimeoutException extends CaptchaNoException
{
    public const CODE = 'timeout';
}
