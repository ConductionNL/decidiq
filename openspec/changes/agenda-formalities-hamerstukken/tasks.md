# Tasks: agenda-formalities-hamerstukken

## Implementation tasks

### Task 1: Mark an item as formality
- **spec_ref**: `openspec/changes/agenda-formalities-hamerstukken/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together`
- **files**: `lib/Settings/register.d/`, `src/components/tabs/MeetingAgendaTab.vue`
- **acceptance_criteria**:
  - GIVEN an agenda WHEN the secretary marks item 4 as formality THEN it shows the formality label
- [ ] Implement
- [ ] Test (red first)

### Task 2: Adopt formalities together
- **spec_ref**: `openspec/changes/agenda-formalities-hamerstukken/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together`
- **files**: `lib/Service/AgendaService.php`, `src/views/LiveMeeting.vue`
- **acceptance_criteria**:
  - GIVEN three formalities WHEN the chair adopts them together THEN each records adopted without debate and the time
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
