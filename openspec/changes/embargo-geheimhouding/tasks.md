# Tasks: embargo-geheimhouding

## Implementation Tasks

### Task 1: Register fragment 65 — schemas + declarative dialects + embargo properties
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground`
- **files**: `lib/Settings/register.d/65-embargo-geheimhouding.json`, `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported on a clean instance WHEN schemas are listed THEN `geheimhouding` and `geheimhouding-grond` exist with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys: `opgelegd → bekrachtigd → opgeheven`, direct `opgelegd → opgeheven` guarded by the ground's bekrachtiging requirement, `opgeheven` terminal), aggregations, notifications (ADR-031 dialect), and relations
  - GIVEN existing DigitalDocument objects WHEN the additive `embargoUntil`/`embargoActive`/`embargoAudience` properties are imported into the canonical file THEN existing objects validate unchanged
  - GIVEN a geheimhouding whose ground requires bekrachtiging WHEN a direct `opgelegd → opgeheven` transition is attempted THEN OR rejects it per the declared map
- [ ] Implement
- [ ] Test

### Task 2: Seed data — grounds with dual article labels + example geheimhoudingen + embargo documents
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-002-configurable-ground-list-with-dual-gemeentewet-article-labels`
- **files**: `lib/Settings/register.d/65-embargo-geheimhouding.json` (`x-openregister-seeds`)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seeds import THEN 8 GeheimhoudingGronden exist (Gemeentewet art. 87-89 with legacy labels voorheen art. 25/55/86, Woo 5.1 absolute + relative, one `overig` statutory ground), each `builtIn: true` and admin-editable; deactivating a ground hides it from the picker but keeps it resolvable on existing records
  - GIVEN the seeds WHEN the register overview loads THEN 3 Geheimhoudingen (awaiting bekrachtiging, OVERDUE bekrachtiging, opgeheven-awaiting-publication) and 2 embargo documents (locked future / released past) make every KPI and state visible on install (ADR-016)
- [ ] Implement
- [ ] Test

### Task 3: GeheimhoudingService — impose with classifier drive + authority guards
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground`
- **files**: `lib/Service/GeheimhoudingService.php`, `lib/Controller/GeheimhoudingController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a griffie user WHEN a geheimhouding is imposed on an AgendaItem/Decision/DigitalDocument THEN the record stores ground, imposedBy, imposedAt, scope + target UUID, and the target's EXISTING classifier is driven (`confidentiality: confidential` / `isPublished: "confidential"`), carrying ALL other target fields forward (PUT-semantic save)
  - GIVEN a user without organ authority WHEN impose/bekrachtig/opheffen is called THEN 403 (per-object guard in the method body)
  - GIVEN no resolvable ground WHEN impose is called THEN it is refused — no geheimhouding without a structured ground
- [ ] Implement
- [ ] Test

### Task 4: Bekrachtiging workflow — agenda placement, besluit link, fail-visible overdue
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag`
- **files**: `lib/Service/GeheimhoudingService.php`, `lib/Controller/GeheimhoudingController.php`
- **acceptance_criteria**:
  - GIVEN a ground with `requiresBekrachtiging: true` WHEN the geheimhouding is imposed THEN an AgendaItem referencing it is created on the confirming body's next scheduled meeting and `bekrachtigingDeadline` defaults to that meeting's date (manually overridable)
  - GIVEN a linked bekrachtigingsbesluit Decision WHEN bekrachtig is called THEN state becomes `bekrachtigd` with besluit UUID + timestamp; WHEN the ground needs no bekrachtiging THEN 409
  - GIVEN a passed deadline without besluit WHEN the register is viewed THEN the geheimhouding is flagged overdue, its state remains `opgelegd`, and the target remains confidential (never auto-lift)
- [ ] Implement
- [ ] Test

### Task 5: Opheffing workflow — besluit + conditions, eligibility restored, never auto-public
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery`
- **files**: `lib/Service/GeheimhoudingService.php`, `lib/Controller/GeheimhoudingController.php`
- **acceptance_criteria**:
  - GIVEN an opheffingsbesluit by the imposing/confirming body WHEN opheffen is called THEN state becomes `opgeheven` storing besluit UUID, date, and optional conditions, and the target classifier returns to its non-public default (`internal`), audit-trailed
  - GIVEN an opgeheven geheimhouding WHEN no griffie member has run the publish flow THEN the target appears on no public surface; WHEN the normal publish flow runs THEN it accepts the target
- [ ] Implement
- [ ] Test

### Task 6: EmbargoReleaseJob + publication guard
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release`
- **files**: `lib/BackgroundJob/EmbargoReleaseJob.php`, `lib/AppInfo/Application.php`, `lib/Service/` (publication payload/eligibility guard extension)
- **acceptance_criteria**:
  - GIVEN a document with `embargoActive: true` and past `embargoUntil` WHEN the 15-minute TimedJob runs THEN `embargoActive` flips to false via the OR object API (only matching documents queried), wider members can read it, and the declarative release notification fires on the flip — entitled-audience access was never interrupted
  - GIVEN a future `embargoUntil` WHEN a regular member requests the document THEN access is denied while the entitled audience reads it
  - GIVEN a target under a geheimhouding in `opgelegd`/`bekrachtigd` WHEN a publish request runs THEN payload construction is structurally refused before eligibility evaluation, naming the blocking geheimhouding + ground citation (public-publication eligibility-gates requirement itself unmodified); GIVEN the geheimhouding lookup fails THEN publication is refused, never allowed
- [ ] Implement
- [ ] Test

### Task 7: View audit trail for stukken under geheimhouding
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding`
- **files**: `lib/Service/GeheimhoudingService.php`, `lib/Controller/GeheimhoudingController.php`, document read/download path
- **acceptance_criteria**:
  - GIVEN a document under active geheimhouding WHEN a member reads/downloads it through the app THEN an audit entry (actor NC UID, timestamp, document UUID, geheimhouding UUID) lands in the OR audit trail (append-only, never mutated)
  - GIVEN the geheimhouding detail view WHEN griffie/staff open the views tab THEN the audit list renders per geheimhouding; non-staff get 403 on the views endpoint
  - GIVEN an opgeheven geheimhouding WHEN the document is viewed THEN no special audit entry is written
- [ ] Implement
- [ ] Test

### Task 8: Manifest fragment — register pages, KPI widgets, dialogs, i18n, docs
- **spec_ref**: `openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi`
- **files**: `src/manifest.d/embargo-geheimhouding.json`, `src/dialogs/GeheimhoudingImposeDialog.vue`, `src/dialogs/GeheimhoudingOpheffenDialog.vue`, `l10n/`, `docs/features/embargo-geheimhouding.md`
- **acceptance_criteria**:
  - GIVEN the manifest fragment (schema refs by slug: `geheimhouding`, `geheimhouding-grond`) WHEN the app loads THEN the geheimhoudingenregister per body renders target, ground (citation + legacy label), imposedBy, lifecycle, bekrachtiging status, with KPI widgets (active, awaiting/overdue bekrachtiging, awaiting publication) sourced from declarative aggregations
  - GIVEN the seeded overdue geheimhouding WHEN the overview loads THEN the overdue KPI is non-zero and links to the filtered list
  - GIVEN the dialogs WHEN rendered THEN they live in their own files, the ground NcSelect has `inputLabel`, Dutch and English strings exist (statutory terms keep Dutch names with English gloss), and pages meet WCAG 2.1 AA
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off; `openspec validate` passes
- Register-import verified on a clean Postgres instance (lifecycle dialect actually applied — no silent-ignore)
- Live verification through the UI: impose → agenda placement → bekrachtig → embargo flip (job run) → opheffing → normal publish flow; overdue KPI visible from seeds
- Hydra gates green (route-auth, semantic-auth, no-admin-idor, notification-dialect, manifest-validation, redundant-controller, orphaned-capability)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests (`tests/integration/decidiq-embargo-geheimhouding.postman_collection.json`)
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` (ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) translation strings added (ADR-005)
- `openspec validate` passes
