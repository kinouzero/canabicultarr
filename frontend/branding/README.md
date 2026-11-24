# Brand assets

Artwork from the approved November 17, 2026 brand pack, used to build the application logos, image placeholders and installation icons.

| File | Use |
| --- | --- |
| `mark.png` | Application symbol |
| `monochrome.png` | Theme-colored photo placeholders |
| `wordmark.png` | Horizontal application logo |
| `palette.json` | Source brand colors |

## Regenerate interface and installation assets

From `frontend`, with dependencies and a Playwright Chromium browser installed:

```sh
npm run icons:generate
```

`PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` can select an existing Chromium installation. The generator trims transparent margins and writes interface derivatives under `public/branding`. It also places the supplied mark on the palette's light and dark backgrounds for the standard PWA, maskable, and Apple touch icons under `public/icons`. After changing `public/manifest.webmanifest`, regenerate its light variant so both retain the same application ID. The installation artwork follows the device theme exposed by the browser. The browser favicon and Desktop icon (`app-512.png`) are supplied separately. The email header uses the supplied 128px symbol at `backend/resources/images/mail-logo.png`.

Photo placeholders use the monochrome asset as a CSS mask so they follow the active theme. Third-party icon notices are under `public/licenses`.
