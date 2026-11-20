#!/bin/sh
set -eu

runtime=$1
mkdir -p "$runtime/ini" "$runtime/notices"
# The local archive override stays pinned to the verified PHP 8.5.13 build.
expected_sha256=3df6d6dbe3b79d2d0ed045a7bc92e442f3a65083cf808620e101b86b7ffe5af6
archive=/run/secrets/windows_php_archive
if ! test -f "$archive"; then
    archive=$(mktemp)
    trap 'rm -f "$archive"' EXIT
    url=https://bin.nativephp.com/main/desktop/win/x64/php-8.5.zip
    checksum=$(curl -fsSL --retry 3 "$url.sha256")
    expected_sha256=$(printf '%s\n' "$checksum" | awk 'NR == 1 { print $1 }')
    if ! printf '%s\n' "$expected_sha256" | grep -Eq '^[0-9a-f]{64}$'; then
        echo 'NativePHP did not provide a valid SHA-256 checksum.' >&2
        exit 1
    fi
    curl -fsSL --retry 3 "$url" -o "$archive"
fi
if ! printf '%s  %s\n' "$expected_sha256" "$archive" | sha256sum -c; then
    echo 'Windows PHP archive does not match its expected checksum.' >&2
    exit 1
fi
unzip -q "$archive" php.exe -d "$runtime"
cp /etc/ssl/certs/ca-certificates.crt "$runtime/cert.pem"

source=https://raw.githubusercontent.com/NativePHP/php-bin/c4d0b4ad6df1f0fef196c22b2cac0d26b4658b38/license-files
for notice in src_php-src_0.txt zend.txt ext_zip_0.txt lib_brotli_0.txt lib_bzip2_0.txt lib_curl_0.txt lib_freetype_0.txt lib_icu_0.txt lib_libiconv-win_0.txt lib_libjpeg_0.txt lib_libpng_0.txt lib_libsodium_0.txt lib_libwebp_0.txt lib_libxml2_0.txt lib_libzip_0.txt lib_libssh2_0.txt lib_nghttp2_0.txt lib_onig_0.txt lib_openssl_0.txt lib_sqlite_0.txt lib_xz_0.txt lib_zlib_0.txt; do
    curl -fsSL --retry 3 "$source/$notice" -o "$runtime/notices/$notice"
done

cat > "$runtime/php.ini" <<'INI'
expose_php=Off
display_errors=Off
log_errors=On
openssl.cafile="${CANABICULTARR_CA_FILE}"
curl.cainfo="${CANABICULTARR_CA_FILE}"
INI
