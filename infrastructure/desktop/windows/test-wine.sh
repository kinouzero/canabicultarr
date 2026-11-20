#!/bin/sh
set -eu

cd "$(dirname "$0")/../../.."
previous=${CANABICULTARR_TEST_PREVIOUS_PREVIEW:-}
if test -n "$previous"; then
    set -- -v "$previous:/previous:ro"
else
    set --
fi
docker run "$@" --rm --network none --platform linux/amd64 \
    -v "$PWD/.desktop-build:/packages:ro" -v "$PWD/infrastructure/desktop:/checks:ro" \
    cana-windows-smoke:local sh -eu -c '
    install -d -m 0700 -o nobody /tmp/fixture
    su -s /bin/sh nobody -c '"'"'
        export HOME=/tmp/fixture WINEPREFIX=/tmp/fixture/wine WINEDEBUG=-all
        export PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=/checks
        xvfb-run -a python3 /checks/windows/test_installation.py
    '"'"'
'
