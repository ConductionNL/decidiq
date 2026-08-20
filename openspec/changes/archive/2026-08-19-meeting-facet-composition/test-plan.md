# Test Plan: meeting-facet-composition

## Test Cases

### TC-1: Oral questions facet scoped to the current meeting
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-009-oral-questions-vragenuur-facet`
- **type**: functional
- **persona**: N/A (griffier/clerk workflow, no dedicated persona test exists for this role)
- **preconditions**: Meeting "Raadsvergadering 2025-01-15" seeded with 3 `mondelinge-vraag` objects targeting it
- **steps**: Navigate to `/meetings/:id` for that meeting
- **expected result**: The oral-questions facet lists the 3 seeded questions with number, subject, fraction, status; each row links to `MondelingeVraagDetail`
- **test command**: `/test-functional`

### TC-2: Add oral question in context pre-fills the meeting
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-009-oral-questions-vragenuur-facet`
- **type**: functional
- **persona**: N/A
- **preconditions**: User is on a meeting's detail page with edit rights
- **steps**: Click "Create" on the oral-questions facet, submit the create dialog
- **expected result**: The new `mondelinge-vraag` object's `targetMeeting` equals the current meeting without the user selecting it
- **test command**: `/test-functional`

### TC-3: Interpellations facet shows only requests scheduled at this meeting
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-010-interpellations-facet`
- **type**: functional
- **persona**: N/A
- **preconditions**: One `interpellatieverzoek` with `behandeldIn` set to this meeting, one without
- **steps**: Navigate to the meeting's detail page
- **expected result**: Only the scheduled request appears; no create button is present on this facet
- **test command**: `/test-functional`

### TC-4: Proxy authorizations facet lists and creates in context
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-011-proxy-authorizations-voting-facet`
- **type**: functional
- **preconditions**: Meeting seeded with 2 `proxyAuthorization` objects
- **steps**: Navigate to the meeting's detail page; create a new proxy authorization from the facet
- **expected result**: Both seeded proxies list with signature/countersign status; the new one is created with `meeting` pre-filled
- **test command**: `/test-functional`

### TC-5: Kascommissie facet visible only in association mode
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-kascommissie-verklaringen-facet-assoc-mode-only`
- **type**: functional
- **preconditions**: Tenant `organisatie_modus` togglable; a meeting whose `governanceBody` has a `kascommissie-verklaring`
- **steps**: Load the meeting's detail page with `organisatie_modus = assoc`, then reload with `organisatie_modus = gov`
- **expected result**: Facet renders the statement in `assoc` mode; renders no content in `gov` mode, and no other widget's layout breaks
- **test command**: `/test-functional`

### TC-6: Kascommissie mode gate — unit-level toggle coverage
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#scenario-kascommissie-facet-hidden-outside-association-mode`
- **type**: functional
- **preconditions**: `MeetingKascommissieTab.vue` mounted in isolation with a mocked settings store
- **steps**: Mount with each of `gov`/`corp`/`assoc`/`ops` as `organisatie_modus`
- **expected result**: `CnObjectListWidget` renders only when mode is `assoc`
- **test command**: `npm run test:unit` (vitest, `src/components/tabs/__tests__/MeetingKascommissieTab.spec.js`)

### TC-7: Routed-documents facet — two-hop join correctness
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-013-routed-incoming-documents-facet-read-only`
- **type**: functional
- **preconditions**: Meeting with 2 agenda items; a raadsinformatiebrief routed to one, an ingekomen-stuk routed to the other via `listAgendaItem`, and an unrouted ingekomen-stuk
- **steps**: Navigate to the meeting's detail page
- **expected result**: Facet lists the letter and the routed incoming document; the unrouted one is absent; no create button is present
- **test command**: `/test-functional`

### TC-8: Routed-documents facet — unit-level join coverage
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#scenario-documents-routed-onto-the-meetings-agenda`
- **type**: functional
- **preconditions**: `MeetingRoutedDocumentsTab.vue` mounted in isolation with a mocked object store returning a fixed set of agenda items and documents
- **steps**: Mount with the fixture and assert on rendered rows
- **expected result**: Only documents whose `agendaItem`/`targetAgendaItem`/`listAgendaItem` matches one of the mocked agenda-item ids appear
- **test command**: `npm run test:unit` (vitest, `src/components/tabs/__tests__/MeetingRoutedDocumentsTab.spec.js`)

### TC-9: No nav/manifest regression
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#acceptance-criteria`
- **type**: regression
- **preconditions**: This change applied to a clean checkout
- **steps**: Run `npm run check:manifest` and `npm run check:nav-ceiling`
- **expected result**: Both pass with no new top-level nav entries and a structurally valid manifest
- **test command**: `/test-regression`

### TC-10: Accessibility of the 3 declarative facets and the 2 custom facets
- **spec_ref**: `openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: Meeting detail page with all 5 facets populated
- **steps**: Run the WCAG 2.2 AA audit across the meeting detail page, including keyboard navigation through each facet's rows and the kascommissie facet's hidden state
- **expected result**: No new violations; the hidden kascommissie facet leaves no focus trap and no orphaned ARIA region
- **test command**: `/test-accessibility`

## Coverage Summary

- REQ-MDV-009 (oral questions): covered — TC-1, TC-2
- REQ-MDV-010 (interpellations): covered — TC-3
- REQ-MDV-011 (proxy authorizations): covered — TC-4
- REQ-MDV-012 (kascommissie, assoc-mode gated): covered — TC-5, TC-6
- REQ-MDV-013 (routed documents, read-only): covered — TC-7, TC-8
- Non-functional (manifest/nav regression, accessibility): covered — TC-9, TC-10

## Out of Scope

- Performance load testing of the routed-documents facet's 3-fetch sequence
  — deferred until a meeting with an unusually large agenda-item count is
  observed in practice; the design's Trade-offs section already accepts
  this cost at typical scale (~5-30 agenda items).
- Security/authorization testing — no new endpoints or RBAC rules are
  introduced; existing OpenRegister RBAC coverage applies unchanged.
