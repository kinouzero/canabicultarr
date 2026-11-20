#!/bin/sh
set -eu
cd "$(dirname "$0")/../../.."

network=$(docker network create --internal "cana-lan-smoke-$$")
trap 'docker network rm "$network" >/dev/null 2>&1 || :' EXIT
docker run --rm --platform linux/arm64 --network "$network" --network-alias cana-desktop.test \
    -v "$PWD/.desktop-build:/packages:ro" -v "$PWD/infrastructure/desktop:/checks:ro" \
    python:3.13-slim-bookworm sh -eu -c '
    dpkg -i /packages/canabicultarr-desktop_0.2.0-preview_arm64.deb
    runtime=/opt/canabicultarr/runtime
    export CANABICULTARR_TEST_LAN=1
    export CANABICULTARR_TEST_PHP="$runtime/php" CANABICULTARR_TEST_DESKTOP_LAUNCHER=/opt/canabicultarr/project/infrastructure/desktop/run.php
    export LD_LIBRARY_PATH="$runtime/lib" PHPRC="$runtime/php.ini" PHP_INI_SCAN_DIR="$runtime/ini"
    export CANABICULTARR_CA_FILE="$runtime/cert.pem" CANABICULTARR_PHP_EXTENSIONS="$runtime/extensions"
    PYTHONDONTWRITEBYTECODE=1 su -s /bin/sh nobody -c "python3 /checks/test_runtime.py"
'
