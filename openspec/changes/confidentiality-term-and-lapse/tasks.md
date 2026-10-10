# Tasks: confidentiality-term-and-lapse

Build after `confidentiality-in-plain-words` (schema names) and with or after `embargo-geheimhouding` REQ-EMB-003/004 (ratification and lifting workflow).

## Implementation tasks

### Task 1: Fields and the lapsed state
- **spec_ref**: `openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date`, `#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses`
- **files**: `lib/Settings/register.d/129-confidentiality-term-and-lapse.json` (next free number at build time, `slug: confidentiality-restriction`)
- **acceptance_criteria**:
  - GIVEN the import WHEN the restriction schema is read THEN `imposingDecision`, `liftBy`, `sentToBodyAt`, `sentWithAgendaItem` exist, optional; the lifecycle has `lapsed` (from imposed, terminal) in the canonical dialect; no `PARTIAL IMPORT`
  - GIVEN liftBy before imposedAt WHEN saved THEN refused (save-time check, OR rejects allOf)
- [ ] Implement
- [ ] Test

### Task 2: Lapse on meeting close
- **spec_ref**: `#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses`
- **files**: `lib/Listener/ConfidentialityLapseListener.php`, `lib/AppInfo/Application.php`, agenda item outcome "niet bekrachtigd" / "uitgesteld" in the agenda item form
- **acceptance_criteria**:
  - the four scenarios of REQ-EMB-021 pass with the real meeting lifecycle event
  - GIVEN a lapsed decision-scoped restriction WHEN `ConfidentialityRestrictions::isDecisionRestricted()` runs THEN false, and the decision still needs the normal publish flow
  - audit entry carries meeting and agenda item; a notification rule in the ADR-031 dialect fires on `lapsed`
- [ ] Implement
- [ ] Test

### Task 3: End-date job
- **spec_ref**: `#requirement-req-emb-022-the-planned-end-date-puts-the-lifting-on-the-agenda`
- **files**: `lib/BackgroundJob/ConfidentialityLiftByJob.php` (daily TimedJob), admin setting `confidentialityLiftLeadDays` (default 30), `lib/Service/` agenda placement reusing the ratification placement of embargo-geheimhouding
- **acceptance_criteria**:
  - GIVEN liftBy within the lead time WHEN the job runs THEN one lifting agenda item exists on the next meeting; running again adds none
  - GIVEN liftBy passed WHEN the register loads THEN the restriction shows overdue; its lifecycle is unchanged
  - the job queries only restrictions with liftBy set and state imposed or ratified
- [ ] Implement
- [ ] Test

### Task 4: Detail page per board DcGeheimhouding
- **spec_ref**: `#requirement-req-emb-023-the-detail-page-shows-the-four-steps-of-the-board`, `#requirement-req-emb-020-a-restriction-records-its-imposing-decision-and-planned-end-date`
- **files**: `src/manifest.d/embargo-geheimhouding.json`, `buildConfidentialityStages` in `src/components/widgets/registerDetailWidgets.js`, `tests/vitest/registerDetailWidgets.spec.js`, `l10n/`
- **acceptance_criteria**:
  - GIVEN the example restriction WHEN its page opens THEN header, stepper, four-step timeline, Geheimhouding card, Waar de geheimhouding op ligt, Grond card and Gerelateerd match the board
  - GIVEN a lapsed restriction WHEN its page opens THEN the stepper and timeline show Vervallen
- [ ] Implement
- [ ] Test (vitest per stage; one Playwright test on the example)

### Task 5: Align embargo-geheimhouding REQ-EMB-003 and example data
- **files**: `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the example set WHEN loaded THEN the board's restriction and one lapsed restriction exist
  - the REQ-EMB-003 text of embargo-geheimhouding names the lapse of REQ-EMB-021 as its one exception (done in the spec PR that added this change)
- [ ] Implement
- [ ] Test
