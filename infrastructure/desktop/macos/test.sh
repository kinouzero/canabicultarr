#!/bin/sh
set -eu

cd "$(dirname "$0")/../../.."
image="${CANABICULTARR_TEST_MAC_IMAGE:-$PWD/.desktop-build/Canabicultarr-Desktop-preview-mac-$(uname -m).dmg}"
if test "${CANABICULTARR_TEST_MAC_NOTARIZED:-0}" = 1; then
    codesign --verify --strict "$image"
    xcrun stapler validate "$image"
    spctl --assess --type open --context context:primary-signature "$image"
fi
scratch=$(mktemp -d "${TMPDIR:-/tmp}/canabicultarr-macos-test.XXXXXXXX")
mount="$scratch/mount"
previous_mount="$scratch/previous"
mkdir "$mount" "$previous_mount"
cleanup() {
    hdiutil detach "$previous_mount" -quiet 2>/dev/null || :
    if hdiutil detach "$mount" -quiet 2>/dev/null; then
        rm -rf "$scratch"
    elif ! mount | grep -F " on $mount (" >/dev/null; then
        rm -rf "$scratch"
    fi
}
trap cleanup EXIT

hdiutil verify "$image" -quiet
hdiutil attach "$image" -readonly -nobrowse -mountpoint "$mount" -quiet
app="$scratch/Applications/Canabicultarr.app"
mkdir "$(dirname "$app")"
if test -n "${CANABICULTARR_TEST_PREVIOUS_PREVIEW:-}"; then
    legacy="$CANABICULTARR_TEST_PREVIOUS_PREVIEW/$(basename "$image")"
    hdiutil attach "$legacy" -readonly -nobrowse -mountpoint "$previous_mount" -quiet
    /usr/bin/ditto "$previous_mount/Install Canabicultarr.app" "$app"
    swiftc -O -parse-as-library -swift-version 5 infrastructure/desktop/macos/DesktopInstallation.swift \
        infrastructure/desktop/macos/UpgradeSmoke.swift -o "$scratch/upgrade-smoke"
    export CANABICULTARR_TEST_MAC_UPGRADE_HELPER="$scratch/upgrade-smoke"
    export CANABICULTARR_TEST_MAC_UPGRADE_SOURCE="$mount/Install Canabicultarr.app"
else
    /usr/bin/ditto "$mount/Install Canabicultarr.app" "$app"
fi
codesign --verify --deep --strict "$app"
test "$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIconFile' "$app/Contents/Info.plist")" = Canabicultarr.icns
test -s "$app/Contents/Resources/Canabicultarr.icns"
cmp "$app/Contents/Resources/brand-mark.png" frontend/branding/mark.png
cmp "$app/Contents/Resources/palette.json" frontend/branding/palette.json
candidate="$mount/Install Canabicultarr.app"
project="$candidate/Contents/Resources/project"
cmp "$project/frontend/dist/application.json" "$project/backend/resources/application.json"
version=$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["version"])' "$project/frontend/dist/application.json")
test "$(/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$candidate/Contents/Info.plist")" = "${version%%-*}"
runtime="$app/Contents/Resources/runtime"
if otool -L "$runtime/php" "$runtime/"*.dylib | grep -E '^[[:space:]]+/(opt|usr/local)/'; then
    echo 'Found a PHP dependency outside the installed application.' >&2
    exit 1
fi

swiftc -O -parse-as-library -swift-version 5 infrastructure/desktop/macos/DesktopSetupClient.swift \
    infrastructure/desktop/macos/SetupSmoke.swift -o "$scratch/setup-smoke"
export CANABICULTARR_TEST_NATIVE_SETUP="$scratch/setup-smoke"
export CANABICULTARR_TEST_PHP="$runtime/php"
export CANABICULTARR_TEST_DESKTOP_LAUNCHER="$app/Contents/Resources/project/infrastructure/desktop/run.php"
export PHPRC="$runtime/php.ini"
export PHP_INI_SCAN_DIR="$runtime/ini"
export CANABICULTARR_CA_FILE="$runtime/cert.pem"
if test -n "${CANABICULTARR_TEST_LAN_BROWSER_ADDRESS:-}"; then
    CANABICULTARR_TEST_NODE=$(command -v node)
    export CANABICULTARR_TEST_NODE
fi
PATH=/usr/bin:/bin:/usr/sbin:/sbin python3 infrastructure/desktop/test_runtime.py
codesign --verify --deep --strict "$app"
