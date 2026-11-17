# Brand assets

Artwork from the approved November 17, 2026 brand pack, used to build the application logos, image placeholders and installation icons.

| File | Use |
| --- | --- |
| `mark.png` | Application symbol |
| `monochrome.png` | Theme-colored photo placeholders |
| `wordmark.png` | Horizontal application logo |
| `palette.json` | Source brand colors |

## Regenerate interface assets

From `frontend`, with dependencies and a Playwright Chromium browser installed:

```sh
npm run icons:generate
```

`PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` can select an existing Chromium installation. The generator trims transparent margins and writes interface derivatives under `public/branding`. Supplied favicon, Apple touch, standard PWA and maskable icons are retained. Replace those files from the approved pack when updating the brand; regeneration only changes the optimized interface derivatives. The email header uses the supplied 128px symbol at `backend/resources/images/mail-logo.png`.

Photo placeholders use the monochrome asset as a CSS mask so they follow the active theme. Third-party icon notices are under `public/licenses`.
