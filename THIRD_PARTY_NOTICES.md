# Third-party notices

This package is MIT licensed and has **no runtime dependencies**. `composer require
goal-api/sdk` adds exactly one package to your `vendor/`.

It needs two PHP extensions, both bundled with essentially every PHP build and declared in
`composer.json`:

| Requirement | Licence |
|---|---|
| `ext-curl` | The PHP Licence (part of PHP itself) |
| `ext-json` | The PHP Licence (part of PHP itself) |

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
