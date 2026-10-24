<?php

// Solves Google's public reCAPTCHA v2 demo widget and prints the token.
// Run with: CAPTCHANO_API_KEY=cn-... php examples/basic.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CaptchaNo\Client;
use CaptchaNo\Exceptions\CaptchaNoException;
use CaptchaNo\Exceptions\InsufficientBalanceException;

$captchano = new Client(); // reads CAPTCHANO_API_KEY

try {
    $balance = $captchano->balance();
    echo "balance: {$balance->balance} {$balance->currency}\n";

    $task = $captchano->recaptchaV2(
        'https://www.google.com/recaptcha/api2/demo',
        '6Le-wvkSAAAAAPBMRTvw0Q4Muexq9bi0DJwx_mJ-',
    );
    printf("task %s ready, cost $%s\ntoken: %.40s...\n", $task->id, $task->cost, $task->token());
} catch (InsufficientBalanceException) {
    fwrite(STDERR, "Top up at https://captchano.com/dashboard/topup\n");
    exit(1);
} catch (CaptchaNoException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
