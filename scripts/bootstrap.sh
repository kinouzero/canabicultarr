#!/usr/bin/env sh
set -eu
PROJECT_ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PROJECT_ROOT"
if [ "$#" -gt 1 ]; then
  echo 'Usage: sh scripts/bootstrap.sh [--pull|--dev]' >&2
  exit 1
fi
case "${1:-}" in
  ''|--pull) image_action=pull; set -- -f compose.yaml ;;
  --dev) image_action=build; set -- -f compose.yaml -f compose.dev.yaml ;;
  *) echo 'Usage: sh scripts/bootstrap.sh [--pull|--dev]' >&2; exit 1 ;;
esac
if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required to start Canabicultarr." >&2
  exit 1
fi
if [ ! -f .env ]; then
  echo "Copy .env.example to .env and set DB_PASSWORD before starting." >&2
  exit 1
fi
if [ -e backend/.env ] || [ -L backend/.env ]; then
  if ! [ backend/.env -ef .env ]; then
    echo "backend/.env must point to ../.env; merge separate values into the root file first." >&2
    exit 1
  fi
else
  ln -s ../.env backend/.env
fi
docker compose "$@" config --quiet
docker compose "$@" "$image_action" app
if ! grep -q '^APP_KEY=.' .env; then
  docker compose "$@" run --rm --no-deps \
    -v "$PROJECT_ROOT/.env:/var/www/html/.env" app php artisan key:generate --force
fi
if [ "$image_action" = build ]; then
  echo "Development image prepared. Run docker compose -f compose.yaml -f compose.dev.yaml up --build --watch."
else
  echo "Application prepared. Run docker compose up -d; pending migrations run before application services start."
fi
