#!/bin/sh
set -eu

runtime=$1
mkdir -p "$runtime/lib" "$runtime/ini" "$runtime/extensions" "$runtime/notices"
cp /usr/local/bin/php "$runtime/php"
cp /etc/ssl/certs/ca-certificates.crt "$runtime/cert.pem"
extension_dir=$(php -n -r 'echo ini_get("extension_dir");')
for extension in bcmath exif gd intl opcache pcntl zip; do
    if test -f "$extension_dir/$extension.so"; then
        cp "$extension_dir/$extension.so" "$runtime/extensions/"
    fi
done
cat > "$runtime/php.ini" <<'INI'
expose_php=Off
display_errors=Off
log_errors=On
openssl.cafile="${CANABICULTARR_CA_FILE}"
curl.cainfo="${CANABICULTARR_CA_FILE}"
extension_dir="${CANABICULTARR_PHP_EXTENSIONS}"
INI
for extension in bcmath exif gd intl pcntl zip; do
    if test -f "$runtime/extensions/$extension.so"; then
        printf 'extension=%s\n' "$extension" >> "$runtime/php.ini"
    fi
done
if test -f "$runtime/extensions/opcache.so"; then printf 'zend_extension=opcache\n' >> "$runtime/php.ini"; fi
# ldd gives the complete dependency closure for the PHP binary and each extension.
for file in "$runtime/php" "$runtime/extensions/"*.so; do ldd "$file"; done |
    awk '/=> \// {print $1, $3}' | sort -u | while read -r soname library; do
    case "$soname" in
        libc.so.*|libm.so.*|libdl.so.*|libpthread.so.*|librt.so.*|libresolv.so.*|libutil.so.*) continue ;;
    esac
    cp -L "$library" "$runtime/lib/$soname"
    package=$(dpkg-query -S "$(realpath "$library")" 2>/dev/null | head -1 | cut -d: -f1)
    if test -n "$package" && test -f "/usr/share/doc/$package/copyright"; then
        cp -L "/usr/share/doc/$package/copyright" "$runtime/notices/$package.txt"
    fi
done
PHPRC="$runtime/php.ini" PHP_INI_SCAN_DIR="$runtime/ini" CANABICULTARR_PHP_EXTENSIONS="$runtime/extensions" \
    CANABICULTARR_CA_FILE="$runtime/cert.pem" LD_LIBRARY_PATH="$runtime/lib" "$runtime/php" -r '
foreach (["pdo_sqlite", "pcntl", "posix", "intl", "mbstring", "bcmath", "gd", "zip", "exif", "dom", "fileinfo"] as $extension) {
  if (!extension_loaded($extension)) { fwrite(STDERR, "Missing $extension\n"); exit(1); }
}'
