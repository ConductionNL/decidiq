# AGENTS.md

Instructions for coding agents (and people) working in this repository. Every command below exists in `composer.json` or `package.json`.

## What this app is

Decidiq is a decision-making platform for Nextcloud for governance bodies, associations, corporate boards and operational meetings. It manages meetings, agendas, motions, amendments, voting, minutes and decision tracking, and stores its data as objects in [OpenRegister](https://github.com/ConductionNL/openregister), which must be installed first.

More context: [README.md](README.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Setup

Evaluation stack (Nextcloud with Decidiq and OpenRegister, built from this repository):

```bash
docker compose -f docker-compose.yml up -d --build
# open http://localhost:8080/apps/decidiq/ and sign in as admin / admin
docker compose -f docker-compose.yml down -v   # remove the stack and its data
```

Dependencies for development:

```bash
composer install
npm install
```

## Build

```bash
npm run build   # production webpack build
npm run dev     # development build
npm run watch   # rebuild on change
```

## Test

```bash
composer test:unit   # PHPUnit (phpunit.xml: tests/Unit, tests/unit, tests/Integration)
npm run test:unit    # Vitest
npm run test:e2e     # Playwright (playwright.config.ts, tests/e2e)
npm run a11y:scan    # Playwright accessibility scan (tests/e2e/a11y)
```

## Lint and quality

```bash
composer check:strict      # lint, phpcs, phpmd, psalm, phpstan, test:all
composer check             # quicker: lint, phpcs, psalm, test:unit
composer cs:fix            # fix PHP code style
npm run lint               # ESLint
npm run stylelint          # CSS and Vue styles
npm run format             # Prettier check
npm run check:manifest     # validate src/manifest.json
npm run test:l10n          # translation keys
npm run test:l10n:parity   # every locale complete
```

## OpenSpec flow

Work is specified before it is built, in [`openspec/`](openspec/) (see [openspec/README.md](openspec/README.md)):

1. Propose a change in `openspec/changes/<change-name>/` with `proposal.md`, `design.md`, `tasks.md` and spec deltas in `specs/<capability>/spec.md`.
2. Build it, ticking tasks in `tasks.md`.
3. Merge the spec deltas into `openspec/specs/<capability>/spec.md`.
4. Archive the change to `openspec/changes/archive/`.

Project rules for proposals, specs and designs are in [openspec/config.yaml](openspec/config.yaml).

## Architecture decisions

App-level decision records are in [`openspec/architecture/`](openspec/architecture/). Design decisions for a single change are in that change's `design.md`.

## Repository conventions

- Code is generic: one universal entity set adapts per governance mode, instead of parallel entities per audience ([ADR-006](openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md)).
- Data lives in OpenRegister. Schemas are configuration in `lib/Settings/decidesk_register.json` (OpenAPI 3.0.0) with additions in `lib/Settings/register.d/`, imported into OpenRegister.
- Workflows are configuration: object lifecycles are declared in the register files above, as OpenRegister metadata ([openspec/config.yaml](openspec/config.yaml)).
- Pages and menus are manifest configuration in `src/manifest.json`. Check them with `npm run check:manifest`.
- Every user-visible string is translated; see `l10n/` and `npm run test:l10n`.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example `docs: add AGENTS.md with build, test and lint commands`. See [CONTRIBUTING.md](CONTRIBUTING.md).
