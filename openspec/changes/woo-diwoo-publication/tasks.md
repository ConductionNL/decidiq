# Tasks: woo-diwoo-publication

## Implementation Tasks

### Task 1: Register fragment 58 — WooCategorieMapping schema, aggregations, additive `diwoo` property
- **spec_ref**: `openspec/changes/woo-diwoo-publication/specs/woo-diwoo-publication/spec.md#requirement-req-woo-001-woo-informatiecategorie-mapping-configuration`
- **files**: `lib/Settings/register.d/58-woo-diwoo-publication.json`, `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported on a clean instance WHEN schemas are listed THEN `woo-categorie-mapping` exists from fragment 58 with pattern-validated `informatiecategorie` (`https://identifier.overheid.nl/tooi/` prefix), required `objectType`/`informatiecategorieLabel`/`active`, per-property titles, and the coverage `x-openregister-aggregations`
  - GIVEN existing `PublicationPayload` objects WHEN the additive optional `diwoo` property imports (canonical file, not the fragment) THEN existing objects validate unchanged
  - GIVEN a mapping write with a non-TOOI URI WHEN validated THEN OR rejects it
- [ ] Implement
- [ ] Test

### Task 2: Seed data — per-type mappings with verbatim TOOI waardelijst URIs
- **spec_ref**: `openspec/changes/woo-diwoo-publication/specs/woo-diwoo-publication/spec.md#requirement-req-woo-001-woo-informatiecategorie-mapping-configuration`
- **files**: `lib/Settings/register.d/58-woo-diwoo-publication.json` (`x-openregister-seeds`)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seeds import THEN 8 mapping rows exist (meeting-agenda, besluitenlijst, minutes, decision, motie, toezegging, raadsinformatiebrief, regeling) with `@self` envelopes, each `informatiecategorie` copied verbatim from the published TOOI waardelijst woo-informatiecategorieën (vergaderstukken-decentrale-overheden for vergaderstuk types; wetten-en-algemeen-verbindende-voorschriften for regeling) — no invented URIs
  - GIVEN sibling schemas (motie/toezegging/raadsinformatiebrief/regeling) not yet installed WHEN import and publish run THEN their rows are inert and nothing fails (design Seed Data)
- [ ] Implement
- [ ] Test

### Task 3: DiWooMetadataService — decorate payload builders + publish-time override
- **spec_ref**: `openspec/changes/woo-diwoo-publication/specs/woo-diwoo-publication/spec.md#requirement-req-woo-002-diwoo-metadata-decoration-of-publication-payloads`
- **files**: `lib/Service/DiWooMetadataService.php`, existing publication payload builder service(s), publish dialog component, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN an active mapping and configured bestuursorgaan WHEN an eligible object is published THEN the payload gains `diwoo` with informatiecategorie, `informatiecategorieBron`, bestuursorgaan (TOOI gemeente id, records-management convention), openbaarmakingsdatum = publicatiedatum, and documenthandeling (vaststelling with decision/approval date when known, else ontvangst)
  - GIVEN a staff override selected in the publish dialog WHEN published THEN `diwoo.informatiecategorie` is the override with `informatiecategorieBron: override` and the mapping object is unchanged
  - GIVEN no active mapping and no override WHEN published THEN publication succeeds without a `diwoo` block and the gap is countable
  - GIVEN the decorator installed WHEN the existing public-publication PHPUnit/Newman suites run THEN they pass unchanged (eligibility, PII stripping, immutability untouched)
- [ ] Implement
- [ ] Test

### Task 4: Woo-index sitemap endpoint (public, paginated DiWoo XML)
- **spec_ref**: `openspec/changes/woo-diwoo-publication/specs/woo-diwoo-publication/spec.md#requirement-req-woo-003-harvestable-woo-index-sitemap`
- **files**: `lib/Controller/WooIndexController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN published, `diwoo`-decorated payloads WHEN an unauthenticated client GETs the index THEN valid DiWoo sitemap XML lists exactly those entries with metadata + public resource URLs, paginated per the sitemap protocol
  - GIVEN a withdrawn publication (depublicatiedatum set) WHEN the index is fetched THEN it no longer appears
  - GIVEN unpublished/draft/deny-listed objects and payloads without `diwoo` WHEN fetched THEN none appear and no payload bodies or NC UIDs are exposed (Newman negative suite)
- [ ] Implement
- [ ] Test

### Task 5: Optional LV Woo push via OpenConnector with honest degradation
- **spec_ref**: `openspec/changes/woo-diwoo-publication/specs/woo-diwoo-publication/spec.md#requirement-req-woo-004-optional-lv-woo-push-delivery-via-openconnector`
- **files**: `lib/Service/IWooIndexConnectorService.php`, `lib/Service/WooIndexConnectorService.php`, `lib/Service/LogWooIndexConnectorService.php`, `lib/Controller/WooPushController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a configured Woo Source WHEN a decorated publication is pushed THEN the `PublicationRecord` stores delivery state + remote ack reference
  - GIVEN OpenConnector absent or Source unset WHEN publishing THEN flows are unaffected, the harvest path stands, and the admin UI states push is unavailable — no failure, no pretend delivery
  - GIVEN a push failure WHEN it errors THEN the record marks failed-retryable, staff see the failure, and re-push works; publication state never changes
- [ ] Implement
- [ ] Test

### Task 6: Coverage report — aggregations, endpoint residue, dashboard widget
- **spec_ref**: `openspec/changes/woo-diwoo-publication/specs/woo-diwoo-publication/spec.md#requirement-req-woo-005-woo-coverage-report`
- **files**: `lib/Controller/WooCoverageController.php`, `appinfo/routes.php`, `src/manifest.d/woo-diwoo.json`
- **acceptance_criteria**:
  - GIVEN seeded pre-decorator publications WHEN the coverage view loads THEN the KPI (% published objects with `diwoo`) matches the underlying objects, unmapped types link to settings, undecorated publications link to rectify
  - GIVEN an uninstalled sibling type WHEN rendered THEN it shows "type not installed", not a compliance gap
  - GIVEN the counters WHEN expressible THEN they come from `x-openregister-aggregations` in fragment 58; only the schema-existence residue is computed read-only server-side
- [ ] Implement
- [ ] Test

### Task 7: Admin mapping UI, i18n, docs
- **spec_ref**: `openspec/changes/woo-diwoo-publication/specs/woo-diwoo-publication/spec.md#requirement-req-woo-006-admin-mapping-ui`
- **files**: decidesk admin settings section (`src/settings/`), `lib/Settings/` ISettings wiring, `l10n/`, `docs/features/woo-diwoo.md`
- **acceptance_criteria**:
  - GIVEN an admin in the Woo settings section WHEN they edit mappings (via `useObjectStore`), the bestuursorgaan TOOI id (per-body override), and the push Source slug THEN all round-trip; server data flows via `IInitialState`/`loadState`; the component is NOT in vue-router (admin-router gate); no secrets stored (ADR-064)
  - GIVEN the UI WHEN strings render THEN Dutch and English exist (statutory Dutch terms with English gloss), NcSelects carry `inputLabel`, and the section meets WCAG 2.1 AA
  - GIVEN docs WHEN published THEN `docs/features/woo-diwoo.md` documents mapping, harvest URL, and push setup with a screenshot
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off; `openspec validate --strict` passes
- Register-import verified on a clean Postgres instance (fragment 58 merged; aggregations applied — no silent-ignore)
- Live verification through the UI: map type → publish with default and with override → fetch woo-index.xml unauthenticated → withdraw and re-fetch → push (and degrade path) → coverage KPI
- Public-publication regression suites green against the decorated builders
- Hydra gates green (route-auth, semantic-auth, no-admin-idor, admin-router, initial-state, notification-dialect, manifest-validation, redundant-controller)

## Quality checklist

- PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/DiWooMetadataServiceTest.php`, `WooIndexConnectorServiceTest.php`)
- Newman/Postman tests for new endpoints incl. the negative security suite (`tests/integration/decidesk-woo-diwoo.postman_collection.json`)
- Playwright browser tests for the publish-override dialog, coverage widget, and admin section
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` with screenshot (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added (ADR-005)
- `openspec validate` passes
