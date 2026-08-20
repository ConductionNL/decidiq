# raadsinformatiebrieven-register Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- [raadsinformatiebrieven](../../changes/raadsinformatiebrieven/)

## Purpose

The raadsinformatiebrieven register (RIB / collegebrieven): the college's formal outbound information letters to the council under its actieve informatieplicht (Gemeentewet art. 169), tracked from verzending through optional agendering ter kennisname to betrokken-bij-behandeling, with a per-RIB technische-vragen thread (short Q&A between council members and the organisation), an optional toezegging-afdoening link, and WOO-aware public publication. A RIB is outbound and college-issued — explicitly not an ingekomen stuk (`ingekomen-stukken-register` owns inbound mail) — and a technische vraag is explicitly not a formal art. 33 schriftelijke vraag (the planned `SchriftelijkeVraag` of `changes/fractievoorzitter-fractie-koppeling` owns that instrument).

## ADDED Requirements

### Requirement: REQ-RIB-001 Raadsinformatiebrief schema on OpenRegister

The system SHALL define a `Raadsinformatiebrief` schema in the decidesk register (via the `lib/Settings/register.d/51-raadsinformatiebrieven.json` fragment per ADR-037, never by editing `decidesk_register.json`), annotated `x-schema-org: schema:Message` (sender = the college, recipient = the council, dateSent). The schema SHALL carry at minimum: `number` (human-readable letter number, format `RIB-{jaar}-{volgnummer}`, schema-validated by pattern, required, unique per year), `onderwerp` (required), `portefeuillehouder` (Person reference, required), `category` (open string driven by a configurable option list — not a hard enum, so municipalities can extend categories without a schema change; required), `sentAt` (verzenddatum, date, required), `directedTo` (GovernanceBody reference, required), `letterDocument` (file reference via the Files leaf, required) plus `bijlagen` (file references, optional), `agendaItem` (AgendaItem reference — the ter-kennisname placement, optional), `afgedaneToezegging` (Toezegging reference, optional), `relatedDossier` (dossier reference — targets the `ArchivalDossier` planned in `records-management-archiving`; nullable, degrades to a plain link until that change lands; optional), `relatedDecision` (Decision reference, optional), `relatedMotion` (reference to a `Decision` of `decisionType: motion`, optional), and `lifecycle` (required). Every property SHALL carry a `title`; the manifest and all widget/filter sources SHALL reference the schema by its slug `raadsinformatiebrief`. The schema SHALL NOT carry internal-only or citizen-PII fields (no drafts, no parafen, no addresses), so the whole object is publishable by construction.

#### Scenario: Griffie registers a sent college letter

- GIVEN a raadsinformatiebrief sent by the college about jeugdzorg wachtlijsten
- WHEN the griffie medewerker registers it with number RIB-2026-014, onderwerp, wethouder Van Dijk as portefeuillehouder, category, sent date, and the letter PDF
- THEN a Raadsinformatiebrief object is created in the decidesk register in its initial lifecycle state, linked to the gemeenteraad governance body
- AND the letter and bijlagen attach via the existing Files integration (no new document store)

#### Scenario: Number format is validated

- GIVEN a new Raadsinformatiebrief being saved with number "brief-14"
- WHEN OpenRegister validates the object against the schema
- THEN the save is rejected because the number does not match the `RIB-{jaar}-{volgnummer}` pattern

#### Scenario: Register fragment is additive

- GIVEN a decidesk installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the Raadsinformatiebrief schema is registered from the register.d fragment 51
- AND no existing schema in `decidesk_register.json` is modified

### Requirement: REQ-RIB-002 RIB lifecycle is declarative with optional agendering

The `Raadsinformatiebrief` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`default`): field `lifecycle`, initial `verzonden`, transitions `verzonden → geagendeerd`, `geagendeerd → betrokken-bij-behandeling`, and `verzonden → betrokken-bij-behandeling` (agendering is optional), with `betrokken-bij-behandeling` terminal. Setting `geagendeerd` SHOULD be accompanied by the `agendaItem` reference recording the ter-kennisname placement on an agenda item (typically the lijst-ingekomen-stukken/LIS item or a dedicated RIB list item of a meeting). The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: RIB placed ter kennisname on the next meeting

- GIVEN a Raadsinformatiebrief in lifecycle `verzonden`
- WHEN the griffie sets it to `geagendeerd` with the LIS agenda item of the next raadsvergadering as `agendaItem`
- THEN the transition is accepted and the RIB references the agenda item

#### Scenario: RIB betrokken bij behandeling without separate agendering

- GIVEN a Raadsinformatiebrief in lifecycle `verzonden` that a committee discusses directly with a related raadsvoorstel
- WHEN the griffie sets it to `betrokken-bij-behandeling`
- THEN the transition is accepted per the declared transition map and the object is terminal

#### Scenario: Invalid transition rejected declaratively

- GIVEN a Raadsinformatiebrief in lifecycle `betrokken-bij-behandeling`
- WHEN any user attempts to set the lifecycle back to `verzonden`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-RIB-003 Toezegging-afdoening link surfaces as evidence, never duplicates the lifecycle

When `afgedaneToezegging` references a `Toezegging`, the system SHALL surface the RIB on that toezegging's detail view as afdoening evidence per the toezeggingen-register model (the linked-RIB display alongside `afdoeningsBewijs`), and the RIB detail SHALL link back to the toezegging. The system SHALL NOT auto-transition the toezegging's lifecycle, SHALL NOT copy afdoening state onto the RIB, and SHALL NOT introduce a second afdoening log: marking the toezegging `afgedaan` (with `afdoeningsToelichting` and `afdoeningsBewijs` referencing the RIB) remains an explicit griffie action on the Toezegging governed by toezeggingen-register REQ-002.

#### Scenario: RIB afdoet a toezegging

- GIVEN an open Toezegging "raadsbrief jeugdzorg vóór 1 maart" and a newly registered Raadsinformatiebrief with `afgedaneToezegging` set to it
- WHEN the griffier opens the Toezegging detail
- THEN the RIB is shown as afdoening evidence with a navigable link
- AND the toezegging's lifecycle is still `open` until the griffier explicitly sets it to `afgedaan` with the RIB as `afdoeningsBewijs`

#### Scenario: No duplicate afdoening state on the RIB

- GIVEN a Raadsinformatiebrief linked to a toezegging that is later marked `afgedaan`
- WHEN the RIB object is inspected
- THEN it carries only the `afgedaneToezegging` reference and no afdoening status, note, or log of its own

### Requirement: REQ-RIB-004 TechnischeVraag schema for the per-RIB Q&A thread

The system SHALL define a `TechnischeVraag` schema in the decidesk register (same register.d fragment 51), annotated `x-schema-org: schema:Question` (with the answer as `schema:Answer` text). The schema SHALL carry at minimum: `rib` (Raadsinformatiebrief reference, required), `vraag` (question text, required), `gesteldDoor` (Person reference — the council member, required), `fractie` (fraction name, plain string until the planned Fractie schema of `changes/fractievoorzitter-fractie-koppeling` lands; optional), `gesteldOp` (date, required), `antwoord` (answer text, optional until answered), `beantwoordDoor` (answering organisation unit, plain string, optional), `beantwoordOp` (date, optional), and `lifecycle` (required) declared exclusively via `x-openregister-lifecycle`: initial `gesteld`, transition `gesteld → beantwoord`, with `beantwoord` terminal. Every property SHALL carry a `title`; the slug is `technische-vraag`. The RIB detail page SHALL render the thread of technische vragen for that RIB in question order, with staff able to record answers.

#### Scenario: Member asks a technische vraag on a RIB

- GIVEN a registered Raadsinformatiebrief
- WHEN a raadslid (or the griffie on their behalf) adds a technische vraag with question text, the member as gesteldDoor, and their fractie
- THEN a TechnischeVraag object is created in lifecycle `gesteld`, referencing the RIB
- AND the RIB detail thread shows the question as unanswered

#### Scenario: Organisation answers the question

- GIVEN a TechnischeVraag in lifecycle `gesteld`
- WHEN the griffie records the answer text, the answering afdeling, and the answer date, and sets the lifecycle to `beantwoord`
- THEN the transition is accepted and the thread shows question and answer together

#### Scenario: Question without a RIB is rejected

- GIVEN a TechnischeVraag being saved without a `rib` reference
- WHEN OpenRegister validates the object
- THEN the save is rejected by schema validation

### Requirement: REQ-RIB-005 Technische vragen are bounded away from schriftelijke vragen and inbound mail

A technische vraag SHALL be a short informational Q&A entry scoped to one RIB and SHALL NOT implement the formal art. 33 schriftelijke-vragen instrument: the `TechnischeVraag` schema SHALL NOT carry answer deadlines/termijnbewaking, college-workflow states, or fractie-quorum machinery, and the system SHALL NOT convert a technische vraag into a schriftelijke vraag automatically — escalation is a manual re-filing as a `SchriftelijkeVraag` (planned in `changes/fractievoorzitter-fractie-koppeling`) once that capability lands. Likewise, a Raadsinformatiebrief SHALL NOT be registered as, or mirrored to, an `IngekomenStuk` (`ingekomen-stukken-register` owns inbound external mail), and college-internal drafting/parafering of RIBs SHALL remain out of decidesk (procest domain): decidesk registers the sent letter only.

#### Scenario: Technische vraag carries no formal-instrument machinery

@e2e exclude static schema-shape convention — enforced by schema review and PHPUnit assertion on the register fragment
- WHEN the `TechnischeVraag` schema in fragment 51 is inspected
- THEN it contains no deadline, termijn, or college-workflow properties and no lifecycle states beyond `gesteld → beantwoord`

#### Scenario: Member needs a formal answer instead

- GIVEN a technische vraag whose answer the member finds insufficient
- WHEN the member decides to invoke art. 33
- THEN they file a new schriftelijke vraag through that instrument's own capability (manual re-filing; the technische vraag stays as-is in this register, unconverted)

#### Scenario: RIB never appears in the inbound register

- GIVEN a registered Raadsinformatiebrief
- WHEN the IngekomenStukken index is browsed
- THEN the RIB is not listed there (no IngekomenStuk object was created for it)

### Requirement: REQ-RIB-006 Public publication of RIBs and answered technische vragen via the OR published-predicate

The system SHALL make RIBs and answered technische vragen publicly available through OpenRegister's anonymous RBAC published-predicate surface, following the toezeggingen-register live-predicate pattern: the `Raadsinformatiebrief` schema declares an `authorization.read` rule granting the `public` group read access while `publicatiedatum <= $now`, and the `TechnischeVraag` schema declares the same rule with the additional condition that `lifecycle` equals `beantwoord` — so an unanswered question is never anonymously readable even if its predicate is set prematurely. Staff with governance-body authority publish by setting `publicatiedatum` (withdraw via `depublicatiedatum`); publication SHALL never happen without an explicit staff action. Because both schemas carry only publishable fields by construction (WOO-aware: portefeuillehouders and council members are public officeholders; no citizen PII, no internal fields — REQ-RIB-001/REQ-RIB-004), no derived payload is needed and the public list reflects lifecycle changes live. The system SHALL NOT serve app-local anonymous pages for RIBs or technische vragen. A confidential RIB is handled by simply never setting the predicate.

#### Scenario: Published RIB is anonymously readable

- GIVEN a Raadsinformatiebrief published by the griffie (publicatiedatum in the past)
- WHEN an unauthenticated client reads the OR published-predicate surface
- THEN the RIB is returned with number, onderwerp, portefeuillehouder, category, sent date, and lifecycle

#### Scenario: Answered question published, unanswered never exposed

- GIVEN one TechnischeVraag in `beantwoord` with publicatiedatum in the past and one in `gesteld` whose publicatiedatum was set by mistake
- WHEN an unauthenticated client queries the published surface
- THEN only the answered question is returned; the unanswered one is not

#### Scenario: Status change is live on the public list

- GIVEN a published Raadsinformatiebrief in lifecycle `verzonden`
- WHEN the griffie sets it to `geagendeerd`
- THEN the next anonymous read shows lifecycle `geagendeerd` without any republish step

#### Scenario: Unpublished RIB is not public

- GIVEN a Raadsinformatiebrief without `publicatiedatum`
- WHEN an unauthenticated client queries the published surface
- THEN the RIB is not returned

### Requirement: REQ-RIB-007 List page, detail page with Q&A thread, search, and CSV export

The system SHALL provide a Raadsinformatiebrieven index page and a RaadsinformatiebriefDetail page as manifest pages in a `src/manifest.d/raadsinformatiebrieven.json` fragment (ADR-037), following existing manifest-v2 conventions (`register: decidesk`, `schema: raadsinformatiebrief` — always the slug, never PascalCase): index columns number, onderwerp, portefeuillehouder, category, sentAt, lifecycle; quick filters on lifecycle, category, governance body, and portefeuillehouder; index search (`_search`) matching number and onderwerp; CSV export via `ExportService` + `CnMassExportDialog` including number, onderwerp, portefeuillehouder, category, sent date, and lifecycle. The detail page SHALL show the letter document and bijlagen via the Files leaf, the navigable links (agenda item, toezegging, dossier, decision, motie), the technische-vragen thread (REQ-RIB-004) with add-question and record-answer actions in dedicated dialogs, publish/withdraw actions, and the audit trail in the sidebar.

#### Scenario: Griffie browses and filters the RIB list

- GIVEN registered RIBs across two categories and two governance bodies
- WHEN the griffie opens the Raadsinformatiebrieven page and filters on category and lifecycle `verzonden`
- THEN only matching RIBs are listed
- AND clicking a row opens the detail page showing letter, bijlagen, links, and the Q&A thread

#### Scenario: Search by number

- GIVEN a RIB numbered RIB-2026-014
- WHEN the griffie types "RIB-2026-014" in the index search
- THEN the list narrows to that RIB

#### Scenario: Export the RIB list to CSV

- GIVEN a filtered RIB list
- WHEN the griffie exports via the mass-export dialog
- THEN a CSV downloads containing number, onderwerp, portefeuillehouder, category, sent date, and lifecycle columns

### Requirement: REQ-RIB-008 Declarative notification to council members on a new RIB

New-RIB notifications SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on the `Raadsinformatiebrief` schema: a `created` trigger that notifies the council members of the directed-to governance body, with Dutch and English subjects carrying the RIB number and onderwerp. Recipients SHALL be resolved via `kind:object-acl` and `kind:groups` per the decidesk-notifications recipient rules (never `kind:field` on non-uid person properties). The app SHALL NOT dispatch this notification imperatively and SHALL NOT introduce a bespoke notification BackgroundJob.

#### Scenario: Council members notified of a new RIB

- GIVEN the gemeenteraad governance body with its member group configured
- WHEN a new Raadsinformatiebrief directed to that body is registered
- THEN the council members receive a Nextcloud notification naming the RIB number and onderwerp, linking to the RIB detail

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate (gate-18)
- WHEN the notification-dialect gate scans the raadsinformatiebrieven code paths
- THEN no imperative object-notification dispatch exists; the rule is declarative in the register fragment

## Non-Functional Requirements

- **Performance:** the RIB index paginates via the standard OR list API; the technische-vragen thread loads as one filtered list query per detail view (filter on `rib`, no N+1).
- **Accessibility:** Target WCAG 2.2 AA; pages use standard manifest-v2 components (index/detail) and existing dialog components which carry the fleet's gate-checked semantics; the 6 NEW-in-2.2 SCs are n/a beyond shared-component coverage (no dragging, no auth flows, no new help surfaces).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] Raadsinformatiebrief and TechnischeVraag schemas register from fragment 51 and validate required fields and the RIB number pattern
- [ ] Lifecycle transitions for both schemas are enforced by x-openregister-lifecycle only (no app-side state machine)
- [ ] A RIB linked to a toezegging surfaces as afdoening evidence on the Toezegging detail; no lifecycle duplication either way
- [ ] TechnischeVraag carries no formal-instrument machinery; RIBs never appear in the ingekomen-stukken register
- [ ] Published RIBs and answered technische vragen are anonymously readable through the OR predicate surface; unpublished RIBs and unanswered questions are not
- [ ] Index/detail pages render from the manifest fragment with search, filters, Q&A thread, and CSV export
- [ ] New-RIB notification fires declaratively to council members; gate-18 passes

## Notes

- Related: `toezeggingen-register` (afdoening model — referenced, not duplicated), `ingekomen-stukken-register` (inbound boundary), `changes/fractievoorzitter-fractie-koppeling` (planned `SchriftelijkeVraag` — the formal art. 33 instrument), `termijnagenda` (announces expected RIBs; this register records the sent ones), `public-publication` (this capability uses the toezeggingen-register live-predicate carve-out, not derived payloads, so the eligibility matrix is untouched).
- ORI/OpenRaadsinformatie has no dedicated RIB type; `schema:Message` (sender = college, recipient = council) and `schema:Question`/`schema:Answer` are the schema.org annotations per the register's `x-schema-org` marker convention.
- Deferred: answered-question notification to the asking member (recipient routing for Person-ref fields per decidesk-notifications), global-search schema-list inclusion, dashboard KPI for unanswered technische vragen.
