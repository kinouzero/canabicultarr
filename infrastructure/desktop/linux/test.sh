#!/bin/sh
set -eu

cd "$(dirname "$0")/../../.."
case "${1:-}" in arm64|amd64) arch=$1 ;; *) echo 'Specify arm64 or amd64.' >&2; exit 1 ;; esac
previous=${CANABICULTARR_TEST_PREVIOUS_PREVIEW:-}
if test -n "$previous"; then
    set -- -v "$previous:/previous:ro"
else
    set --
fi
docker run "$@" --rm --network none --platform "linux/$arch" \
    -v "$PWD/.desktop-build:/packages:ro" -v "$PWD/infrastructure/desktop:/checks:ro" \
    python:3.13-slim-bookworm sh -eu -c '
    package="canabicultarr-desktop_0.2.0-preview_$1.deb"
    ! command -v php
    ! command -v node
    export PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=/checks
    if test -f "/previous/$package"; then
        dpkg -i "/previous/$package"
        install -d -m 0700 -o nobody /tmp/desktop-profile
        su -s /bin/sh nobody -c "python3 /checks/test_launcher.py prepare-upgrade /tmp/desktop-profile"
        old_version=$(dpkg-query -W canabicultarr-desktop | cut -f2)
        dpkg -i "/packages/$package"
        dpkg --compare-versions "$(dpkg-query -W canabicultarr-desktop | cut -f2)" gt "$old_version"
        su -s /bin/sh nobody -c "python3 /checks/test_launcher.py verify-upgrade /tmp/desktop-profile"
    else
        dpkg -i "/packages/$package"
        su -s /bin/sh nobody -c "python3 /checks/test_launcher.py"
    fi
' sh "$arch"
