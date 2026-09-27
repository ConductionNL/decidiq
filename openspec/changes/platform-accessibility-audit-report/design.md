# Design: platform-accessibility-audit-report

Kind: code. One Playwright suite, one checklist file, one report generator,
one release step. Read against decidiq `development` at 4d7430ff and hydra
`development` at 6ce49e3.

## What exists today

- **The baseline.** `openspec/specs/accessibility-baseline/spec.md`
  (REQ-ACC-001 to REQ-ACC-005): one H1, a skip link, landmarks, keyboard
  operation, translated strings. No audit.
- **Public pages.** decidiq serves no public HTML. Its `#[PublicPage]`
  controllers (`lib/Controller/OriController.php`,
  `lib/Controller/ProjectionController.php`,
  `lib/Controller/ParticipationController.php`,
  `lib/Controller/HealthController.php`) answer JSON. Residents read decidiq
  data through OpenCatalogi (`src/views/settings/PublicationSettings.vue`) and
  through portaliq pages built from `lib/Portal/PortalContributionProvider.php`.
- **axe-core.** `package.json:53` depends on `axe-core ^4.13.0`, and no test in
  `tests/` imports it. There is no `tests/axe/report.json`.
- **The hydra axe gate.** hydra `.claude/skills/hydra-gate-axe/SKILL.md`: stage
  20 of `run-hydra-gates.sh` reads `tests/axe/report.json`, fails on serious or
  critical violations, and skips silently when the file is absent.
- **hydra's statement generator.** hydra
  `scripts/generate-toegankelijkheidsverklaring.sh` computes a status from
  gate logs under `/tmp/hydra-gate-<name>.log` and cites
  `openspec/architecture/wcag-coverage.md` (`:91`), which does not exist on
  hydra `development`. It is not evidence for a buyer, so this change does not
  build on it.
- **Playwright.** `tests/e2e/playwright.config.ts`, `npm run test:e2e`.
- **Documents.** The open change `document-accessibility-check` scans PDFs
  before publication and reports per body; this change does not repeat it.

## D1. The sample

`tests/e2e/a11y/sample.json`, following WCAG-EM step 3 (a structured sample):

- every page `type` the manifests declare (`index`, `detail`, `dashboard`,
  `settings`, `custom`), one page each, chosen from `src/manifest.json` and
  `src/manifest.d/*.json` by a small script so a new page type cannot be
  missed;
- the complete processes a member walks: open a meeting, cast a vote, read the
  minutes;
- `LiveMeeting`, the projection screen, and the personal settings;
- when portaliq is installed on the test instance, the portal pages of each
  decidiq collection, marked `owner: portaliq`.

## D2. The automated scan

`tests/e2e/a11y/wcag-audit.spec.ts` visits each sample entry as a seeded member
and as an administrator, runs axe-core with the tags `wcag2a`, `wcag2aa`,
`wcag21a` and `wcag21aa`, and merges the violations into
`tests/axe/report.json` in the shape the hydra axe gate reads.

Every violation gets an owner from the node it sits in: inside decidiq's app
root it is `decidiq`, in Nextcloud's header, navigation or footer it is
`nextcloud`, on a portal page it is `portaliq`. The reason is written in
`.github/workflows/code-quality.yml:243`: `enable-axe` is off because a vanilla
Nextcloud 34 already carries serious and critical violations on core's own
routes. Owner attribution is what lets decidiq's scan fail only on its own
markup while still reporting the rest.

A serious or critical violation owned by `decidiq` fails the test. Others are
recorded and do not fail decidiq's run.

## D3. The manual checklist

`docs/compliance/wcag-manual-checks.json`: one entry per WCAG 2.1 A and AA
success criterion that axe does not decide, with the check to perform in plain
words, and per release `result` (`pass`, `fail`, `not-applicable`,
`not-tested`), `tester`, `date` and `note`. Empty results are `not-tested`,
never `pass`.

## D4. The report

`scripts/wcag-audit-report.mjs` reads `tests/axe/report.json`, the checklist
and `package.json` version, and writes `docs/compliance/wcag-2.1-aa-audit.md`:

1. what was evaluated, the version, the date, and that it is a supplier
   self-evaluation following WCAG-EM;
2. the sample;
3. a table of all WCAG 2.1 A and AA success criteria with the outcome, and for
   each failure the page, the rule and the owner (`decidiq`, `nextcloud`,
   `portaliq`, `opencatalogi`);
4. counts per outcome, with `not-tested` shown as a count of its own.

`npm run a11y:report` runs the scan and the generator. The docs site links the
report from `docs/compliance/`.

## D5. Release

The release workflow is shared (`.github/workflows/release.yml:39` calls
`ConductionNL/.github` `release.yml`), so decidiq does not change it.
`code-quality.yml` runs `npm run a11y:report` on tags and uploads the report
as an artefact. Whether to turn on the shared `enable-axe` input as well is
left to the decision the workflow note names: with owner attribution in
decidiq's own report, the answer no longer depends on core's violations.

## Declarative or imperative

No schema, lifecycle or widget changes. The scan and the generator are test
and build tooling; ADR-031 does not apply.

## Seed data

No schema is added. The scan runs against the municipality example set
(`lib/Settings/profiles/municipality.json`), so every sampled detail page has a
realistic object.

## Risks

- axe results vary with the theme. The scan runs with the default Nextcloud
  theme and, when installed, the NL Design theme, and the report says which.
