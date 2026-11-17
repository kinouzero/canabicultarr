#!/usr/bin/env sh
set -eu
PROJECT_ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PROJECT_ROOT"
export DB_PASSWORD=ci-e2e-password
E2E_ROOT=$(mktemp -d)
trap 'rm -rf "$E2E_ROOT"' EXIT
export E2E_COMPOSE_FILE="$E2E_ROOT/compose.json"
python3 scripts/test-environment.py "$E2E_COMPOSE_FILE"

compose_e2e() {
  docker compose -p canabicultarr-e2e -f "$E2E_COMPOSE_FILE" "$@"
}

cleanup() {
  compose_e2e down --volumes --remove-orphans >/dev/null 2>&1
}
trap 'result=$?; if [ "$result" -ne 0 ]; then
  compose_e2e logs --tail=40 app >&2
fi
cleanup
rm -rf "$E2E_ROOT"' EXIT
trap 'exit 1' HUP INT TERM
cleanup

compose_e2e run --build --rm --no-deps --entrypoint sh app -lc '
  set -eu
  mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
  touch storage/e2e.sqlite
'
compose_e2e up -d --wait --wait-timeout 60 redis
compose_e2e up -d --no-deps app

attempt=0
until curl --fail --silent --max-time 2 http://127.0.0.1:18000/up >/dev/null &&
  curl --fail --silent --max-time 2 http://127.0.0.1:18080/ >/dev/null; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 180 ]; then
    echo 'Isolated authentication services did not become ready.' >&2
    exit 1
  fi
  sleep 1
done

cd frontend
if [ "$#" -eq 0 ] || [ "${AUTH_E2E_BOOTSTRAP:-0}" = "1" ]; then
  # Initial setup is irreversible; exercise it before other flows create users.
  APP_COVERAGE_DEFER=1 npm run test:e2e -- account-activation.spec.ts
  if [ "$#" -eq 0 ]; then
    APP_COVERAGE_APPEND=1 npm run test:e2e -- --grep-invert 'account-activation.spec.ts'
  else
    APP_COVERAGE_APPEND=1 npm run test:e2e -- "$@"
  fi
else
  npm run test:e2e -- "$@"
fi
