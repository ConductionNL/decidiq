# Tasks: meeting-facet-composition

## Implementation Tasks

### Task 1: Add the three declarative object-list facets to MeetingDetail
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-009-oral-questions-vragenuur-facet`, `#requirement-req-mdv-010-interpellations-facet`, `#requirement-req-mdv-011-proxy-authorizations-voting-facet`
- **files**: `src/manifest.json` (page `MeetingDetail`: `widgets[]`, `layout[]`)
- **acceptance_criteria**:
  - GIVEN a meeting with mondelinge-vraag/proxyAuthorization objects targeting it WHEN its detail page loads THEN the oral-questions and proxy-authorizations facets list them and offer add-in-context pre-filled to this meeting
  - GIVEN a meeting with interpellatieverzoek objects whose behandeldIn is this meeting WHEN its detail page loads THEN the interpellations facet lists them with no create button
  - GIVEN `npm run check:manifest` and `npm run check:nav-ceiling` WHEN run after this task THEN both pass unchanged (no new nav entries, valid manifest structure)
- [x] Implement
- [x] Test

### Task 2: Kascommissie mode-gated facet
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-kascommissie-verklaringen-facet-assoc-mode-only`
- **files**: `src/components/tabs/MeetingKascommissieTab.vue` (new), `src/manifest.json` (widget + slot entry), `src/registry.js`
- **acceptance_criteria**:
  - GIVEN `organisatie_modus` is `assoc` and the meeting's governanceBody has a kascommissie-verklaring WHEN the detail page loads THEN the facet renders the statement (no create button)
  - GIVEN `organisatie_modus` is `gov`/`corp`/`ops` WHEN the detail page loads THEN the facet renders no content and no other widget's layout shifts unexpectedly
- [x] Implement
- [x] Test

### Task 3: Routed incoming-documents facet (two-hop, read-only)
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-013-routed-incoming-documents-facet-read-only`
- **files**: `src/components/tabs/MeetingRoutedDocumentsTab.vue` (new), `src/manifest.json` (widget + slot entry), `src/registry.js`
- **acceptance_criteria**:
  - GIVEN a meeting's own agenda items WHEN the detail page loads THEN the facet lists every raadsinformatiebrief whose agendaItem, and every ingekomen-stuk whose targetAgendaItem/listAgendaItem, resolves to one of those agenda items
  - GIVEN a raadsinformatiebrief/ingekomen-stuk not routed to any of this meeting's agenda items WHEN the detail page loads THEN it does not appear in the facet
  - GIVEN the facet WHEN rendered THEN it offers no create affordance
- [x] Implement
- [x] Test

### Task 4: Layout placement + i18n strings for all 5 facets
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#non-functional-requirements`
- **files**: `src/manifest.json` (layout entries per design.md's Layout table), i18n source files for `decidesk` (`nl_NL` + `en_US`)
- **acceptance_criteria**:
  - GIVEN the 5 new widgets WHEN the layout is applied THEN none overlap and each fits its declared gridWidth/gridHeight without clipping
  - GIVEN every new facet title, column label, and empty-state string WHEN the app renders in `nl_NL` THEN no string falls back to its English source key
- [x] Implement
- [x] Test

### Task 5: Mode-gate and two-hop-join test coverage
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#scenario-kascommissie-facet-hidden-outside-association-mode`, `#scenario-documents-routed-onto-the-meetings-agenda`
- **files**: `tests/vitest/meetingKascommissieVisibility.spec.js` (new), `tests/vitest/routedDocumentsJoin.spec.js` (new) — see DEVIATION note below for why the path/shape differs from what this task originally named
- **acceptance_criteria**:
  - GIVEN `organisatie_modus` toggled between `assoc` and every other mode WHEN `MeetingKascommissieTab` is mounted THEN it renders content only in `assoc` mode
  - GIVEN a mocked meeting with 2 agenda items and mixed routed/unrouted documents WHEN `MeetingRoutedDocumentsTab` is mounted THEN only the routed documents appear
- [x] Implement
- [x] Test
- **DEVIATION**: `vitest.config.js` runs on plain Vite with no `@vitejs/plugin-vue` registered and its `include` glob is `tests/vitest/**/*.spec.{js,ts}` (`src/**` is explicitly excluded) — a `.vue` SFC cannot be imported by a Vitest spec in this repo at all (confirmed empirically; this is also why the existing `tests/vitest/registerDetailWidgets.spec.js` and `tests/vitest/ensureRelationType.spec.js` test extracted `.js` logic rather than mounting components). So "mount `MeetingKascommissieTab`/`MeetingRoutedDocumentsTab`" is not achievable as originally worded. Both components' logic that the acceptance criteria actually exercise (the mode gate; the two-hop id-membership join/merge) was extracted into importable, dependency-free `.js` siblings — `src/components/tabs/kascommissieVisibility.js` (`isKascommissieVisible`, `kascommissieContent`) and `src/components/tabs/routedDocumentsJoin.js` (`collectAgendaItemIds`, `filterRoutedIngekomenStukken`, `buildRoutedDocumentRows`) — and `tests/vitest/meetingKascommissieVisibility.spec.js` (8 tests) / `tests/vitest/routedDocumentsJoin.spec.js` (16 tests) exercise those functions directly, covering the same GIVEN/WHEN/THEN behaviour the acceptance criteria describe. The `.vue` components themselves are thin wrappers with no additional branching logic of their own, so this covers the real logic surface; only the "mounted in isolation" mechanism differs from the original wording.

## Quality checklist

- All new/changed business logic covered by vitest unit tests (`src/components/tabs/__tests__/`) — no PHPUnit needed, this change is frontend-only
- No new API endpoints — Newman/Postman coverage not applicable
- UI changes covered by Playwright browser tests (facet rendering, mode-gate visibility, add-in-context create pre-fill)
- All tests pass (`npm run test:unit`, `npm run test:e2e`)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new user-facing string (ADR-007)
- `npm run check:manifest` and `npm run check:nav-ceiling` both pass
- `openspec validate` passes
- No PHP files touched — `composer check:strict` unaffected

## Live post-rebuild verification (not run by the implementation agent — no `npm run build`, no Playwright; orchestrator/CI or a follow-up agent should verify against a rebuilt bundle on the shared dev instance)

- Open a `gov`-mode meeting (e.g. the seeded `raadsvergadering-2025-01-15`) and confirm all 18 `MeetingDetail` widgets render — the 13 pre-existing ones plus the 5 new facets — with no console errors and no widget clipped/overlapping (gridY 35-49 is new territory below the existing gridY-31 floor).
- Confirm the oral-questions facet lists the 3 seeded `mondelinge-vraag` objects and the proxy-authorizations facet lists the 2 seeded `proxyAuthorization` objects that target this meeting (design.md Seed Data table); confirm "Create" on each pre-fills `targetMeeting`/`meeting` to the current meeting.
- Confirm the interpellations facet renders with no create button (seed data has no `behandeldIn` set for this meeting, so it should render its empty state).
- Confirm the kascommissie facet renders NOTHING (no card, no title) on this `gov`-mode meeting, and toggle `organisatie_modus` to `assoc` (Settings) to confirm it then renders (no `assoc`-mode meeting is seeded yet, so the list itself may be empty — verify the CARD renders, not necessarily populated rows).
- Confirm the routed-documents facet lists the seeded `raadsinformatiebrief`/`ingekomen-stuk` objects routed via `lijst-ingekomen-stukken-2025-01-15` (design.md Seed Data table), with a working Type badge and row-click navigation to the correct detail page per type, and confirm it never renders a create button.
- Confirm `@object.governanceBody` actually resolves inside `MeetingKascommissieTab` at runtime (this implementation relies on Vue 3's documented behaviour that `provide`/`inject` follows the real mounted component tree, not the lexical slot-template location — verified by source reading of `@conduction/nextcloud-vue`'s `CnDetailPage`/`CnPageRenderer`/`CnObjectListWidget`, not by a live app check, since the shared `:8080` dev instance had Decidesk disabled at implementation time). If this resolves to `null`/unresolved instead, `CnObjectListWidget` will show its "waiting for context" prompt forever in `assoc` mode — that would be the visible symptom to look for.
- Run a WCAG 2.2 AA pass (`/test-accessibility`) across the page, including keyboard nav through the two new tabs and confirming the hidden kascommissie facet leaves no empty landmark/ARIA region (TC-10).
