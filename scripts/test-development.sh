#!/usr/bin/env sh
set -eu
# Exercise the actual Vite/Nginx development path with isolated fake configuration.
export APP_BUILD_TARGET=development
export E2E_DEV_SERVER=true
exec sh "$(dirname "$0")/test-auth-e2e.sh" development-app.spec.ts
