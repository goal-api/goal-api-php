# Third-party notices

This package is MIT licensed and has **no runtime dependencies**. `composer require
goal-api/sdk` adds exactly one package to your `vendor/`.

It needs three PHP extensions, all declared in `composer.json` and all part of PHP itself:

| Requirement | Licence | Used for |
|---|---|---|
| `ext-curl` | The PHP Licence | The HTTP transport |
| `ext-json` | The PHP Licence | Request and response bodies |
| `ext-hash` | The PHP Licence | `hash_hmac` for webhook signatures |

`json` and `hash` cannot be disabled on PHP 7.4 or later, so in practice only `curl` is
worth checking on an unusual build.

Using curl directly, rather than Guzzle or a PSR-18 client, is what keeps the dependency
count at zero. That matters for installing into a project that already pins a conflicting
HTTP client. If you would rather route through your own stack, pass an `$httpHandler`;
see the README.

## Development only

Not installed for consumers (`composer install --no-dev`) and not part of any release:
`phpunit/phpunit` (BSD-3-Clause) and its own transitive dev tree.

## Checking this yourself

```bash
composer show --no-dev --tree
composer licenses
```
