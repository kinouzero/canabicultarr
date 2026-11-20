#!/bin/sh
set -eu

cd "$(dirname "$0")/../../.."
root=$(pwd -P)
arch=$(uname -m)
version=$(python3 -c 'import json; print(json.load(open("frontend/dist/application.json"))["version"])')
if test -n "${CANABICULTARR_DESKTOP_VERSION:-}" && test "$CANABICULTARR_DESKTOP_VERSION" != "$version"; then
    echo 'The compiled frontend version does not match the requested Desktop version.' >&2
    exit 1
fi
short_version=${version%%-*}
if ! printf '%s\n' "$short_version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo 'The Desktop version must begin with a numeric major, minor, and patch version.' >&2
    exit 1
fi
revision=${CANABICULTARR_DESKTOP_BUILD:-7}
case "$revision" in
    ''|*[!0-9]*) echo 'The Desktop build number must be an integer.' >&2; exit 1 ;;
esac
test "$(uname -s)" = Darwin
identity=${CANABICULTARR_MACOS_SIGN_IDENTITY:-}
notary_profile=${CANABICULTARR_MACOS_NOTARY_PROFILE:-}
artifact="Canabicultarr-Desktop-preview-mac-$arch.dmg"
if test -n "$identity$notary_profile"; then
    case "$identity" in
        'Developer ID Application: '*) ;;
        *) echo 'Set CANABICULTARR_MACOS_SIGN_IDENTITY to a Developer ID Application identity.' >&2; exit 1 ;;
    esac
    if test -z "$notary_profile"; then
        echo 'Set CANABICULTARR_MACOS_NOTARY_PROFILE to a notarytool keychain profile.' >&2
        exit 1
    fi
    if ! security find-identity -v -p codesigning | grep -F " \"$identity\"" >/dev/null; then
        echo 'The configured Developer ID Application identity is unavailable in the keychain.' >&2
        exit 1
    fi
    xcrun --find notarytool >/dev/null
    xcrun --find stapler >/dev/null
    artifact="Canabicultarr-Desktop-mac-$arch.dmg"
fi
build="$root/.desktop-build"
staging=$(mktemp -d "$build-stage.XXXXXXXX")
trap 'rm -rf "$staging"' EXIT
app="$staging/Install Canabicultarr.app"
resources="$app/Contents/Resources"
project="$resources/project"
mkdir -p "$app/Contents/MacOS" "$project/backend" "$project/frontend" "$project/infrastructure/desktop" "$build"

python3 infrastructure/desktop/macos/bundle_php.py "$(command -v php)" "$resources/runtime"
if test -n "${CANABICULTARR_DESKTOP_LAN_BINARY:-}"; then
    install -m 0755 "$CANABICULTARR_DESKTOP_LAN_BINARY" "$resources/runtime/desktop-lan"
else
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "$resources/runtime/desktop-lan" infrastructure/desktop/lan/main.go infrastructure/desktop/lan/certificate.go
fi
mkdir "$resources/runtime/ini"
cp -L /etc/ssl/cert.pem "$resources/runtime/cert.pem"
cat > "$resources/runtime/php.ini" <<'INI'
expose_php=Off
display_errors=Off
log_errors=On
openssl.cafile="${CANABICULTARR_CA_FILE}"
curl.cainfo="${CANABICULTARR_CA_FILE}"
INI

for directory in app bootstrap config database lang public resources routes vendor; do
    if test -d "backend/$directory"; then
        /usr/bin/ditto "backend/$directory" "$project/backend/$directory"
    fi
done
rm -f "$project/backend/bootstrap/cache/"*.php
cp backend/artisan backend/composer.json backend/composer.lock "$project/backend/"
/usr/bin/ditto frontend/dist/ "$project/frontend/dist"
mkdir -p "$project/backend/resources"
cp frontend/dist/application.json "$project/backend/resources/application.json"
cp infrastructure/desktop/Configuration.php infrastructure/desktop/ProfileBackup.php infrastructure/desktop/BackupSchedule.php infrastructure/desktop/LanCertificate.php infrastructure/desktop/RuntimeLog.php infrastructure/desktop/router.php infrastructure/desktop/run.php "$project/infrastructure/desktop/"
cp frontend/branding/mark.png "$resources/brand-mark.png"
cp frontend/branding/palette.json "$resources/palette.json"
iconset="$staging/Canabicultarr.iconset"
mkdir "$iconset"
for size in 16 32 128 256 512; do
    /usr/bin/sips -z "$size" "$size" frontend/public/icons/app-512.png --out "$iconset/icon_${size}x${size}.png" >/dev/null
    /usr/bin/sips -z "$((2 * size))" "$((2 * size))" frontend/public/icons/app-512.png --out "$iconset/icon_${size}x${size}@2x.png" >/dev/null
done
/usr/bin/iconutil -c icns "$iconset" -o "$resources/Canabicultarr.icns"
rm -r "$iconset"
/usr/bin/ditto infrastructure/desktop/macos/en.lproj "$resources/en.lproj"
/usr/bin/ditto infrastructure/desktop/macos/fr.lproj "$resources/fr.lproj"

minimum_macos=$(otool -l "$resources/runtime/php" "$resources/runtime/"*.dylib | awk '/ minos / { print $2 }' | sort -V | tail -1)

cat > "$app/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
<key>CFBundleExecutable</key><string>Canabicultarr</string>
<key>CFBundleIdentifier</key><string>com.github.kinouzero.canabicultarr</string>
<key>CFBundleName</key><string>Canabicultarr</string>
<key>CFBundleIconFile</key><string>Canabicultarr.icns</string>
<key>CFBundleDevelopmentRegion</key><string>en</string>
<key>CFBundlePackageType</key><string>APPL</string>
<key>CFBundleShortVersionString</key><string>$short_version</string>
<key>CFBundleVersion</key><string>$revision</string>
<key>LSMinimumSystemVersion</key><string>13.0</string>
<key>NSHighResolutionCapable</key><true/>
</dict></plist>
PLIST
if test -n "$minimum_macos" && test "$(printf '%s\n' 13.0 "$minimum_macos" | sort -V | tail -1)" = "$minimum_macos"; then
    /usr/libexec/PlistBuddy -c "Set :LSMinimumSystemVersion $minimum_macos" "$app/Contents/Info.plist"
fi

swiftc -O -parse-as-library -swift-version 5 -target "$arch-apple-macos13.0" \
    infrastructure/desktop/macos/DesktopApp.swift infrastructure/desktop/macos/DesktopSetupClient.swift \
    infrastructure/desktop/macos/DesktopBrand.swift infrastructure/desktop/macos/DesktopInstallation.swift -o "$app/Contents/MacOS/Canabicultarr"
if test -n "$identity"; then
    for library in "$resources/runtime/"*.dylib; do
        codesign --force --timestamp --sign "$identity" "$library"
        codesign --verify --strict "$library"
    done
    for executable in "$resources/runtime/php" "$resources/runtime/desktop-lan"; do
        codesign --force --timestamp --options runtime --sign "$identity" "$executable"
        codesign --verify --strict "$executable"
    done
    (
        cd "$resources/runtime"
        for file in php ./*.dylib; do
            checksum=$(shasum -a 256 "$file")
            printf '%s %s\n' "${file#./}" "${checksum%% *}"
        done > manifest.txt
    )
    codesign --force --timestamp --options runtime --sign "$identity" "$app"
else
    codesign --force --sign - "$app"
fi
codesign --verify --deep --strict "$app"

image_dir=$(mktemp -d "$build/.macos-image.XXXXXXXX")
trap 'rm -rf "$staging" "$image_dir"' EXIT
image="$image_dir/$artifact"
printf 'Creating disk image for %s\n' "$arch"
/usr/bin/hdiutil create -nospotlight -fs APFS -format UDZO -volname "Canabicultarr Desktop" -srcfolder "$staging" "$image"
if test -n "$identity"; then
    codesign --timestamp --sign "$identity" "$image"
    codesign --verify --strict "$image"
    xcrun notarytool submit "$image" --keychain-profile="$notary_profile" --wait --timeout 30m
    xcrun stapler staple "$image"
    xcrun stapler validate "$image"
    spctl --assess --type open --context context:primary-signature "$image"
fi
mv -f "$image" "$build/$artifact"
printf '%s\n' "$build/$artifact"
