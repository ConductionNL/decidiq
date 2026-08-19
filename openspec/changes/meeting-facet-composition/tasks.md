# Tasks: meeting-facet-composition

## Implementation Tasks

### Task 1: Add the three declarative object-list facets to MeetingDetail
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-009-oral-questions-vragenuur-facet`, `#requirement-req-mdv-010-interpellations-facet`, `#requirement-req-mdv-011-proxy-authorizations-voting-facet`
- **files**: `src/manifest.json` (page `MeetingDetail`: `widgets[]`, `layout[]`)
- **acceptance_criteria**:
  - GIVEN a meeting with mondelinge-vraag/proxyAuthorization objects targeting it WHEN its detail page loads THEN the oral-questions and proxy-authorizations facets list them and offer add-in-context pre-filled to this meeting
  - GIVEN a meeting with interpellatieverzoek objects whose behandeldIn is this meeting WHEN its detail page loads THEN the interpellations facet lists them with no create button
  - GIVEN `npm run check:manifest` and `npm run check:nav-ceiling` WHEN run after this task THEN both pass unchanged (no new nav entries, valid manifest structure)
- [ ] Implement
- [ ] Test

### Task 2: Kascommissie mode-gated facet
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-kascommissie-verklaringen-facet-assoc-mode-only`
- **files**: `src/components/tabs/MeetingKascommissieTab.vue` (new), `src/manifest.json` (widget + slot entry), `src/registry.js`
- **acceptance_criteria**:
  - GIVEN `organisatie_modus` is `assoc` and the meeting's governanceBody has a kascommissie-verklaring WHEN the detail page loads THEN the facet renders the statement (no create button)
  - GIVEN `organisatie_modus` is `gov`/`corp`/`ops` WHEN the detail page loads THEN the facet renders no content and no other widget's layout shifts unexpectedly
- [ ] Implement
- [ ] Test

### Task 3: Routed incoming-documents facet (two-hop, read-only)
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-013-routed-incoming-documents-facet-read-only`
- **files**: `src/components/tabs/MeetingRoutedDocumentsTab.vue` (new), `src/manifest.json` (widget + slot entry), `src/registry.js`
- **acceptance_criteria**:
  - GIVEN a meeting's own agenda items WHEN the detail page loads THEN the facet lists every raadsinformatiebrief whose agendaItem, and every ingekomen-stuk whose targetAgendaItem/listAgendaItem, resolves to one of those agenda items
  - GIVEN a raadsinformatiebrief/ingekomen-stuk not routed to any of this meeting's agenda items WHEN the detail page loads THEN it does not appear in the facet
  - GIVEN the facet WHEN rendered THEN it offers no create affordance
- [ ] Implement
- [ ] Test

### Task 4: Layout placement + i18n strings for all 5 facets
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#non-functional-requirements`
- **files**: `src/manifest.json` (layout entries per design.md's Layout table), i18n source files for `decidesk` (`nl_NL` + `en_US`)
- **acceptance_criteria**:
  - GIVEN the 5 new widgets WHEN the layout is applied THEN none overlap and each fits its declared gridWidth/gridHeight without clipping
  - GIVEN every new facet title, column label, and empty-state string WHEN the app renders in `nl_NL` THEN no string falls back to its English source key
- [ ] Implement
- [ ] Test

### Task 5: Mode-gate and two-hop-join test coverage
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#scenario-kascommissie-facet-hidden-outside-association-mode`, `#scenario-documents-routed-onto-the-meetings-agenda`
- **files**: `src/components/tabs/__tests__/MeetingKascommissieTab.spec.js` (new), `src/components/tabs/__tests__/MeetingRoutedDocumentsTab.spec.js` (new)
- **acceptance_criteria**:
  - GIVEN `organisatie_modus` toggled between `assoc` and every other mode WHEN `MeetingKascommissieTab` is mounted THEN it renders content only in `assoc` mode
  - GIVEN a mocked meeting with 2 agenda items and mixed routed/unrouted documents WHEN `MeetingRoutedDocumentsTab` is mounted THEN only the routed documents appear
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by vitest unit tests (`src/components/tabs/__tests__/`) — no PHPUnit needed, this change is frontend-only
- No new API endpoints — Newman/Postman coverage not applicable
- UI changes covered by Playwright browser tests (facet rendering, mode-gate visibility, add-in-context create pre-fill)
- All tests pass (`npm run test:unit`, `npm run test:e2e`)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new user-facing string (ADR-007)
- `npm run check:manifest` and `npm run check:nav-ceiling` both pass
- `openspec validate` passes
- No PHP files touched — `composer check:strict` unaffected
