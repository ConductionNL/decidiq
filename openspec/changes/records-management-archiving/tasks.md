# Tasks: records-management-archiving

> **Consumption-first.** OpenRegister already ships retention resolution, Selectielijst storage, MDTO serialization, SIP packaging, e-depot transfer, destruction (dual approval + legal holds), and the `verklaring_van_vernietiging` certificate. None of that is re-implemented here. Target `retention` / `tmlo` — **never** `_retention` (transient, read-only) or `_tmlo` (does not exist).

## Implementation Tasks

### Task 1: Register fragment — ArchivalDossier schema, archive config, classification
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly`
- **files**: `lib/Settings/register.d/44-records-management-archiving.json`, `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported on a clean instance WHEN schemas are listed THEN `archival-dossier` exists with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys), aggregations, calculations, notifications (ADR-031 dialect), and relations — and no `retention-rule`, `transfer-package`, or `destruction-list` schema is created
  - GIVEN archivable schemas WHEN their `archive` config (`enabled`, `classificatie`, afleidingswijze where needed) is imported THEN OR's `RetentionService::applyArchivalMetadata()` populates the persisted `retention` field on save
  - GIVEN existing Minutes/Decision/Meeting/DigitalDocument objects WHEN the additive `securityClassification` property is imported THEN existing objects validate unchanged (defaults to `openbaar`)
- [ ] Implement
- [ ] Test

### Task 2: Seed data — Selectielijst 2020 as OR SelectionList objects + example dossiers
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice`
- **files**: `lib/Settings/register.d/44-records-management-archiving.json` (`x-openregister-seeds`)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seeds import THEN 4 OpenRegister `SelectionList` objects exist (categories 2.1/3.1 bewaren, 19.1/11.1 vernietigen, per design Seed Data) — no decidiq-local retention-rule objects
  - GIVEN the 3 seeded ArchivalDossiers (forming/closed/closed-due) WHEN they are saved THEN OR resolves `retention.classificatie`, `.archiefnominatie`, and `.archiefactiedatum` — the seeds MUST NOT author those values
  - GIVEN the seeded due dossier WHEN the compliance dashboard loads THEN the due-for-destruction and completeness counters are non-zero (seeds make the feature testable on install, ADR-016)
- [ ] Implement
- [ ] Test

### Task 3: ArchivalDossierService — assembly, completeness, close
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly`
- **files**: `lib/Service/ArchivalDossierService.php`, `lib/Controller/ArchivalDossierController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a completed meeting WHEN assemble runs THEN the dossier enumerates approved minutes, all linked decisions (incl. decisionType variants), voting rounds, and attachments by UUID
  - GIVEN a forming dossier with unapproved minutes WHEN close is called without override THEN it is refused naming the gap; WHEN called with an override reason THEN it closes and stores the reason
  - GIVEN a closed dossier WHEN a member mutation is attempted THEN it is rejected
- [ ] Implement
- [ ] Test

### Task 4: MDTO fields + transfer/destruction hand-off to OpenRegister
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot`
- **files**: `lib/Service/ArchivalDossierService.php`, `lib/Controller/ArchivalDossierController.php`
- **acceptance_criteria**:
  - GIVEN a dossier being closed WHEN it is saved THEN its OR `tmlo` field carries MDTO descriptive metadata and OR's `MdtoXmlGenerator` serves its MDTO record — decidiq contains no MDTO mapping table, serializer, or item-level derivation
  - GIVEN a transfer-routed dossier and a configured OR e-depot transport WHEN transfer is triggered THEN decidiq creates an OR transfer list over the member UUIDs via `TransferListService` and OR performs packaging/delivery; on OR success the dossier becomes `transferred`
  - GIVEN an unconfigured OR e-depot WHEN staff open a transfer-routed dossier THEN the UI states automated transfer is unavailable and points to OR's e-depot settings; no dossier state change and no decidiq-side package
  - GIVEN a dossier past its `retention.archiefactiedatum` with a destruction nominatie WHEN destruction is proposed THEN it is an OR destruction list approved via OR's routes; decidiq implements no approval, deletion, or sweep job; the dossier reflects `destroyed` on OR execution
- [ ] Implement
- [ ] Test

### Task 5: Vernietigingsverklaring rendering + security classification
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering`
- **files**: `lib/Service/ArchivalDossierService.php`, `lib/Service/PublicationEligibilityService.php`
- **acceptance_criteria**:
  - GIVEN an OR destruction execution has produced a `verklaring_van_vernietiging` certificate WHEN decidiq renders it THEN it is fetched from OR's certificates route, rendered (Docudesk PDF, markdown fallback), persisted with permanent retention, never destruction-eligible, and OR-reported skipped objects are visible — decidiq does not compose or re-derive the certificate
  - GIVEN a `vertrouwelijk` object WHEN a publish request targets it THEN the payload builder refuses structurally before eligibility evaluation
  - GIVEN a dossier classification WHEN it is validated THEN it maps onto OR's `VERTROUWELIJKHEIDAANDUIDING_LEVELS` at the same relative position, and a dossier less restrictive than a member surfaces the computed-classification warning
- [ ] Implement
- [ ] Test

### Task 6: Manifest fragment — pages, compliance dashboard, i18n, docs
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard`
- **files**: `src/manifest.d/records-management.json`, `l10n/`, `docs/features/records-management.md`
- **acceptance_criteria**:
  - GIVEN the manifest fragment (schema refs by slug) WHEN the app loads THEN dossier index/detail pages and dashboard widgets render with counters from declarative aggregations (dossiers per state, overdue transfers, unresolved retention, meetings without dossier, pending OR destruction lists)
  - GIVEN the seeded overdue/gap data WHEN the dashboard is viewed THEN counters read OR's `retention.archiefactiedatum` / `.archiefstatus`, match the underlying objects, and link to filtered lists
  - GIVEN the UI WHEN strings render THEN Dutch and English are available (statutory Dutch terms kept with English gloss) and pages meet WCAG 2.1 AA
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off; `openspec validate` passes
- Register-import verified on a clean Postgres instance (lifecycle dialect actually applied — no silent-ignore; `retention` actually resolved by OR — no phantom seed values)
- Live verification through the UI: form → close → OR transfer list → transfer (and degrade path) → OR destruction list → OR approval → OR execution → verklaring rendered
- Grep-verified: no decidiq code writes `_retention`, references `_tmlo`, computes an archiefactiedatum, serializes MDTO, or deletes a member object
- Hydra gates green (route-auth, semantic-auth, no-admin-idor, notification-dialect, manifest-validation, redundant-controller — the last one matters most here)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests (`tests/integration/decidiq-records-management.postman_collection.json`)
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` (ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) translation strings added (ADR-005/ADR-007)
- `openspec validate` passes
