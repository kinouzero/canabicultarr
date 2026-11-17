# Project scripts

Run scripts from the repository root. Deployment configuration comes from the shared root `.env`.

| Script | Purpose |
| --- | --- |
| `bootstrap.sh` | Validate Compose, build the app (or pull with `--pull`) and initialize a missing application key |
| `test-bootstrap.sh` | Check bootstrap behavior using a temporary mocked Docker command |
| `test-environment.py` | Generate a temporary acceptance stack without loading private deployment settings |
| `test-auth-e2e.sh` | Start a disposable test instance and run selected Playwright scenarios |
| `test-container.sh` | Verify the packaged runtime against isolated data |
| `test-development.sh` | Verify the Vite/Nginx development path |
| `test-browser-coverage.sh` | Run browser coverage checks for CI |
| `release-metadata.py` | Validate release version, immutable tag and registry metadata for GitHub Actions |
| `tests/` | Compose and public-port configuration contracts |

The browser harness owns its test containers and volumes and removes them on exit. It does not reuse the deployment database or private connection environment. Tests may require a Chromium installation; follow [development](https://github.com/kinouzero/canabicultarr/wiki/local-development).

These scripts are development/operations tools, not a second application service. Runtime supervision lives under [`infrastructure/app`](../infrastructure/app).
