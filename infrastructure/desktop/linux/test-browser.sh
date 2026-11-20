#!/bin/sh
set -eu

cd "$(dirname "$0")/../../.."
case "${1:-}" in arm64|amd64) arch=$1 ;; *) echo 'Specify arm64 or amd64.' >&2; exit 1 ;; esac
container=$(docker run -d --rm --platform "linux/$arch" --network host \
    -v "$PWD/.desktop-build:/packages:ro" debian:bookworm-slim sh -ec '
    dpkg -i "/packages/canabicultarr-desktop_0.2.0-preview_$1.deb" >/dev/null
    install -d -m 0700 -o nobody /tmp/cana-brand-preview
    exec su -s /bin/sh nobody -c "CANABICULTARR_DESKTOP_DATA_DIR=/tmp/cana-brand-preview CANABICULTARR_DESKTOP_NO_BROWSER=1 /opt/canabicultarr/canabicultarr-desktop"
' sh "$arch")
trap 'docker stop "$container" >/dev/null 2>&1 || :' EXIT

for attempt in $(seq 1 30); do
    if wizard=$(docker exec "$container" cat /tmp/cana-brand-preview/launcher-url 2>/dev/null) &&
       curl --max-time 1 -fsS "$wizard" >/dev/null 2>&1; then
        CANABICULTARR_DESKTOP_WIZARD_URL="$wizard" node infrastructure/desktop/linux/test-browser.mjs
        exit
    fi
    sleep 1
done
echo 'The disposable Linux wizard could not be reached from the test browser.' >&2
exit 1
