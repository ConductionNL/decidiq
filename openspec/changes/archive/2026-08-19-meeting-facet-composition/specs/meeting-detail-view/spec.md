# meeting-detail-view Specification

## ADDED Requirements

### Requirement: REQ-MDV-009 — Oral questions (vragenuur) facet
The Meeting detail page (`MeetingDetail`) SHALL show a facet listing the
`mondelinge-vraag` objects whose `targetMeeting` equals the current
meeting's object id, and SHALL let the user create a new oral question
from that facet with `targetMeeting` pre-filled to the current meeting.

#### Scenario: Oral questions scoped to the current meeting
- GIVEN a meeting "Raadsvergadering 2025-01-15" with two `mondelinge-vraag`
  objects whose `targetMeeting` is that meeting, and one `mondelinge-vraag`
  object targeting a different meeting
- WHEN the user opens the meeting's detail page
- THEN the oral-questions facet lists exactly the two questions that
  target this meeting
- AND each row links to that question's detail page

#### Scenario: Creating an oral question in context
- GIVEN the user is on a meeting's detail page
- WHEN the user creates a new oral question from the oral-questions facet
- THEN the new `mondelinge-vraag` object is created with `targetMeeting`
  set to the current meeting without the user having to select it

### Requirement: REQ-MDV-010 — Interpellations facet
The Meeting detail page SHALL show a facet listing the
`interpellatieverzoek` objects whose `behandeldIn` equals the current
meeting's object id.

#### Scenario: Interpellations scheduled at the current meeting
- GIVEN a meeting with one `interpellatieverzoek` object whose
  `behandeldIn` is that meeting, and one `interpellatieverzoek` object
  with no `behandeldIn` set (not yet scheduled)
- WHEN the user opens the meeting's detail page
- THEN the interpellations facet lists exactly the one request scheduled
  at this meeting
- AND the unscheduled request does not appear

### Requirement: REQ-MDV-011 — Proxy authorizations (voting) facet
The Meeting detail page SHALL show a facet listing the `proxyAuthorization`
objects whose `meeting` equals the current meeting's object id, and SHALL
let the user register a new proxy authorization from that facet with
`meeting` pre-filled to the current meeting.

#### Scenario: Proxy authorizations scoped to the current meeting
- GIVEN a meeting with two `proxyAuthorization` objects whose `meeting` is
  that meeting
- WHEN the user opens the meeting's detail page
- THEN the proxy-authorizations facet lists both, showing each one's
  signature and countersign status

#### Scenario: Registering a proxy authorization in context
- GIVEN the user is on a meeting's detail page
- WHEN the user registers a new proxy authorization from the facet
- THEN the new `proxyAuthorization` object is created with `meeting` set
  to the current meeting without the user having to select it

### Requirement: REQ-MDV-012 — Kascommissie verklaringen facet (assoc mode only)
The Meeting detail page SHALL show a facet listing `kascommissie-verklaring`
objects whose `governanceBody` equals the current meeting's own
`governanceBody`, and this facet SHALL be visible only when the tenant's
active `organisatie_modus` setting is `assoc`. In every other mode the
facet SHALL be hidden — the widget declaration itself is not removed from
the page, only its rendered content is suppressed.

#### Scenario: Kascommissie facet visible in association mode
- GIVEN the tenant's `organisatie_modus` is `assoc`
- AND the current meeting's `governanceBody` has one `kascommissie-verklaring`
  object referencing it
- WHEN the user opens the meeting's detail page
- THEN the kascommissie facet renders and lists that statement

#### Scenario: Kascommissie facet hidden outside association mode
- GIVEN the tenant's `organisatie_modus` is `gov`
- AND the current meeting's `governanceBody` has a `kascommissie-verklaring`
  object referencing it
- WHEN the user opens the meeting's detail page
- THEN the kascommissie facet does not render any content
- AND no other facet on the page is affected

### Requirement: REQ-MDV-013 — Routed incoming documents facet (read-only)
The Meeting detail page SHALL show a single read-only facet listing every
`raadsinformatiebrief` object whose `agendaItem` resolves to one of the
current meeting's own agenda items, and every `ingekomen-stuk` object
whose `targetAgendaItem` or `listAgendaItem` resolves to one of the
current meeting's own agenda items. The facet SHALL NOT offer a create
affordance.

#### Scenario: Documents routed onto the meeting's agenda
- GIVEN a meeting with two agenda items, A1 and A2
- AND one `raadsinformatiebrief` object with `agendaItem` = A1
- AND one `ingekomen-stuk` object with `listAgendaItem` = A2
- AND one `ingekomen-stuk` object with no agenda-item reference at all
- WHEN the user opens the meeting's detail page
- THEN the routed-documents facet lists the letter and the routed
  incoming document
- AND the unrouted incoming document does not appear
- AND no create button is offered on this facet

## Non-Functional Requirements

- **Performance:** Each facet's list query SHALL be scoped server-side by
  its filter (never fetch-then-filter-client-side for the three
  single-hop facets); the routed-documents facet's two-hop join MAY do a
  bounded client-side id-membership filter after fetching the current
  meeting's own (typically < 30) agenda items.
- **Accessibility:** Every facet SHALL meet WCAG 2.2 AA — list rows
  keyboard-navigable, empty states announced, the kascommissie facet's
  hidden state SHALL NOT leave a focus trap or an announced-but-invisible
  region.
- **Internationalization:** Dutch and English MUST be supported (ADR-005)
  for every new facet title, column label, and empty-state string.

## Acceptance Criteria

- [ ] All 5 facets render on `MeetingDetail` with real seeded data where
      seed data already exists (oral questions, proxy authorizations,
      routed documents — see design.md Seed Data)
- [ ] Kascommissie facet is invisible on the seeded `gov`-mode demo meeting
      and would render on an `assoc`-mode meeting once one is seeded
- [ ] No new top-level nav entry is added; `npm run check:nav-ceiling`
      passes unchanged
- [ ] `npm run check:manifest` passes

## Notes

- Every ref field this spec relies on (`targetMeeting`, `behandeldIn`,
  `meeting`, `governanceBody`, `agendaItem`, `targetAgendaItem`,
  `listAgendaItem`) is already declared on its schema in
  `lib/Settings/register.d/` — verified during artifact generation,
  2026-08-19. No schema change accompanies this spec.
- Implements ADR-004 Rule 3 (authoring in the meeting context, browsing in
  the register) for the five surfaces `ia-six-clusters` relocated into the
  *Meetings* nav cluster.
- See design.md for why REQ-MDV-012 and REQ-MDV-013 need a registry
  component instead of a pure declarative `object-list` widget.
