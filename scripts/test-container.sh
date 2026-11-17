#!/usr/bin/env sh
set -eu
# Exercise the actual runtime image, with isolated SQLite/Redis and fake credentials.
export APP_BUILD_TARGET=runtime
exec sh "$(dirname "$0")/test-auth-e2e.sh" packaged-app.spec.ts
