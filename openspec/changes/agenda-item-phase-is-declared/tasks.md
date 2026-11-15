# Tasks: agenda-item-phase-is-declared

## Implementation Tasks

### Task 1: Declare the phase and move existing values
- **spec_ref**: `openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase`
- **files**: `lib/Settings/register.d/129-agenda-item-phase.json` (`AgendaItem.phase`, enum, facetable), `lib/Repair/MoveAgendaItemStatusToPhase.php` (new, registered in `appinfo/info.xml` post-migration), `lib/Service/AgendaService.php` (`advanceBobPhase()` reads and patches `phase`), `tests/Unit/Service/AgendaServiceTest.php`, `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `AgendaItem` declares `phase` with the five values
  - GIVEN an item without a phase WHEN advanced THEN it moves from `beeldvorming` to `oordeelsvorming`
  - GIVEN an item with `status: besluitvorming` WHEN the repair step runs twice THEN `phase` is `besluitvorming` and the second run changes nothing
- [ ] Implement
- [ ] Test

### Task 2: Show the phase on the agenda item page
- **spec_ref**: `openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-011-the-agenda-item-page-shows-the-phase`
- **files**: `src/manifest.json` (`AgendaItemDetail` widget), `src/components/tabs/AgendaItemPhaseTab.vue` (new, `CnTimelineStages`), `src/registry.js`, `src/views/LiveMeeting.vue` (read `phase`), `tests/vitest/agendaItemPhase.spec.js`
- **acceptance_criteria**:
  - GIVEN a decision item WHEN its page opens THEN the stage line marks its phase
  - GIVEN an informational item WHEN its page opens THEN no stage line shows
- [ ] Implement
- [ ] Test
