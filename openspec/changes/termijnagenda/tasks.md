# Tasks: termijnagenda

## Implementation Tasks

### Task 1: Register fragment 50 — TermijnagendaItem schema with declarative dialects and publication predicate
- **spec_ref**: `openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister` (+ REQ-LTA-002/REQ-LTA-003/REQ-LTA-008/REQ-LTA-009)
- **files**: `lib/Settings/register.d/50-termijnagenda.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN the `termijnagenda-item` schema exists with all required fields, property titles, the `x-schema-org: schema:PlanAction` annotation, and no existing schema is modified (fragment number 50 exactly; 40–49/51–65 belong to siblings)
  - GIVEN the schema WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with `gepland → verschoven ⟲ → gerealiseerd | vervallen` and terminal states final, `vervallen` requires `redenVervallen`, ownerType `portefeuillehouder` requires an `owner` Person reference, and `plannedPeriod` rejects anything but `YYYY-Qn`/`YYYY-MM`
  - GIVEN the schema WHEN inspected THEN `x-openregister-notifications` declares the scheduled period-arrival rappel (owner + griffie, nl+en subjects, never for terminal/realised items) and no imperative dispatch exists anywhere
  - GIVEN an item with `publicatiedatum` in the past WHEN read anonymously via the OR predicate surface THEN it is returned live (including shift history); without the predicate it is not
- [ ] Implement
- [ ] Test

### Task 2: Seed data — realistic Dutch municipal termijnagenda objects
- **spec_ref**: `openspec/changes/termijnagenda/design.md#seed-data`
- **files**: `lib/Settings/register.d/50-termijnagenda.json` (seed section / `_registers.json` entries)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 5 termijnagenda items exist per the design table (one `verschoven` with a populated shift history, one `vervallen` with reason, mixed expectedType/ownerType, origin links via nil-UUID placeholders, four published) linked to seeded governance-body/meeting/person objects
  - GIVEN the seeded data WHEN the dashboard and board render THEN at least one item is overdue-open so the KPI is non-zero and the shifted card is demoable on install (ADR-016 testability)
- [ ] Implement
- [ ] Test

### Task 3: Manifest fragment — index page, detail page, menu, CSV export
- **spec_ref**: `openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-007-list-view-and-csv-export` (+ REQ-LTA-006 detail rendering)
- **files**: `src/manifest.d/termijnagenda.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN the Termijnagenda index renders with columns onderwerp/governanceBody/plannedPeriod/expectedType/owner/lifecycle and quick filters on body, lifecycle, expectedType, and period (schema referenced by slug `termijnagenda-item`, never PascalCase)
  - GIVEN a detail page WHEN opened THEN shift history, origin links (toezegging/motie/decision), and realisation links render as navigable references, and CSV export works via the mass-export dialog including shift count and reason fields
- [ ] Implement
- [ ] Test

### Task 4: Board view — per-body period columns with drag-to-reschedule and mandatory-reason dialog
- **spec_ref**: `openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-005-per-body-board-view-with-drag-to-reschedule` (+ REQ-LTA-004)
- **files**: `src/manifest.d/termijnagenda.json`, `src/dialogs/` or `src/modals/` (reschedule-reason dialog)
- **acceptance_criteria**:
  - GIVEN the board for one governance body WHEN it renders THEN items group into period columns ordered by the design D2 last-day sort key (mixed month/quarter granularity sorts chronologically)
  - GIVEN a card dragged to another period column WHEN the reason dialog is confirmed THEN one PUT-semantic saveObject sets lifecycle `verschoven`, updates `plannedPeriod`, and appends `{van, naar, reden, door, op}` to `verschuifHistorie` (existing entries unchanged); WHEN cancelled THEN the card restores and zero requests are issued
  - GIVEN a keyboard-only user WHEN they invoke the card's reschedule action THEN the same reschedule completes without dragging (WCAG 2.2 SC 2.5.7); a repeat shift appends a second history entry preserving the first
- [ ] Implement
- [ ] Test

### Task 5: Realisation and vervallen flows — assistive linking, never auto-scheduling
- **spec_ref**: `openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision` (+ REQ-LTA-003)
- **files**: `src/dialogs/` or `src/modals/` (realise dialog, vervallen dialog), detail-page action wiring
- **acceptance_criteria**:
  - GIVEN an open item WHEN the griffier marks it `gerealiseerd` THEN the dialog suggests matching agenda items of the same body (standard OR list query), the confirmed link sets `realisedAgendaItem`/`realisedDecision`, and no meeting or agenda item is ever created or modified by the flow
  - GIVEN an open item WHEN a user marks it `vervallen` without a reason THEN the save is rejected; with a reason THEN the item is terminal and further lifecycle edits are refused by the OR transition map
- [ ] Implement
- [ ] Test

### Task 6: Dashboard KPI — overdue termijnagenda items
- **spec_ref**: `openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-010-dashboard-kpi-for-overdue-termijnagenda-items`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN the "Termijnagenda over termijn" stat widget counts non-terminal unrealised items whose period's last day is past, via a declarative source aggregation, and clicking routes to the pre-filtered Termijnagenda index
  - GIVEN the widget filter DSL lacks a relative-now token WHEN implementing THEN the documented D6 fallback is applied (never a silently wrong count) and the design open question is resolved in the PR, aligned with the toezeggingen change's KPI token
- [ ] Implement
- [ ] Test

### Task 7: E2E coverage — Playwright scenarios for the termijnagenda register
- **spec_ref**: `openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md`
- **files**: `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude` (the notification-dialect convention scenario is already excluded in-spec)
  - GIVEN the seeded environment WHEN the e2e suite runs THEN plan → drag-reschedule (reason + history) → realise-with-suggestion and plan → vervallen-with-reason pass end-to-end, the cancel path issues no write, and the published item is readable anonymously while an unpublished one is not
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — note: no new PHP services/controllers are expected (thin client); fragment and manifest validation coverage applies
- New/changed API endpoints covered by Newman/Postman tests — N/A expected (no new app routes; OR predicate surface covered by e2e anonymous-read checks)
- UI changes covered by Playwright browser tests (board, dialogs, index, detail, KPI)
- All tests pass (`composer test`); `composer check:strict` clean
- Feature documentation updated in `docs/features/termijnagenda.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle; modal-isolation for the new dialogs)
- `openspec validate` passes
