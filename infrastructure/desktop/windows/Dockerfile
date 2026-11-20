# syntax=docker/dockerfile:1
FROM --platform=$BUILDPLATFORM composer:2 AS backend
WORKDIR /app/backend
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction

FROM --platform=$BUILDPLATFORM node:26-bookworm-slim AS frontend
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci --no-fund
COPY frontend/ ./
RUN npm run build

FROM debian:bookworm-slim AS assets
RUN apt-get update && apt-get install -y --no-install-recommends binutils-mingw-w64-x86-64 cpp imagemagick && rm -rf /var/lib/apt/lists/*
WORKDIR /assets
COPY frontend/public/icons/app-512.png app.png
RUN convert app.png -define icon:auto-resize=256,128,64,48,32,16 Canabicultarr.ico \
 && printf '1 ICON "Canabicultarr.ico"\n' > icon.rc \
 && x86_64-w64-mingw32-windres --preprocessor=cpp icon.rc icon_windows_amd64.syso

FROM --platform=$BUILDPLATFORM golang:1.26-bookworm AS launcher
WORKDIR /src
COPY infrastructure/desktop/launcher/main.go infrastructure/desktop/launcher/platform_windows.go infrastructure/desktop/launcher/setup.html ./
COPY --from=assets /assets/icon_windows_amd64.syso ./
RUN printf 'module canabicultarr-desktop\n\ngo 1.26\n' > go.mod \
 && CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -trimpath -ldflags="-s -w -H=windowsgui" -o /Canabicultarr.exe .

COPY infrastructure/desktop/lan/main.go infrastructure/desktop/lan/certificate.go /src/lan/
RUN CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -trimpath -ldflags="-s -w -H=windowsgui" -o /desktop-lan.exe ./lan

FROM debian:bookworm-slim AS pack
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl nsis unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /build
COPY infrastructure/desktop/windows/bundle-runtime.sh ./
RUN --mount=type=secret,id=windows_php_archive sh bundle-runtime.sh /build/payload/runtime
COPY --from=backend /app/backend ./payload/project/backend
COPY --from=frontend /app/dist ./payload/project/frontend/dist
COPY --from=frontend /app/dist/application.json ./payload/project/backend/resources/application.json
COPY --from=launcher /desktop-lan.exe ./payload/runtime/desktop-lan.exe
COPY --from=launcher /Canabicultarr.exe ./payload/
COPY --from=assets /assets/Canabicultarr.ico ./payload/
COPY infrastructure/desktop/router.php infrastructure/desktop/run.php infrastructure/desktop/Configuration.php infrastructure/desktop/ProfileBackup.php infrastructure/desktop/BackupSchedule.php infrastructure/desktop/LanCertificate.php infrastructure/desktop/RuntimeLog.php ./payload/project/infrastructure/desktop/
COPY frontend/branding/mark.png frontend/branding/palette.json ./payload/branding/
COPY infrastructure/desktop/windows/installer.nsi ./
ARG DESKTOP_VERSION=0.2.0-preview.7
RUN mkdir /out && makensis -V2 "-DDESKTOP_VERSION=$DESKTOP_VERSION" installer.nsi

FROM debian:trixie-slim AS wine
RUN apt-get update && apt-get install -y --no-install-recommends wine64 python3 xvfb xauth && rm -rf /var/lib/apt/lists/*

FROM scratch AS artifact
COPY --from=pack /out/ /
