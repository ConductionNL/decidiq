---
description: Run Decidiq's Playwright end-to-end and accessibility tests
---
Run the browser tests in `tests/e2e`, configured in `playwright.config.ts`.

1. Make sure a Nextcloud with Decidiq and OpenRegister is running, for example `docker compose -f docker-compose.yml up -d --build` (http://localhost:8080, admin / admin).
2. `npm run test:e2e`, or `npx playwright test $ARGUMENTS` for one spec file.
3. For accessibility: `npm run a11y:scan` (configured in `tests/e2e/a11y/playwright.config.ts`).

Report each failing test with its step and the error.
