# Tasks: routes-send-proposal-along-route

## Implementation tasks

### Task 1: Send for approval dialog and progress widget
- **spec_ref**: `openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page`
- **files**: `src/dialogs/SendForApprovalDialog.vue`, `src/components/tabs/DecisionApprovalTab.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - vitest red first
- [x] Implement
- [x] Test (red first)
- Built: `src/dialogs/SendForApprovalDialog.vue`, `src/components/tabs/DecisionApprovalTab.vue` (the approval chain leaf plus the Send for approval button), `src/services/approvalRouteStart.js`, the `decision-approval` widget on DecisionDetail and in the simple structure. Spec: `tests/vitest/sendForApproval.spec.js`.

### Task 2: Agenda placement waits for clearance
- **spec_ref**: `openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page`
- **files**: `src/components/tabs/MeetingAgendaTab.vue`
- **open**: an agenda item names no decision today (agenda-item has no decision reference), so there is nothing to clear on placement; the link has to be decided first.
- **acceptance_criteria**:
  - vitest red first
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
