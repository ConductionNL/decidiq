# toezeggingen-register Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- [toezeggingen-ingekomen-stukken](../../changes/toezeggingen-ingekomen-stukken/)

## Purpose

A standalone commitments register (toezeggingenlijst): political commitments made by a portefeuillehouder or college member to a council or committee, tracked from the moment they are made in a meeting through afdoening, with a deadline, declarative rappels (ADR-031), a live public toezeggingenlijst, and CSV export. A toezegging is a public-accountability instrument — it is explicitly not an ActionItem (CalDAV VTODO, `action-item-board-via-deck-leaf`) and not motion execution tracking (`motie-amendement-administratie` UitvoeringsUpdate); it cross-references both without duplicating them.

## ADDED Requirements

### Requirement: REQ-001 Toezegging schema on OpenRegister

The system SHALL define a `Toezegging` schema in the decidesk register (via a `lib/Settings/register.d/` fragment per ADR-037, never by editing `decidesk_register.json`), annotated `x-schema-org: schema:Action` (agent = madeBy, actionStatus derived from lifecycle). The schema SHALL carry at minimum: `text` (the commitment, required), `madeBy` (Person reference — the portefeuillehouder/college member, required), `meeting` (Meeting reference, required), `agendaItem` (AgendaItem reference, optional), `directedTo` (GovernanceBody reference, required), `deadline` (date, optional), `lifecycle` (required), `afdoeningsToelichting` (afdoening note, optional), `afdoeningsBewijs` (evidence link/document reference, optional), and `relatedMotion` (optional reference to a `Decision` of `decisionType: motion`). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `toezegging`.

#### Scenario: Griffier registers a commitment made during a meeting

- GIVEN an open council meeting with an active agenda item
- WHEN the griffier registers a toezegging with text, the wethouder as madeBy, and a deadline
- THEN a Toezegging object is created in the decidesk register linked to the meeting, agenda item, and governance body
- AND the object validates against the schema (missing text or madeBy is rejected by OpenRegister)

#### Scenario: Register fragment is additive

- GIVEN a decidesk installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the Toezegging schema is registered from the register.d fragment
- AND no existing schema in `decidesk_register.json` is modified

### Requirement: REQ-002 Lifecycle is declarative with four states

The `Toezegging` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`default`): field `lifecycle`, initial `open`, states `open → in-uitvoering → afgedaan | vervallen`, with `afgedaan` and `vervallen` terminal. Marking a toezegging `afgedaan` SHOULD be accompanied by an `afdoeningsToelichting`. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Commitment is settled

- GIVEN a Toezegging in lifecycle `in-uitvoering`
- WHEN the griffier sets it to `afgedaan` with an afdoening note and an evidence link (e.g. the raadsbrief)
- THEN the transition is accepted and the object is terminal
- AND the afdoening note and evidence reference are stored on the object

#### Scenario: Invalid transition rejected declaratively

- GIVEN a Toezegging in lifecycle `afgedaan`
- WHEN any user attempts to set the lifecycle back to `open`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-003 Toezegging is not an action item and not motion execution tracking

A Toezegging SHALL NOT be written as, or mirrored to, a CalDAV VTODO ActionItem, and SHALL NOT be counted in the "Open action items" dashboard KPI (REQ-AI-DECK-005). When `relatedMotion` is set, the execution narrative SHALL live exclusively on that motion's UitvoeringsUpdate log (`motie-amendement-administratie`); the Toezegging SHALL only reference the motion and record its own afdoening — the system SHALL NOT create a second execution-update log for motion-tied commitments.

#### Scenario: Commitment stemming from a college takeover of a motion

- GIVEN a motion taken over by the college (overgenomen-door-college) with an UitvoeringsUpdate log
- WHEN the griffier registers the resulting toezegging with `relatedMotion` set to that motion
- THEN the Toezegging detail links to the motion and its execution updates
- AND no duplicate execution-update objects are created by the toezeggingen register

#### Scenario: Action-item KPI unaffected

- GIVEN five open Toezegging objects and two open action-item VTODOs
- WHEN the dashboard "Open action items" KPI computes
- THEN it counts 2 (the VTODO-backed source only)

### Requirement: REQ-004 Deadline rappels are declarative notifications

Deadline reminders SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on the `Toezegging` schema: a scheduled trigger that notifies the portefeuillehouder (madeBy) and the griffie group when a non-terminal toezegging approaches its deadline, and a scheduled trigger when it is past its deadline, both with Dutch and English subjects. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Rappel before deadline

- GIVEN a Toezegging in lifecycle `open` whose deadline is within the configured rappel window
- WHEN the scheduled notification trigger evaluates
- THEN the madeBy person and the griffie recipients receive a Nextcloud notification referencing the toezegging

#### Scenario: Overdue rappel

- GIVEN a Toezegging in lifecycle `open` or `in-uitvoering` whose deadline is in the past
- WHEN the scheduled notification trigger evaluates
- THEN an overdue notification is sent
- AND no notification is sent for toezeggingen in `afgedaan` or `vervallen`

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- WHEN the notification-dialect gate scans the toezeggingen code paths
- THEN no imperative object-notification dispatch exists; all rappels are declarative rules in the register fragment

### Requirement: REQ-005 Public toezeggingenlijst via the OR published-predicate

The system SHALL make the public toezeggingenlijst available through OpenRegister's anonymous RBAC published-predicate surface: the `Toezegging` schema declares an `authorization.read` rule granting the `public` group read access while `publicatiedatum <= $now`, and staff with governance-body authority publish a toezegging by setting `publicatiedatum` (and withdraw by setting `depublicatiedatum`). Because the predicate sits on the live object, the public list SHALL reflect lifecycle changes (e.g. `afgedaan`) without republication. The `Toezegging` schema SHALL NOT carry internal-only fields (no confidential remarks property), so the whole object is publishable by construction. The system SHALL NOT serve app-local anonymous pages for toezeggingen. Publication SHALL never happen without an explicit staff action.

#### Scenario: Published commitment is anonymously readable

- GIVEN a Toezegging published by the griffier (publicatiedatum in the past)
- WHEN an unauthenticated client reads the OR published-predicate surface
- THEN the toezegging is returned with its text, madeBy, deadline, and lifecycle

#### Scenario: Status change is live on the public list

- GIVEN a published Toezegging in lifecycle `open`
- WHEN the griffier sets it to `afgedaan`
- THEN the next anonymous read shows lifecycle `afgedaan` without any republish step

#### Scenario: Unpublished commitment is not public

- GIVEN a Toezegging without `publicatiedatum`
- WHEN an unauthenticated client queries the published surface
- THEN the toezegging is not returned

### Requirement: REQ-006 List page, detail page, and CSV export

The system SHALL provide a Toezeggingen index page and a ToezeggingDetail page as manifest pages in a `src/manifest.d/` fragment (ADR-037), following existing manifest-v2 conventions (`register: decidesk`, `schema: toezegging`, columns for text, madeBy, directedTo, deadline, lifecycle; quick filters on lifecycle and governance body). The index SHALL support CSV export via `ExportService` + `CnMassExportDialog` including madeBy, meeting date, deadline, lifecycle, and afdoening note.

#### Scenario: Griffier browses and filters the toezeggingenlijst

- GIVEN registered toezeggingen across two governance bodies
- WHEN the griffier opens the Toezeggingen page and filters on lifecycle `open`
- THEN only open toezeggingen are listed
- AND clicking a row opens the ToezeggingDetail page showing all fields, the linked meeting/agenda item, and the related motion when set

#### Scenario: Export toezeggingenlijst to CSV

- GIVEN a filtered toezeggingen list
- WHEN the griffier exports via the mass-export dialog
- THEN a CSV downloads containing the commitment text, portefeuillehouder, meeting date, deadline, lifecycle, and afdoening note

### Requirement: REQ-007 Dashboard KPI for overdue open commitments

The Dashboard manifest page SHALL carry a declarative stat widget "Open toezeggingen over deadline" counting Toezegging objects in a non-terminal lifecycle whose `deadline` lies in the past, sourced via the manifest widget aggregation (`register: decidesk`, `schema: toezegging`, `metric: count`) — no imperative counting endpoint. The widget SHALL route to the Toezeggingen index pre-filtered to the same set.

#### Scenario: KPI counts only overdue non-terminal commitments

- GIVEN three toezeggingen past their deadline in lifecycle `open`/`in-uitvoering`, one past-deadline `afgedaan`, and one future-deadline `open`
- WHEN the dashboard renders
- THEN the KPI shows 3
- AND clicking it opens the Toezeggingen index filtered to the overdue set

## Non-Functional Requirements

- **Performance:** the toezeggingenlijst index paginates via the standard OR list API; the KPI is a single count aggregation (no N+1).
- **Accessibility:** Target WCAG 2.2 AA; pages use standard manifest-v2 components (index/detail/stat) which carry the fleet's gate-checked semantics; the 6 NEW-in-2.2 SCs are n/a beyond what the shared components already cover (no dragging, no auth flows, no new help surfaces introduced by this capability).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] Toezegging schema registers from a register.d fragment and validates required fields
- [ ] Lifecycle transitions are enforced by x-openregister-lifecycle only (no app-side state machine)
- [ ] Rappel notifications fire declaratively before and after deadline, and never for terminal states
- [ ] Published toezeggingen are anonymously readable through the OR predicate surface; unpublished ones are not
- [ ] Index/detail pages render from the manifest fragment; CSV export works
- [ ] Dashboard KPI counts overdue non-terminal toezeggingen and deep-links to the filtered list
- [ ] No VTODO is created for a toezegging; action-item KPI is unaffected

## Notes

- Related: `motie-amendement-administratie` (UitvoeringsUpdate — referenced, not duplicated), `action-item-board-via-deck-leaf` (ActionItem = VTODO; toezegging explicitly out of that store), `public-publication` (predicate pattern; this capability uses predicate-on-live-object because the whole schema is publishable and the public list must be live).
- ORI/OpenRaadsinformatie defines no Toezegging type; the schema.org annotation `schema:Action` is used instead, consistent with the register's `x-schema-org` marker convention.
- Follow-up work a griffie derives from a toezegging (internal tasks) remains a VTODO via the existing action-item flow.
