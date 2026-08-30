# ingekomen-stukken-register Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [toezeggingen-ingekomen-stukken](../../changes/toezeggingen-ingekomen-stukken/)

## Purpose

The lijst ingekomen stukken: registration of documents received by the council (letters from residents and organisations, college submissions, petitions, invitations), a griffie routing advice per document, placement on the dedicated "Lijst ingekomen stukken" agenda item of the next council meeting, bulk council confirmation of the routing decisions via the existing hamerstuk flow (`agenda-live-management` REQ-LIV-003), follow-up status tracking, and WOO-aware public publication in which natural-person senders are anonymised. Files attach through the existing Files leaf; e-mail intake automation and full DMS are out of scope.

## ADDED Requirements

### Requirement: REQ-001 IngekomenStuk schema on OpenRegister

The system SHALL define an `IngekomenStuk` schema in the decidesk register (via a `lib/Settings/register.d/` fragment per ADR-037), annotated `x-schema-org: schema:Message` (sender, dateReceived). The schema SHALL carry at minimum: `title` (required), `sender` (display name, required), `senderType` (enum `natuurlijk-persoon` | `organisatie` | `bestuursorgaan`, required — drives public anonymisation), `receivedAt` (date, required), `category` (enum `brief-inwoner` | `brief-organisatie` | `collegestuk` | `petitie` | `uitnodiging` | `overig`, required), `summary` (optional), `routingAdvice` (enum `voor-kennisgeving-aannemen` | `in-handen-college-ter-afdoening` | `in-handen-college-ter-voorbereiding` | `betrekken-bij-agendapunt`, optional until advised), `targetAgendaItem` (AgendaItem reference, required when routingAdvice is `betrekken-bij-agendapunt`), `listAgendaItem` (reference to the "Lijst ingekomen stukken" AgendaItem it is placed on, optional), `directedTo` (GovernanceBody reference, required), and `lifecycle` (required). Every property SHALL carry a `title`; manifest and widget sources reference the schema by slug `ingekomen-stuk`.

#### Scenario: Griffie registers an incoming letter

- GIVEN a letter from a resident received by the griffie
- WHEN the griffie medewerker registers it with title, sender, senderType `natuurlijk-persoon`, received date, and category `brief-inwoner`
- THEN an IngekomenStuk object is created in the decidesk register in its initial lifecycle state
- AND supporting files attach via the existing Files integration (no new document store)

#### Scenario: Routing advice to an agenda item requires the target

- GIVEN a registered IngekomenStuk
- WHEN the griffie sets routingAdvice `betrekken-bij-agendapunt` without a targetAgendaItem
- THEN schema validation rejects the save until a target agenda item is referenced

### Requirement: REQ-002 Follow-up lifecycle is declarative

The `IngekomenStuk` schema SHALL declare its follow-up workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`): field `lifecycle`, initial `geregistreerd`, states `geregistreerd → geagendeerd → routering-vastgesteld → afgedaan`, plus `aangehouden` reachable from `geagendeerd` and `routering-vastgesteld` (returning to `geagendeerd` for a later meeting), with `afgedaan` terminal. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Normal flow from registration to settlement

- GIVEN an IngekomenStuk in `geregistreerd`
- WHEN it is placed on the lijst ingekomen stukken (→ `geagendeerd`), the council confirms the routing (→ `routering-vastgesteld`), and the college afdoening is recorded (→ `afgedaan`)
- THEN every transition is accepted per the declared transition map

#### Scenario: Council pulls a stuk from the list

- GIVEN an IngekomenStuk in `geagendeerd`
- WHEN a raadslid requests separate discussion and the stuk is set to `aangehouden`
- THEN the transition is accepted and the stuk can later return to `geagendeerd` for a subsequent meeting

### Requirement: REQ-003 Placement on the lijst-ingekomen-stukken agenda item

The system SHALL let the griffie place registered stukken on the "Lijst ingekomen stukken" AgendaItem of the next scheduled council meeting of the governance body: the placement action sets `listAgendaItem` on each selected stuk and moves it to `geagendeerd`. The lijst agenda item SHALL be a regular decidiq AgendaItem (typically tagged `hamerstuk`), so agenda publication, the live meeting view, and minutes treat it like any other item. The agenda item detail SHALL show the stukken placed on it with their routing advice.

#### Scenario: Griffie places the week's stukken on the next meeting

- GIVEN four IngekomenStuk objects in `geregistreerd` and a scheduled council meeting with a "Lijst ingekomen stukken" agenda item
- WHEN the griffie selects the four stukken and places them on that agenda item
- THEN each stuk references the lijst agenda item, its lifecycle becomes `geagendeerd`
- AND the agenda item detail lists the four stukken with their proposed routing advice

#### Scenario: Published agenda shows the list

- GIVEN a published agenda containing the lijst-ingekomen-stukken item
- WHEN a participant opens the agenda item
- THEN the placed stukken are visible with title, category, and routing advice

### Requirement: REQ-004 Bulk council confirmation via the hamerstuk flow

The system SHALL let the chair confirm the routing advice of all stukken on the lijst in a single action during the live meeting, reusing the consent-agenda semantics of `agenda-live-management` REQ-LIV-003: batch confirmation sets every placed stuk in `geagendeerd` to `routering-vastgesteld` (its routingAdvice becoming the routing decision). Before batch confirmation, the chair SHALL be able to pull an individual stuk off the list (it becomes `aangehouden`) so the remaining batch confirmation excludes it. The batch action SHALL be restricted to the chair (or secretary acting for the chair) of an opened meeting.

#### Scenario: Chair confirms the whole list as hamerstuk

- GIVEN an opened council meeting whose lijst-ingekomen-stukken item carries 4 stukken in `geagendeerd`
- WHEN the chair triggers the bulk confirmation and confirms the dialog
- THEN all 4 stukken transition to `routering-vastgesteld` with their advice recorded as the decision

#### Scenario: One stuk pulled for separate discussion

- GIVEN the same list before confirmation
- WHEN the chair pulls one stuk off the list on a raadslid's request
- THEN that stuk becomes `aangehouden` and the subsequent bulk confirmation transitions only the remaining 3

#### Scenario: Non-chair cannot bulk-confirm

- GIVEN an opened meeting and a participant with role `member`
- WHEN the participant views the lijst agenda item
- THEN the bulk confirmation control is not available to them

### Requirement: REQ-005 Public publication with WOO-aware anonymisation

Publication of the lijst ingekomen stukken SHALL go through the existing public-publication machinery (derived, immutable, allow-list payload + `publicatiedatum` predicate; OpenCatalogi routing when configured): an IngekomenStuk is eligible once it reaches `routering-vastgesteld` (or `afgedaan`) and its meeting is public. The payload SHALL carry title, category, receivedAt, routing decision, and sender rendered per senderType: for `natuurlijk-persoon` the sender SHALL be anonymised to a neutral label (e.g. "Inwoner") — the payload SHALL NOT contain the natural person's name, address, or contact details; for `organisatie`/`bestuursorgaan` the sender name is carried as-is. Anonymisation SHALL be enforced structurally in payload construction, independent of UI state. The system SHALL NOT serve app-local anonymous pages for ingekomen stukken.

#### Scenario: Natural-person sender anonymised

- GIVEN an IngekomenStuk with senderType `natuurlijk-persoon` and sender "J. Jansen" in `routering-vastgesteld`
- WHEN staff publish it and the payload is read through the OR published-predicate surface
- THEN the payload shows sender "Inwoner" and contains no occurrence of "J. Jansen" or contact details

#### Scenario: Organisation sender published as-is

- GIVEN an IngekomenStuk with senderType `organisatie` and sender "Stichting Dorpsbelang"
- WHEN it is published
- THEN the payload carries "Stichting Dorpsbelang" as sender

#### Scenario: Unconfirmed stuk not eligible

@e2e exclude eligibility contract — covered by PHPUnit on the eligibility service plus Newman negative test
- GIVEN an IngekomenStuk in `geregistreerd`
- WHEN a publish request is made for it
- THEN the request is rejected with an eligibility error and no payload is created

### Requirement: REQ-006 List page, detail page, and export

The system SHALL provide an IngekomenStukken index page and an IngekomenStukDetail page as manifest pages in a `src/manifest.d/` fragment, per existing manifest-v2 conventions (`register: decidesk`, `schema: ingekomen-stuk`; columns title, sender, category, receivedAt, routingAdvice, lifecycle; quick filters on lifecycle, category, and governance body). The index SHALL support CSV export via `ExportService` + `CnMassExportDialog`. The detail page SHALL show routing advice/decision, the linked lijst agenda item and meeting, attached files via the Files leaf, and the audit trail in the sidebar.

#### Scenario: Griffie works the weekly list

- GIVEN registered stukken in mixed lifecycle states
- WHEN the griffie opens the IngekomenStukken page and filters on `geregistreerd`
- THEN only unplaced stukken are listed, ready for placement
- AND clicking a row opens the detail page with routing, meeting link, files, and audit trail

#### Scenario: Export the list to CSV

- GIVEN a filtered list of stukken
- WHEN the griffie exports via the mass-export dialog
- THEN a CSV downloads with title, sender, category, received date, routing, and lifecycle columns

## Non-Functional Requirements

- **Performance:** index paginates via the standard OR list API; bulk confirmation is one batched update per stuk on the list (bounded by list size, no N+1 reads).
- **Accessibility:** Target WCAG 2.2 AA via standard manifest-v2 components and existing dialog components (`CnFormDialog`, confirmation dialogs); the 6 NEW-in-2.2 SCs are n/a beyond shared-component coverage (no dragging, no auth flows, no new help surfaces).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); enum labels translated; i18n keys in English.

## Acceptance Criteria

- [ ] IngekomenStuk schema registers from a register.d fragment; conditional targetAgendaItem validation works
- [ ] Lifecycle transitions enforced by x-openregister-lifecycle only
- [ ] Placement action links stukken to the lijst agenda item and sets `geagendeerd`
- [ ] Chair bulk confirmation transitions all placed stukken; pulled stukken become `aangehouden`; non-chair has no control
- [ ] Published payloads anonymise natural-person senders structurally; organisations pass through; unconfirmed stukken are refused
- [ ] Index/detail pages render from the manifest fragment; CSV export works

## Notes

- Related: `agenda-live-management` (hamerstuk batch semantics reused), `public-publication` (payload machinery extended — see the delta on that spec in this change), `email-linking-via-email-leaf` (future: auto-registering inbound griffie mail as IngekomenStuk is deliberately deferred).
- WOO context: proactive publication of ingekomen stukken is common griffie practice; anonymisation of natural persons follows the same "structural, server-side" rule as the existing "totals, never voters" payload discipline.
- No ORI type exists for ingekomen stukken; `schema:Message` is the schema.org annotation (sender/dateReceived map naturally).
