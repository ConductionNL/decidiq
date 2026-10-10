# Tasks: agenda-templates-and-copy

## Implementation tasks

### Task 1: Start from a template
- **spec_ref**: `openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template`
- **files**: `lib/Settings/register.d/`, `src/dialogs/AgendaCopyDialog.vue`, `src/components/tabs/MeetingAgendaTab.vue`
- **acceptance_criteria**:
  - GIVEN template Raadsvergadering with 6 items WHEN the secretary starts a new agenda from it THEN the meeting has those 6 items in order
- [x] Implement
- [x] Test (red first)

### Task 2: Copy from an earlier meeting
- **spec_ref**: `openspec/changes/agenda-templates-and-copy/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting`
- **files**: `src/dialogs/AgendaCopyDialog.vue`, `lib/Service/MeetingSeriesService.php`
- **acceptance_criteria**:
  - GIVEN an earlier meeting WHEN the secretary picks two of its items THEN both are added after the last item
  - GIVEN a series generated from a meeting THEN each new meeting has its agenda
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
