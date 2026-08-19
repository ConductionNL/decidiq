# Test Plan: organisation-facet-composition

## Test Cases

### TC-1: bodyType enum includes faction, no Fractie schema exists
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-bodies/spec.md#requirement-req-gbd-013-faction-is-a-governancebody-discriminator-not-a-parallel-schema` (Scenario: Faction is a bodyType value, not a new schema)
- **type**: api
- **preconditions**: register imported at `info.version` `0.9.0`
- **steps**: fetch the `GovernanceBody` schema definition; list all schemas in the register
- **expected result**: `bodyType` enum contains `faction`; no schema named `Fractie` exists
- **test command**: `/test-api`

### TC-2: A faction resolves its parentBody
- **spec_ref**: `.../governance-bodies/spec.md#requirement-req-gbd-013...` (Scenario: A faction references its parent council via parentBody)
- **type**: functional
- **preconditions**: seed objects `gemeenteraad-amsterdam` and `groenlinks-fractie-amsterdam` (`parentBody` = the council's id) exist
- **steps**: open `/governance-bodies/{groenlinks-fractie-amsterdam-id}`
- **expected result**: the body detail page's `body-data` widget shows `parentBody` resolving to "Gemeenteraad Amsterdam"
- **test command**: `/test-functional`

### TC-3: Faction membership uses the same Membership relation
- **spec_ref**: `.../governance-bodies/spec.md#requirement-req-gbd-013...` (Scenario: Faction members use the same Membership relation as any other body)
- **type**: functional
- **preconditions**: seed `membership` object `m-marie-groenlinks-fractie` (`governanceBody` = `groenlinks-fractie-amsterdam`) exists
- **steps**: open `/governance-bodies/{groenlinks-fractie-amsterdam-id}`, inspect the Members tab
- **expected result**: Marie Janssen appears as a member of the faction, resolved identically to how she resolves as a member of Gemeenteraad Amsterdam
- **test command**: `/test-functional`

### TC-4: Retirement schedule widget shows the body's rooster
- **spec_ref**: `.../governance-body-crud/spec.md#requirement-view-governance-body-detail` (Scenario: Retirement schedule shown on the body detail page)
- **type**: functional
- **preconditions**: `auditcommissie-provincie-nh` has a generated `rooster-van-aftreden` (`body` = its id)
- **steps**: open `/governance-bodies/{auditcommissie-provincie-nh-id}`
- **expected result**: "Retirement schedule" widget lists the body's rooster; clicking it navigates to `RoosterDetail` showing the ordered term entries
- **test command**: `/test-functional`

### TC-5: Retirement schedule widget empty state
- **spec_ref**: same requirement (Scenario: No retirement schedule yet)
- **type**: functional
- **preconditions**: a `GovernanceBody` with no `rooster-van-aftreden` object (e.g. `directieteam-gemeente-utrecht`)
- **steps**: open that body's detail page
- **expected result**: "Retirement schedule" widget renders its empty state, no console/network error
- **test command**: `/test-functional`

### TC-6: Term rules widget is read-only
- **spec_ref**: same requirement (Scenario: Term rule shown read-only on the body detail page)
- **type**: functional
- **preconditions**: `raad-van-commissarissen-acme-bv` has a `termijn-regeling` object (`body` = its id)
- **steps**: open that body's detail page; inspect the "Term rules" widget
- **expected result**: the rule is listed with no inline create/edit action; clicking navigates to `TermijnRegelingDetail`, which IS editable
- **test command**: `/test-functional`

### TC-7: Integrity widgets (other positions, gifts)
- **spec_ref**: same requirement (Scenario: Integrity declarations shown on the body detail page)
- **type**: functional
- **preconditions**: `gemeenteraad-amsterdam` has seeded `nevenfunctie` and `geschenk` objects (`governanceBody` = its id)
- **steps**: open `/governance-bodies/{gemeenteraad-amsterdam-id}`
- **expected result**: "Other positions" and "Gifts" widgets list the body's declarations; each row navigates to its own detail page
- **test command**: `/test-functional`

### TC-8: Shared-body participation widgets (both directions)
- **spec_ref**: same requirement (Scenario: Shared-body participation shown on the body detail page; Scenario: A body's own participations in shared bodies are shown)
- **type**: functional
- **preconditions**: `bestuur-noz-organisatie` (`bodyType=shared-body`) has `body-participation` objects with `sharedBody` = its id; `gemeenteraad-noorderbrug` (etc.) has a `body-participation` object with `participant` = its id
- **steps**: open `bestuur-noz-organisatie`'s detail page; separately open `gemeenteraad-noorderbrug`'s detail page
- **expected result**: `bestuur-noz-organisatie` shows "Participating organisations" listing `gemeenteraad-noorderbrug`/`gemeenteraad-oostwoud`/`gemeenteraad-zuidermeer`, each a clickable link to that body's own detail page (per design.md Decision 4); `gemeenteraad-noorderbrug` shows "Shared-body participations" listing `bestuur-noz-organisatie`, also a clickable link
- **test command**: `/test-functional`

### TC-9: Zienswijze rounds widget
- **spec_ref**: same requirement (Scenario: Shared-body participation shown on the body detail page)
- **type**: functional
- **preconditions**: `bestuur-noz-organisatie` has `zienswijzeronde` objects (`sharedBody` = its id)
- **steps**: open `bestuur-noz-organisatie`'s detail page
- **expected result**: "Zienswijze rounds" widget lists them; clicking a row navigates to `ZienswijzerondeDetail`
- **test command**: `/test-functional`

### TC-10: Factions widget
- **spec_ref**: same requirement (Scenario: Factions shown on a body's detail page)
- **type**: functional
- **preconditions**: seed factions from TC-2/TC-3
- **steps**: open `gemeenteraad-amsterdam`'s detail page
- **expected result**: "Factions" widget lists `groenlinks-fractie-amsterdam` and `d66-fractie-amsterdam`; clicking a row navigates to that faction's own `GovernanceBodyDetail`
- **test command**: `/test-functional`

### TC-11: No facet widget errors on an empty body
- **spec_ref**: same requirement (Scenario: No facet widget errors when its register is empty for this body)
- **type**: functional
- **preconditions**: `ledenraad-vng` (association, no rooster/termijnregeling/nevenfunctie/geschenk/body-participation/faction data)
- **steps**: open `ledenraad-vng`'s detail page; check browser console
- **expected result**: all 8 new widgets render their empty states; zero console errors, zero failed network requests
- **test command**: `/test-functional`

### TC-12: Detail page accessibility with the expanded widget set
- **spec_ref**: `.../governance-body-crud/spec.md#requirement-view-governance-body-detail` (page-level, all scenarios)
- **type**: accessibility
- **preconditions**: `gemeenteraad-amsterdam` detail page with all 8 new widgets populated
- **steps**: run the WCAG 2.2 AA audit against the full detail page
- **expected result**: no new violations introduced by the added widgets (headings, landmarks, link text, focus order)
- **test command**: `/test-accessibility`

### TC-13: Existing detail-page behaviour unaffected (regression)
- **spec_ref**: `.../governance-body-crud/spec.md#requirement-view-governance-body-detail` (Scenario: User opens a governance body detail page; Related Meetings shown in detail; Related Members shown in detail; CnObjectSidebar is available)
- **type**: regression
- **preconditions**: any existing `GovernanceBody`
- **steps**: open its detail page
- **expected result**: `body-data`, `body-members`, `body-meetings`, `body-files`, `body-template`, `body-efficiency`, `body-retention`, `body-evaluations` all still render exactly as before this change; sidebar still shows only the History tab
- **test command**: `/test-regression`

### TC-14: Griffier persona — end-to-end organisation hub walkthrough
- **spec_ref**: all scenarios under `governance-body-crud`'s "View governance body detail"
- **type**: persona
- **persona**: Annemarie (VNG Standards Architect) — closest fit for a griffie-facing organisational compliance surface
- **preconditions**: `gemeenteraad-amsterdam` fully seeded
- **steps**: navigate from Dashboard to the body, review every new facet widget in turn
- **expected result**: a griffier can find retirement schedule, term rule, integrity declarations, shared-body participation, and factions without leaving the body's own page
- **test command**: `/test-persona-annemarie`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-GBD-013 (faction discriminator, no parallel schema) | TC-1, TC-2, TC-3 |
| governance-body-crud: View governance body detail (MODIFIED) | TC-4 – TC-13 |
| Persona walkthrough | TC-14 |

All scenarios in both delta spec files have at least one mapped test case.

## Out of Scope

- No test case covers `GovernanceBodyMembersTab`'s `participant`-vs-`membership` query correctness — that is pre-existing, unchanged behaviour in this change (see proposal Open Questions #1); it is neither fixed nor newly tested here.
- No test case covers the stale `fractievoorzitter-fractie-koppeling` draft or the three fragments' placeholder Fractie fields — unaffected by this change.
