# Frontend

Vue 3, strict TypeScript and Naive UI interface for Canabicultarr, with English/French translations, responsive layouts, themes and a basic PWA fallback. Naive UI requires no license key.

## Development

Use the shared root `.env`; browser code never receives application secrets. Start the development container from the repository root:

```sh
docker compose -f compose.yaml -f compose.dev.yaml up --build --watch
```

For a standalone Vite session, install Node 26 and run these commands from `frontend`:

```sh
npm ci
npm run dev
```

Vite proxies the API to `http://127.0.0.1:8420` by default. Set `API_PROXY_URL` in the command environment when the backend uses another address.

## Source map

| Directory | Purpose |
| --- | --- |
| `src/pages` | Dashboard, culture, monitoring, integrations, administration and account screens |
| `src/components` | Shared controls, cards, dialogs and feature sections |
| `src/api` | Typed API clients and response contracts |
| `src/lib` | Shared state and application behavior |
| `src/locales` | English and French translations |
| `src/styles` | Theme, spacing and control styles |
| `public`, `pwa`, `build` | Static assets and PWA build/worker code |
| `branding` | Original artwork used to regenerate icons |
| `tests` | Unit, browser and PWA regression checks |

## Verification

Run the relevant checks from this directory:

```sh
npm run audit
npm run test:unit
npm run test:pwa
npm run build
```

The build includes strict type checking. The [dependency security guide](https://github.com/kinouzero/canabicultarr/wiki/dependency-security) describes the npm audit threshold used in CI. API-backed browser tests use the isolated harness from the repository root, for example `sh scripts/test-auth-e2e.sh connection-settings.spec.ts`; see the [development guide](https://github.com/kinouzero/canabicultarr/wiki/local-development) for initialization and browser setup.

The [handbook](https://github.com/kinouzero/canabicultarr/wiki/Home) explains user workflows. Full offline editing/synchronization and Desktop installers are outside the current release.
