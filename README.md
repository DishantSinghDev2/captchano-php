# CaptchaNo PHP SDK — Captcha Solver API Client for PHP

[![Packagist](https://img.shields.io/packagist/v/captchano/captchano)](https://packagist.org/packages/captchano/captchano)
[![CI](https://github.com/DishantSinghDev2/captchano-php/actions/workflows/ci.yml/badge.svg)](https://github.com/DishantSinghDev2/captchano-php/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4)](composer.json)
[![license](https://img.shields.io/badge/license-MIT-green)](LICENSE)

The official **PHP SDK** for [CaptchaNo](https://captchano.com): solve **reCAPTCHA v2, v3 and Enterprise, Cloudflare Turnstile, DataDome and image captchas** from PHP. Typed models, one exception class per API error code, and **no dependencies beyond ext-curl and ext-json** — bring a PSR-18 client (Guzzle, Symfony HttpClient) if you prefer.

**Keywords:** captcha solver · recaptcha solver · turnstile solver · datadome · image captcha · captcha API · Symfony Panther · Guzzle · Laravel · web scraping

## Features

- PHP 8.1+, PSR-4, readonly typed models
- One call per solve: the client blocks server-side with `?wait=` and falls back to polling
- `catch (InvalidSitekeyException $e)` and a typed exception for every other API error code
- cURL out of the box, or any PSR-18 client through `Psr18Transport`
- You are billed only for successful solves; failed tasks are thrown as typed exceptions

## Installation

```bash
composer require captchano/captchano
```

Get an API key (starts with `cn-`) from the [dashboard](https://captchano.com/dashboard/keys) and export it:

```bash
export CAPTCHANO_API_KEY=cn-...
```

## Quick start

```php
<?php

require 'vendor/autoload.php';

$captchano = new CaptchaNo\Client(); // reads CAPTCHANO_API_KEY

$task = $captchano->recaptchaV2(
    'https://www.google.com/recaptcha/api2/demo',
    '6Le-wvkSAAAAAPBMRTvw0Q4Muexq9bi0DJwx_mJ-',
);

echo $task->token(), PHP_EOL;   // the g-recaptcha-response value
echo $task->cost, PHP_EOL;      // USD charged, as a decimal string
```

Every helper returns a `CaptchaNo\Models\Task` with `id`, `status`, `solution` (`token`, `cookie`, `text`, `userAgent`), `cost`, `billedFrom` and `solveTime`.

## Every challenge type

### reCAPTCHA v2 (checkbox, invisible, Enterprise)

```php
$task = $captchano->recaptchaV2(
    url: 'https://example.com/login',
    sitekey: '6Lc...',                        // data-sitekey, or k= in the iframe URL
    invisible: false,                         // true for invisible widgets
    enterprise: false,                        // true for Enterprise checkbox widgets
    proxy: 'http://user:pass@host:port',      // optional; the IP that will submit the token
    userAgent: 'Mozilla/5.0 ...',             // optional
);
$token = $task->token();
```

### reCAPTCHA v3

```php
$task = $captchano->recaptchaV3(
    url: 'https://example.com/checkout',
    sitekey: '6Lc...',
    action: 'submit',   // the action the page passes to grecaptcha.execute
    minScore: 0.7,
);
```

### Cloudflare Turnstile

```php
$task = $captchano->turnstile(
    url: 'https://example.com/signup',
    sitekey: '0x4AAAAAAA...',
    action: 'signup',   // optional
    cData: null,        // challenge pages only
    pageData: null,     // challenge pages only
);
```

### DataDome

```php
$task = $captchano->dataDome(
    url: 'https://example.com/',
    challengeUrl: 'https://geo.captcha-delivery.com/captcha/?initialCid=...', // the challenge iframe src
    proxy: 'http://user:pass@host:port',                                       // required
    userAgent: 'Mozilla/5.0 ...',
);
$cookie = $task->solution->cookie;       // set it, then
$ua = $task->solution->userAgent;        // replay this user agent through the same proxy
```

### Image captcha

```php
$task = $captchano->image(file_get_contents('captcha.png'));
echo $task->solution->text;
```

### Raw requests, non-blocking submit and polling

```php
// Any field the API accepts
$task = $captchano->solve(['type' => 'turnstile', 'url' => $url, 'sitekey' => $sitekey]);

// Fire and forget, collect later (solutions are kept for ten minutes)
$task = $captchano->submit(['type' => 'image', 'body' => base64_encode($png)]);
$task = $captchano->waitFor($task->id);   // polls GET /v1/task/{id}
$task = $captchano->getTask($task->id);   // one-shot

// Remaining credit
$balance = $captchano->balance();
echo "{$balance->balance} {$balance->currency}";   // "4.2500 USD"
```

## Injecting the token

### Symfony Panther (real Chrome)

Read the sitekey from the page, solve, write the token into the hidden response field and submit. Passing the browser's own user agent keeps the token bound to the session that submits it.

```php
use CaptchaNo\Client as CaptchaNo;
use Symfony\Component\Panther\Client as Panther;

$pageUrl = 'https://www.google.com/recaptcha/api2/demo';

$browser = Panther::createChromeClient();
$crawler = $browser->request('GET', $pageUrl);
$sitekey = $crawler->filter('.g-recaptcha')->attr('data-sitekey');
$ua = $browser->executeScript('return navigator.userAgent;');

$task = (new CaptchaNo())->recaptchaV2($pageUrl, $sitekey, userAgent: $ua);

$browser->executeScript(
    'document.getElementById("g-recaptcha-response").value = arguments[0];'
    . 'document.getElementById("recaptcha-demo-submit").click();',
    [$task->token()],
);
$browser->waitFor('.recaptcha-success');
echo "form accepted\n";
```

For Turnstile, write the token into `input[name="cf-turnstile-response"]` instead. If the site uses a JavaScript callback (`data-callback`), call it with the token after setting the field.

### Plain Guzzle (no browser)

Most forms only check the `g-recaptcha-response` (or `cf-turnstile-response`) field server-side, so you can post it directly. Send the same cookies, user agent and proxy you fetched the page with.

```php
use CaptchaNo\Client as CaptchaNo;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Cookie\CookieJar;

$pageUrl = 'https://www.google.com/recaptcha/api2/demo';
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

$http = new Guzzle(['cookies' => new CookieJar(), 'headers' => ['User-Agent' => $ua]]);
$html = (string) $http->get($pageUrl)->getBody();
preg_match('/data-sitekey="([^"]+)"/', $html, $m);

$task = (new CaptchaNo())->recaptchaV2($pageUrl, $m[1], userAgent: $ua);

$response = $http->post($pageUrl, ['form_params' => ['g-recaptcha-response' => $task->token()]]);
echo str_contains((string) $response->getBody(), 'Verification Success') ? "accepted\n" : "rejected\n";
```

## Error handling

Every API error code has its own exception class in `CaptchaNo\Exceptions`, all extending `CaptchaNoException`:

| Exception | Code | Meaning |
|---|---|---|
| `InvalidApiKeyException` | `invalid_api_key` | Key missing, revoked or wrong |
| `InsufficientBalanceException` | `insufficient_balance` | Balance cannot cover one solve |
| `UnsupportedTypeException` | `unsupported_type` | Unknown type or a required field is missing |
| `InvalidSitekeyException` | `invalid_sitekey` | Sitekey missing, of the wrong family, or rejected for the page |
| `ProxyRequiredException` | `proxy_required` | DataDome without a proxy |
| `ProxyUnreachableException` | `proxy_unreachable` | Proxy malformed or refused the connection |
| `ScoreNotMetException` | `score_not_met` | reCAPTCHA v3 score below `minScore` |
| `UnsolvableException` | `unsolvable` | The challenge could not be solved (not billed) |
| `CapacityUnavailableException` | `capacity_unavailable` | Temporarily unable to accept solves; retry with backoff |
| `RateLimitedException` | `rate_limited` | Too many requests (`$e->retryAfter`) |
| `TaskNotFoundException` | `task_not_found` | No task with that id on your account |
| `UpstreamErrorException` | `upstream_error` | The solve could not be completed (not billed) |
| `ConnectionException` | `connection_error` | No response (DNS, reset, timeout, offline) |
| `TimeoutException` | `timeout` | Still solving after `maxWait`; resume with `waitFor($e->taskId)` |

```php
use CaptchaNo\Exceptions\{CaptchaNoException, InsufficientBalanceException, InvalidSitekeyException, TimeoutException};

try {
    $task = $captchano->turnstile($url, $sitekey);
} catch (InsufficientBalanceException) {
    // top up at https://captchano.com/dashboard/topup
} catch (InvalidSitekeyException $e) {
    // re-read the sitekey from the page
} catch (TimeoutException $e) {
    $task = $captchano->waitFor($e->taskId);
} catch (CaptchaNoException $e) {
    error_log("{$e->errorCode} (HTTP {$e->status}, task {$e->taskId}): {$e->apiMessage}");
    if ($e->isRetryable()) { /* retry once */ }
}
```

Every exception exposes `errorCode`, `apiMessage`, `status`, `taskId`, `task` (for failed tasks), `body` and `retryAfter`.

## Configuration

```php
$captchano = new CaptchaNo\Client(
    apiKey: getenv('CAPTCHANO_API_KEY'),       // default: getenv('CAPTCHANO_API_KEY')
    baseUrl: 'https://api.captchano.com',
    timeout: 30.0,          // per request, seconds; blocking solves add the wait
    wait: 60,               // server-side ?wait= (max 120, 0 = poll only)
    pollInterval: 5.0,      // GET /v1/task interval, seconds
    maxWait: 180.0,         // cap per solve() / waitFor(), seconds
    maxRetries: 2,          // retries for HTTP 429
);
```

### Using Guzzle, Symfony HttpClient or any PSR-18 client

```php
use CaptchaNo\Http\Psr18Transport;

$http = new GuzzleHttp\Client(['timeout' => 150]);   // above the longest wait
$factory = new GuzzleHttp\Psr7\HttpFactory();

$captchano = new CaptchaNo\Client(transport: new Psr18Transport($http, $factory, $factory));
```

Or implement `CaptchaNo\Http\Transport` (one `send()` method) for anything else.

## API overview

| Capability | Call |
|---|---|
| Solve and block until ready | `$captchano->solve($request)` |
| Submit without blocking | `$captchano->submit($request)` |
| Fetch a task once | `$captchano->getTask($id)` |
| Poll until ready or failed | `$captchano->waitFor($id)` |
| Remaining credit | `$captchano->balance()` |
| reCAPTCHA v2 / Enterprise | `$captchano->recaptchaV2($url, $sitekey, ...)` |
| reCAPTCHA v3 | `$captchano->recaptchaV3($url, $sitekey, ...)` |
| Cloudflare Turnstile | `$captchano->turnstile($url, $sitekey, ...)` |
| DataDome | `$captchano->dataDome($url, $challengeUrl, $proxy)` |
| Image captcha | `$captchano->image($bytes)` |

Full endpoint reference: <https://captchano.com/captcha-solver-api> · Per-type prices: <https://captchano.com/pricing>

## FAQ

**How do I get an API key?**
Create an account at [captchano.com](https://captchano.com), then open **Dashboard → API keys**. Keys start with `cn-`. Keep them in `CAPTCHANO_API_KEY` or a secret manager, never in source code.

**Am I charged for failed solves?**
No. You pay only for solves that return a usable solution. See [pricing](https://captchano.com/pricing) for per-type prices.

**Why was my token rejected by the site?**
The most common cause is submitting from a different IP than the one the token was minted for. Pass the proxy your scraper uses as `proxy`, and replay `solution->userAgent` when the solution includes one. For reCAPTCHA v3, send the exact `action` the page uses.

**Does it work with Laravel?**
Yes. Bind `CaptchaNo\Client` as a singleton in a service provider and read the key from `config('services.captchano.key')`.

**Is it free to use the SDK?**
Yes — the SDK is MIT-licensed. You pay only for successful solves.

## Development

```bash
composer install && vendor/bin/phpunit
```

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: [SECURITY.md](SECURITY.md).

## Links

- Website: <https://captchano.com>
- API reference: <https://captchano.com/captcha-solver-api>
- Pricing: <https://captchano.com/pricing>
- Support: <support@captchano.com>

## License

[MIT](LICENSE) © DishIs Technologies LLC
