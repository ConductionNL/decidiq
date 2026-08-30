# Test Plan: ia-six-item-nav

## Test Cases

### TC-1: Navigation shows the six-item IA + Dashboard
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-002-mainmenu-lists-primary-entity-routes`
- **type**: functional
- **persona**: Noor (functional admin / griffier)
- **preconditions**: Decidesk installed, OpenRegister available, app in ready state
- **steps**: Open the app; inspect the left navigation
- **expected result**: Main menu shows Dashboard, Meetings, Decisions, Action items, Motions, Bodies; Beheer/Settings in the settings section; Minutes, Workspaces, Engagement are NOT top-level items
- **test command**: /test-functional

### TC-2: Bodies item routes to GovernanceBodies
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-foundation/spec.md#requirement-app-navigation-via-mainmenu`
- **type**: functional
- **persona**: Noor
- **preconditions**: App in ready state
- **steps**: Click the Bodies navigation item
- **expected result**: The GovernanceBodies page opens at `/governance-bodies` and the Bodies item is marked active
- **test command**: /test-functional

### TC-3: Demoted surfaces remain reachable by deep link
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-003-router-uses-history-mode-with-flat-named-routes`
- **type**: regression
- **persona**: —
- **preconditions**: App installed
- **steps**: Navigate directly to `/minutes`, `/workspaces`, and `/engagement`
- **expected result**: Each page renders even though it is not in the top menu; no redirect to `/`
- **test command**: /test-regression

### TC-4: organisatie_modus defaults to gov
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting`
- **type**: api
- **persona**: —
- **preconditions**: Fresh install; `organisatie_modus` never set
- **steps**: Call `GET /apps/decidesk/api/settings`
- **expected result**: Response includes `organisatie_modus = "gov"`
- **test command**: /test-api

### TC-5: Admin sets mode and persists it
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting`
- **type**: functional
- **persona**: Noor
- **preconditions**: Admin in Decidesk settings
- **steps**: Select organisation mode "corp" in the mode selector; save; reload settings
- **expected result**: `getSettings()` returns `organisatie_modus = "corp"`; the value persists across reload
- **test command**: /test-functional

### TC-6: Bodies item relabels by mode
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-006-mode-aware-label-resolution-at-the-translate-chokepoint`
- **type**: functional
- **persona**: Noor
- **preconditions**: App in ready state
- **steps**: With `organisatie_modus = gov` observe the Bodies label; set it to `corp`, reload, observe again; set `ops`, reload, observe
- **expected result**: Label reads "Fracties & Organen" (gov), "Board" (corp), "Teams" (ops); the nav structure is identical in every mode (only the label changes)
- **test command**: /test-functional

### TC-7: Mode does not create parallel entities or branch the nav
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting`
- **type**: regression
- **persona**: —
- **preconditions**: App booted under each mode
- **steps**: For each mode, inspect the register schema set and the menu item count/ids
- **expected result**: The schema set is unchanged across modes; the menu has the same six working items + Dashboard in every mode — only labels differ
- **test command**: /test-regression

### TC-8: Mode selector accessibility
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting`
- **type**: accessibility
- **persona**: —
- **preconditions**: Admin settings open
- **steps**: Run an a11y audit on the settings page mode selector
- **expected result**: The `NcSelect` has an `inputLabel`; no WCAG 2.1 AA violations on the selector
- **test command**: /test-accessibility

## Coverage Summary

- REQ-NAV-002 (MainMenu six-item IA) — covered by TC-1, TC-2.
- REQ-NAV-003 (routes; demoted surfaces reachable) — covered by TC-3.
- REQ-NAV-006 (mode-aware label resolution) — covered by TC-6, TC-7.
- app-foundation App navigation via MainMenu (MODIFIED) — covered by TC-1, TC-2.
- REQ-ADM-MODE-001 (organisatie-modus setting) — covered by TC-4, TC-5, TC-7, TC-8.

## Out of Scope

- Per-mode relabeling of menu items other than Bodies — DEFERRED in C7 (the map is
  scaffolded but only Bodies is wired); will be tested when the follow-up wires
  the remaining items.
- Folding Dashboard into Meetings — DEFERRED product decision; Dashboard remains a
  distinct landing item, so no test asserts its removal.
