# Backend

Laravel 13 / PHP 8.5 API and background processing for Canabicultarr. PostgreSQL with TimescaleDB stores application records and measurements; Redis-compatible queues run integrations, exports and notifications.

## Configuration and startup

Use the shared `.env` at the repository root. `backend/.env` is a local symlink created by the bootstrap script; do not create a separate configuration file.

From the repository root:

```sh
sh scripts/bootstrap.sh
docker compose up -d
docker compose exec app php artisan migrate --force
```

Follow the [installation guide](https://github.com/kinouzero/canabicultarr/wiki/installation) for SMTP/OIDC, the initial administrator and private file storage. Migrations are deliberate; startup never regenerates `APP_KEY` or migrates an existing database automatically.

## Code map

| Directory | Purpose |
| --- | --- |
| `app/Http` | Versioned endpoints, validation and resource serialization |
| `app/Services` | Application workflows and provider clients |
| `app/Policies` | Record access and permission checks |
| `app/Jobs`, `app/Console` | Queued work and maintenance commands |
| `database/migrations` | Schema and controlled data migrations |
| `lang`, `resources` | Server translations, email and report templates |
| `tests` | Domain, API, security and integration regression tests |
| `scripts` | Coverage tools and optional native Bluetooth relay |

## Verification

The production image omits test dependencies. Use the development image or a local PHP/Composer environment and an isolated test database, as described in [development](https://github.com/kinouzero/canabicultarr/wiki/local-development). Never run database-refresh tests against the live instance.

With development dependencies installed, from `backend`:

```sh
vendor/bin/pint --test
php vendor/bin/phpunit --filter AccountActivationTest
```

See [architecture](https://github.com/kinouzero/canabicultarr/wiki/architecture), [API conventions](https://github.com/kinouzero/canabicultarr/wiki/api) and [OpenAPI](https://raw.githubusercontent.com/wiki/kinouzero/canabicultarr/openapi.yaml) for the application contracts.
