# syntax=docker/dockerfile:1
FROM php:8.5-cli-bookworm AS php
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libjpeg62-turbo-dev libpng-dev libwebp-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j "$(nproc)" intl zip bcmath pcntl gd exif \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction
COPY infrastructure/desktop/linux/bundle-runtime.sh /tmp/bundle-runtime.sh
RUN sh /tmp/bundle-runtime.sh /runtime

FROM --platform=$BUILDPLATFORM node:26-bookworm-slim AS frontend
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci --no-fund
COPY frontend/ ./
RUN npm run build

FROM --platform=$BUILDPLATFORM golang:1.26-bookworm AS launcher
ARG TARGETARCH
WORKDIR /src
COPY infrastructure/desktop/launcher/main.go infrastructure/desktop/launcher/platform_linux.go infrastructure/desktop/launcher/setup.html ./
RUN CGO_ENABLED=0 GOOS=linux GOARCH="$TARGETARCH" go build -trimpath -ldflags="-s -w" -o /launcher main.go platform_linux.go

COPY infrastructure/desktop/lan/main.go infrastructure/desktop/lan/certificate.go /src/lan/
RUN CGO_ENABLED=0 GOOS=linux GOARCH="$TARGETARCH" go build -trimpath -ldflags="-s -w" -o /desktop-lan lan/main.go lan/certificate.go

FROM debian:bookworm-slim AS pack
ARG TARGETARCH
ARG DESKTOP_VERSION=0.2.0~preview7
WORKDIR /package
COPY --from=php /runtime ./opt/canabicultarr/runtime
COPY --from=php /app/backend ./opt/canabicultarr/project/backend
COPY --from=frontend /app/dist ./opt/canabicultarr/project/frontend/dist
COPY --from=frontend /app/dist/application.json ./opt/canabicultarr/project/backend/resources/application.json
COPY --from=launcher /desktop-lan ./opt/canabicultarr/runtime/desktop-lan
COPY --from=launcher /launcher ./opt/canabicultarr/canabicultarr-desktop
COPY infrastructure/desktop/router.php infrastructure/desktop/run.php infrastructure/desktop/Configuration.php infrastructure/desktop/ProfileBackup.php infrastructure/desktop/BackupSchedule.php infrastructure/desktop/LanCertificate.php infrastructure/desktop/RuntimeLog.php ./opt/canabicultarr/project/infrastructure/desktop/
COPY frontend/branding/mark.png ./opt/canabicultarr/branding/mark.png
COPY frontend/branding/palette.json ./opt/canabicultarr/branding/palette.json
COPY frontend/public/icons/app-512.png ./usr/share/icons/hicolor/512x512/apps/canabicultarr.png
COPY infrastructure/desktop/linux/canabicultarr.desktop ./usr/share/applications/
RUN mkdir DEBIAN /out \
 && chmod -R a+rX opt usr \
 && printf 'Package: canabicultarr-desktop\nVersion: %s\nSection: utils\nPriority: optional\nArchitecture: %s\nDepends: libc6 (>= 2.36)\nMaintainer: Canabicultarr\nDescription: Canabicultarr local desktop application\n' "$DESKTOP_VERSION" "$TARGETARCH" > DEBIAN/control \
 && dpkg-deb --root-owner-group --build /package "/out/canabicultarr-desktop_0.2.0-preview_${TARGETARCH}.deb"

FROM scratch AS artifact
COPY --from=pack /out/ /
