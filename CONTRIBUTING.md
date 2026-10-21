# Contributing

Modular is published for its maintainer's own use. Issues and pull requests may go unanswered, and changes are unlikely to be merged unless they fit the design in [`DESIGN.md`](DESIGN.md). Forks are welcome.

To run the checks the project's CI runs, install with `composer install`, then run `php -d memory_limit=1G vendor/bin/pest < /dev/null` (stdin closed, never `--parallel`), `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G`. The `vue` group (`vendor/bin/pest --group=vue < /dev/null`) needs Node and `npm ci`.

A generator change updates the stub, its conformance test and the documentation together; every generator has an entry in `generatorCases()` in the test suite.
