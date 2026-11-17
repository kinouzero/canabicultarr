# Infrastructure

Canabicultarr runs one application container with its web server, PHP, queue workers and scheduler. PostgreSQL and Redis-compatible services are external dependencies; local RustFS storage is optional.

| Path | Purpose |
| --- | --- |
| `app/Dockerfile` | Production runtime and development build targets |
| `app/*.conf` | Nginx, request security and process supervision |
| `app/*.sh` | Entrypoint, health and MQTT worker supervision |
| `../scripts/test-environment.py` | Generate a temporary, isolated acceptance stack from the deployment files |

## Compose files

From the repository root:

```sh
# Production, using the versioned GHCR image and settings from .env
docker compose up -d

# Build locally with development dependencies, Vite and source watching
sh scripts/bootstrap.sh --dev
docker compose -f compose.yaml -f compose.dev.yaml up --build --watch
```

Only `compose.yaml` and `compose.dev.yaml` are maintained. Optional Linux BlueZ socket access is documented as a commented bind mount in `compose.yaml`; Bluetooth mode and relay settings come from `.env` or the UI. Development inherits those options. No privileged Bluetooth container is added.

Acceptance tests generate a temporary JSON Compose configuration with fictional settings and dedicated app/Redis volumes. The generator reads `.env.example`, never the private deployment environment, and the harness removes the generated configuration on exit.

Enable local RustFS with `COMPOSE_PROFILES=storage` and configure its credentials in `.env`, or use another S3-compatible endpoint. Application file storage is selected separately with `DOCUMENTS_DISK` or the administrator UI.

See [installation](https://github.com/kinouzero/canabicultarr/wiki/installation), [operations and backups](https://github.com/kinouzero/canabicultarr/wiki/deployment), and [integration/Bluetooth setup](https://github.com/kinouzero/canabicultarr/wiki/integrations). Never delete persistent volumes to troubleshoot an existing instance.
