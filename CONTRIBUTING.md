# Contributing to captchano-php

Thanks for helping improve the CaptchaNo SDK! Bug reports, docs fixes and pull requests are all welcome.

1. **Open an issue first** for anything bigger than a typo, so we can agree on the approach.
2. Fork, create a branch, and keep each pull request focused on one change.
3. Add or update tests. Tests run against the in-memory `FakeTransport`, never the live API,
   and must not need an API key.
4. Run `composer install` and `vendor/bin/phpunit` before pushing.

The API itself is documented at <https://captchano.com/captcha-solver-api>.
Questions? <support@captchano.com>.
