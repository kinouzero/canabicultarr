#!/usr/bin/env sh
set -eu
PROJECT_ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$PROJECT_ROOT"
npm --prefix frontend run build:coverage
APP_COVERAGE=1 sh scripts/test-auth-e2e.sh "$@"
