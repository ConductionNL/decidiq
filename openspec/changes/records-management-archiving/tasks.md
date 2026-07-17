# Tasks: records-management-archiving

## Implementation Tasks

### Task 1: Register fragment — schemas + declarative dialects
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly`
- **files**: `lib/Settings/register.d/44-records-management-archiving.json`, `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported on a clean instance WHEN schemas are listed THEN `archival-dossier`, `retention-rule`, `transfer-package`, `destruction-list` exist with `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys), aggregations, calculations, notifications (ADR-031 dialect), and relations
  - GIVEN existing Minutes/Decision/Meeting/DigitalDocument objects WHEN the additive `mdto` and `securityClassification` properties are imported THEN existing objects validate unchanged (classification defaults to `openbaar`)
  - GIVEN the dossier lifecycle WHEN a transition outside the declared map is attempted THEN OR rejects it
- [ ] Implement
- [ ] Test

### Task 2: Seed data — Selectielijst rules + example dossiers/packages/lists
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-schedules-per-selectielijst-gemeenten-2020`
- **files**: `lib/Settings/register.d/44-records-management-archiving.json` (`x-openregister-seeds`)
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seeds import THEN 4 RetentionRules (Selectielijst 2020 categories 2.1/3.1 V, 19.1/11.1 B), 3 ArchivalDossiers (forming/closed/closed-due, per design Seed Data), 2 TransferPackages (delivered/ready), and 2 DestructionLists (proposed/executed with verklaring reference) exist with `@self` envelopes and slug cross-references
  - GIVEN the seeded due `B`-dossier WHEN the compliance dashboard loads THEN the due-for-destruction and completeness counters are non-zero (seeds make the feature testable on install, ADR-016)
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

### Task 4: MDTO mapping + TransferPackageService
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-package-generation`
- **files**: `lib/Service/TransferPackageService.php`, `lib/Controller/TransferPackageController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN closed transfer-marked dossiers WHEN a package is built THEN MDTO-XML sidecars exist for dossier + every member (item-level records derived from object properties + OR metadata when no manual `mdto` override) and the package reaches `ready` after validation
  - GIVEN a dossier missing mandatory MDTO fields (e.g. waardering) WHEN the build runs THEN state becomes `failed-validation` with stored errors and delivery is refused
- [ ] Implement
- [ ] Test

### Task 5: ArchiveConnectorService — OpenConnector delivery + honest degradation
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-transfer-delivery-via-openconnector`
- **files**: `lib/Service/IArchiveConnectorService.php`, `lib/Service/ArchiveConnectorService.php`, `lib/Service/LogArchiveConnectorService.php`, `lib/Controller/TransferPackageController.php`
- **acceptance_criteria**:
  - GIVEN a configured archive Source WHEN delivery runs THEN the package becomes `delivered` with the remote ack stored and contained dossiers transition to `transferred`
  - GIVEN OpenConnector absent or Source unconfigured WHEN staff view a `ready` package THEN a zip download (documents + sidecars) is offered and the UI states automatic delivery is unavailable; no dossier state changes
  - GIVEN a delivery failure WHEN the call errors THEN the failure surfaces to staff, is retryable, and no dossier transitions
- [ ] Implement
- [ ] Test

### Task 6: Destruction workflow — propose, authorize (SoD), execute
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-destruction-workflow-with-separated-authorization`
- **files**: `lib/Service/DestructionService.php`, `lib/Controller/DestructionController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN dossiers past their `B` disposition date WHEN a proposal is created THEN the list freezes exactly those dossiers + member UUIDs; unexpired/`V` dossiers are ineligible
  - GIVEN a list proposed by user A WHEN A attempts authorization THEN 403 separation-of-duties; WHEN authorized user B authorizes THEN actor + timestamp recorded
  - GIVEN an authorized list WHEN executed THEN only enumerated objects are deleted via OR retention-aware `deleteObject` and affected dossiers become `destroyed`
- [ ] Implement
- [ ] Test

### Task 7: Vernietigingsverklaring + RetentionSweepJob
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-vernietigingsverklaring-destruction-verification-report`
- **files**: `lib/Service/DestructionService.php`, `lib/BackgroundJob/RetentionSweepJob.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN an execution with one per-object failure WHEN it completes THEN the verklaring records dossiers, categories, proposer/authorizer + timestamps, per-object results including the failure, is rendered (Docudesk PDF, markdown fallback), carries permanent retention, and is never destruction-eligible
  - GIVEN assigned retention rules WHEN the daily sweep runs THEN resolved dispositions are written to OR `_retention` via the object API only, and approaching 10-year transfer deadlines trigger the declarative notification
- [ ] Implement
- [ ] Test

### Task 8: Security classification integration
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-009-security-classification-labels-on-archival-records`
- **files**: `lib/Service/PublicationEligibilityService.php`, `lib/Service/TransferPackageService.php`
- **acceptance_criteria**:
  - GIVEN a `vertrouwelijk` object WHEN a publish request targets it THEN the payload builder refuses structurally before eligibility evaluation
  - GIVEN a classified dossier WHEN a package is built THEN MDTO `beperkingGebruik` reflects the classification and the package records its highest contained classification
  - GIVEN a dossier less restrictive than a member WHEN viewed THEN the computed-classification warning surfaces
- [ ] Implement
- [ ] Test

### Task 9: Manifest fragment — pages, compliance dashboard, dialogs, i18n, docs
- **spec_ref**: `openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-archive-completeness-and-compliance-dashboard`
- **files**: `src/manifest.d/records-management.json`, `src/dialogs/DestructionAuthorizeDialog.vue`, `l10n/`, `docs/features/records-management.md`
- **acceptance_criteria**:
  - GIVEN the manifest fragment (schema refs by slug) WHEN the app loads THEN dossier index/detail pages, destruction-list pages, and dashboard widgets render with counters from declarative aggregations (dossiers per state, overdue transfers, unassigned retention, meetings without dossier, pending destructions)
  - GIVEN the seeded overdue/gap data WHEN the dashboard is viewed THEN counters match the underlying objects and link to filtered lists
  - GIVEN the UI WHEN strings render THEN Dutch and English are available (statutory Dutch terms kept with English gloss) and pages meet WCAG 2.1 AA
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off; `openspec validate` passes
- Register-import verified on a clean Postgres instance (lifecycle dialect actually applied — no silent-ignore)
- Live verification: form → close → package → deliver (and degrade path) → propose → authorize → execute → verklaring, through the UI
- Hydra gates green (route-auth, semantic-auth, no-admin-idor, notification-dialect, manifest-validation, redundant-controller)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests (`tests/integration/decidesk-records-management.postman_collection.json`)
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/` (ADR-010) with screenshot
- Dutch (`nl_NL`) and English (`en_US`) translation strings added (ADR-005/ADR-007)
- `openspec validate` passes
