# Tasks: followup-implementation-progress

## Implementation tasks

### Task 1: ImplementationUpdate schema, decision status fields and the 90-day rule
- **spec_ref**: `openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-001-an-adopted-decision-takes-dated-progress-updates`
- **files**: `lib/Settings/register.d/92-implementation-progress.json`, `lib/Settings/profiles/municipality.json`, `lib/Settings/profiles/association.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `implementation-update` exists and `decision` carries `implementationStatus` and `implementationStatusDate`
  - GIVEN the notification rule WHEN `RegisterJsonTest::testNotificationTriggersUseCanonicalVocabulary` runs THEN it passes and gate-18 is green
- [ ] Implement
- [ ] Test (schema and register tests)

### Task 2: Only adopted decisions take updates, and the newest sets the status
- **spec_ref**: `openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status`
- **files**: `lib/Listener/ImplementationUpdateListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`, `tests/Unit/Listener/ImplementationUpdateListenerTest.php`
- **acceptance_criteria**:
  - GIVEN a rejected motion WHEN the secretariat adds an update THEN it is refused with 422
  - GIVEN an adopted motion WHEN an update dated 2026-06-15 `delayed` is added after one dated 2026-03-01 THEN the decision reads `delayed`, 2026-06-15
  - GIVEN a back-dated update WHEN added THEN the decision's status does not change
- [ ] Implement
- [ ] Test (construct OpenRegister's real `ObjectCreatingEvent` and `ObjectCreatedEvent`)

### Task 3: Implementation widget on decision and motion pages
- **spec_ref**: `openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-001-an-adopted-decision-takes-dated-progress-updates`
- **files**: `src/manifest.json` (`DecisionDetail`, `MotionDetail`)
- **acceptance_criteria**:
  - GIVEN an adopted motion WHEN the griffier opens the motion page THEN the Implementation widget lists its updates newest first with Add update
  - GIVEN a council member WHEN he opens it THEN he reads the updates without Add update
  - GIVEN `tests/validate-manifest.js` WHEN run THEN it passes
- [ ] Implement
- [ ] Test (Playwright adds an update and sees the status change)

### Task 4: Lists show and filter the status
- **spec_ref**: `openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-003-the-motions-and-decisions-lists-filter-on-implementation-status`
- **files**: `src/manifest.json` (`Motions`, `Decisions`)
- **acceptance_criteria**:
  - GIVEN three adopted motions, one delayed WHEN the griffier filters Motions on delayed THEN one row remains
- [ ] Implement
- [ ] Test (Playwright)

### Task 5: The silence reminder reaches the secretariat
- **spec_ref**: `openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-004-the-secretariat-is-reminded-when-nobody-reported-for-90-days`
- **files**: `tests/newman/implementation-progress.json`
- **acceptance_criteria**:
  - GIVEN an adopted motion with its last update 100 days ago WHEN OpenRegister's scheduled notification run fires THEN members of the secretariat get "No progress reported" with the motion title
- [ ] Implement
- [ ] Test (Newman against a seeded instance; this is the only proof the declared rule fires)

## Verification

- `composer check:strict` and `npm run lint` once before push.
