# Canabicultarr

A self-hosted application for tracking plants, their environment and the supplies used to care for them.

Follow each plant from sowing to archival, connect environmental sensors and keep care actions, photos and stock movements in one place. Use it manually or connect Home Assistant, Homebridge, MQTT and HTTP sources, or read nearby standard BLE sensors through Linux BlueZ or a native host relay.

**[User handbook](https://github.com/kinouzero/canabicultarr/wiki/Home)** · **[Installation](https://github.com/kinouzero/canabicultarr/wiki/installation)** · **[Administration](https://github.com/kinouzero/canabicultarr/wiki/settings)** · **[Development](https://github.com/kinouzero/canabicultarr/wiki/local-development)**

> **0.1.0 — first public release.** Build from source or use a published container image. Back up your data before updating.

![Canabicultarr dashboard with fictional demonstration data](https://raw.githubusercontent.com/wiki/kinouzero/canabicultarr/images/dashboard.png)

## Features

| Area | Capabilities |
| --- | --- |
| Plant tracking | Individual records, strain catalog, configurable lifecycle stages and branches, care history and batch actions |
| Locations | Multiple sites, rooms and zones, with scoped access and hardware assignments |
| Monitoring | Sensor readings, compatible-channel comparisons, charts, freshness indicators and alerts |
| Integrations | Home Assistant and Homebridge imports, MQTT subscriptions, authenticated HTTP ingestion and server-side standard BLE readings |
| Device controls | Supported pump, light, socket and fan bindings, manual controls and fixed schedules |
| Inventory | Seeds, fertilizer, substrate and pots, independent storage locations and recorded consumption |
| Files | Private folders, previews, attachments and photo galleries with strain-to-plant inheritance |
| Administration | Local accounts, verified OIDC, access profiles, individual permission exceptions and notification delivery |
| Interface | English/French, responsive cards and tables, light/dark themes and configurable colors |
| Exports | On-demand CSV/PDF exports from measurement history and the culture overview |

### Screenshots

| Plant record | Measurement history |
| --- | --- |
| ![Plant details with care actions and history](https://raw.githubusercontent.com/wiki/kinouzero/canabicultarr/images/plant-record.png) | ![Measurement history with statistics and a trend chart](https://raw.githubusercontent.com/wiki/kinouzero/canabicultarr/images/measurement-history.png) |

All screenshots use fictional demonstration data. Available actions depend on permissions, lifecycle settings and connected services.

## Quick start

You need Docker with the Compose plugin, either SMTP for local account activation or an OIDC provider that supplies verified identities.

Run these commands from the checkout for the version you want to install. Compose pulls the exact version from GHCR by default.

```sh
cp .env.example .env
# Set DB_PASSWORD, the public APP_URL and frontend settings in this private file.
sh scripts/bootstrap.sh
docker compose up -d
```

The container waits for the database and applies pending migrations before starting its services. Set `APP_AUTO_MIGRATE=false` only when migrations are managed separately.

Open `http://localhost:8420` unless you changed the public port. Configure SMTP for local account activation, or configure verified OIDC, then issue the first-administrator setup token:

```sh
docker compose exec app php artisan app:setup-token
```

Follow the [installation guide](https://github.com/kinouzero/canabicultarr/wiki/installation) before exposing an instance. Keep the application key and private files out of Git.

## Documentation

The **[illustrated handbook](https://github.com/kinouzero/canabicultarr/wiki/Home)** covers the interface, daily workflows, administration and troubleshooting.

- [Plants and strains](https://github.com/kinouzero/canabicultarr/wiki/plants), [lifecycle](https://github.com/kinouzero/canabicultarr/wiki/lifecycle) and [dashboard](https://github.com/kinouzero/canabicultarr/wiki/application-navigation)
- [Hardware and measurements](https://github.com/kinouzero/canabicultarr/wiki/measurements), [integrations](https://github.com/kinouzero/canabicultarr/wiki/integrations) and [alerts](https://github.com/kinouzero/canabicultarr/wiki/notifications)
- [Inventory](https://github.com/kinouzero/canabicultarr/wiki/inventory), [files and photos](https://github.com/kinouzero/canabicultarr/wiki/documents) and [accounts](https://github.com/kinouzero/canabicultarr/wiki/accounts)
- [Settings](https://github.com/kinouzero/canabicultarr/wiki/settings), [operations and backups](https://github.com/kinouzero/canabicultarr/wiki/deployment)
- [Development](https://github.com/kinouzero/canabicultarr/wiki/local-development), [architecture](https://github.com/kinouzero/canabicultarr/wiki/architecture), [API](https://github.com/kinouzero/canabicultarr/wiki/api) and [OpenAPI](https://raw.githubusercontent.com/wiki/kinouzero/canabicultarr/openapi.yaml)

## Configuration and data

- The [environment-variable reference](https://github.com/kinouzero/canabicultarr/wiki/installation#required-and-optional-variables) distinguishes required settings, optional defaults and feature-specific credentials. `.env.example` annotates each variable.
- Configure optional connections in the administrator UI or through environment variables. Environment-provided values lock the corresponding UI fields.
- Use private local storage or an S3-compatible destination for uploads. Changing the default destination does not migrate existing files.
- Back up PostgreSQL, uploaded files and the original application key together. See the [operations guide](https://github.com/kinouzero/canabicultarr/wiki/deployment).
- Protected data, edits and device commands require a server connection. PWA support provides a basic connection fallback, not full offline synchronization.

## Architecture

Laravel 13 / PHP 8.5, Vue 3 / TypeScript / Naive UI, PostgreSQL 18 with TimescaleDB, and Redis. A single supervised application container runs the web interface, API and background workers. Optional RustFS supplies S3-compatible storage.

## Development

Clone with `git clone --recurse-submodules https://github.com/kinouzero/canabicultarr.git` so the documentation wiki is available in `docs/`. For an existing checkout, run `git submodule update --init --recursive`.

For live source updates and development dependencies, prepare the local development image and start with the development override:

```sh
sh scripts/bootstrap.sh --dev
docker compose -f compose.yaml -f compose.dev.yaml up --build --watch
```

See the development guide for focused test commands and the configured CI workflow. Never run database-refresh tests against an instance containing real data.

## Dependencies and licensing

Naive UI is MIT-licensed and requires no account, license key or annual renewal. No UI-library secret is needed in GitHub Actions or Docker builds. Keep application credentials and populated environment files out of Git. Third-party dependencies and bundled icon assets retain their respective licenses; notices are included under [frontend/public/licenses](frontend/public/licenses).
