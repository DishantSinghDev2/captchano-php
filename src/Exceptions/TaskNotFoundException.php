<?php

declare(strict_types=1);

namespace CaptchaNo\Exceptions;

/** No task with that id on this account, or it expired (ten minutes). */
final class TaskNotFoundException extends CaptchaNoException
{
    public const CODE = 'task_not_found';
}
