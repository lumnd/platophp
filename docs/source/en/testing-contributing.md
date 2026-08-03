# Testing and Contributing

## Public maintenance guide

[`CONTRIBUTING.md`](https://github.com/lumnd/platophp/blob/main/CONTRIBUTING.md) is the complete,
tracked maintenance contract. It covers framework boundaries, coding standards, initialisation,
process resources, tests, documentation, and compatibility. A contributor must not depend on local
AI instructions or an unpublished task list.

## Verification environment

All PHP verification runs inside the Docker development container. Host PHP results are not used for
acceptance because installed extensions, service names, and process permissions can differ.

```bash
docker compose exec -T -e REDIS_HOST=redis6 php82 sh -lc \
  'cd /data/web/platophp && composer test'

docker compose exec -T php82 sh -lc \
  'cd /data/web/platophp && composer check:architecture && composer style && composer analyse'
```

The main commands are:

| Command | Purpose |
| --- | --- |
| `composer test` | Unit and feature tests |
| `composer test:unit` | No subprocesses or external services |
| `composer test:feature` | HTTP, CLI, fork, and external-service integration |
| `composer check:architecture` | Layout, initialisation, resources, resets, and public API |
| `composer analyse` | PHPStan level 5 without a baseline |
| `composer style` | PHP_CodeSniffer; zero errors required |
| `composer style:fix` | Automatic style fixes |

Unavailable Redis, MySQL, Memcached, Kafka, or other optional services must be reported. Tests and
assertions must not be weakened to hide an environment failure.

## Coding standard

`phpcs.xml` is the executable authority. PlatoPHP follows PSR-12 with these explicit conventions:

| Area | PlatoPHP convention |
| --- | --- |
| Classes | Lowercase snake_case |
| Methods | Lowercase snake_case |
| Private members | Leading underscores are allowed |
| Braces | Allman style |
| Control structures | A space inside parentheses: `if ( $ready )` |

The PSR-3 and PSR-16 adapters retain interface-defined camelCase method names. Namespaces,
directories, filenames, and class names map exactly. Source comments, docblocks, test descriptions,
configuration comments, workflow comments, TODOs, and commit messages are English.

## Public API changes

The architecture check compares all public and protected symbols with
`tests/tools/api-snapshot.txt`. For an intentional change, run:

```bash
composer check:architecture -- --update-api
```

Commit the updated snapshot with the implementation and document the behaviour and reason in
`CHANGELOG.md`. Update both documentation languages when a public contract changes.

## Documentation build

Build documentation in the Docker PHP container after installing development dependencies:

```bash
php docs/build.php
```

The builder verifies one-to-one Chinese and English pages, sources, language switches, assets, and
local links. Do not edit generated HTML or manifest hashes manually.

Read [`SECURITY.md`](https://github.com/lumnd/platophp/blob/main/SECURITY.md) before reporting a
vulnerability; security reports use the private channel described there.
