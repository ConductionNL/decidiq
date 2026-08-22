# termijnagenda-register Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [termijnagenda](../../changes/termijnagenda/)

## Purpose

The termijnagenda (long-term agenda, LTA) is the forward-planning register of upcoming proposals and topics per governance body, jointly maintained by griffie and college: what the council can expect (raadsvoorstel, raadsinformatiebrief, themabijeenkomst, begrotingsstuk), in which period, owned by whom, and originating from which toezegging, motie, or earlier decision. It closes the accountability loop between commitments (`toezeggingen-register`), motion execution (`motie-amendement-administratie`), and the actual meeting agenda (`agenda-management`), and doubles as a public transparency instrument via the existing publication machinery (`public-publication`). Delivered thin-client per ADR-022/ADR-037: declarative dialects (ADR-031) plus manifest-v2 pages; scheduling into meetings stays manual/assistive.

## ADDED Requirements

### Requirement: REQ-LTA-001 TermijnagendaItem schema on OpenRegister

The system SHALL define a `TermijnagendaItem` schema in the decidesk register via the assigned fragment `lib/Settings/register.d/50-termijnagenda.json` (ADR-037; the base `decidesk_register.json` is never edited), annotated `x-schema-org: schema:PlanAction` (object = the expected item, scheduledTime derived from the planned period). The schema SHALL carry at minimum: `onderwerp` (subject, required), `governanceBody` (GovernanceBody reference, required), `plannedPeriod` (required, see REQ-LTA-002), `expectedType` (enum: `raadsvoorstel` | `raadsinformatiebrief` | `themabijeenkomst` | `begrotingsstuk`, required), `ownerType` (enum: `griffie` | `college` | `portefeuillehouder`, required), `owner` (Person reference, required when `ownerType` is `portefeuillehouder`, otherwise null), `toelichting` (free-text note, optional), `lifecycle` (required, see REQ-LTA-003), `verschuifHistorie` (shift history array, see REQ-LTA-004), `redenVervallen` (optional), and realisation links per REQ-LTA-006. Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `termijnagenda-item`.

#### Scenario: Griffier plans an expected raadsvoorstel

- GIVEN the gemeenteraad governance body exists in the register
- WHEN the griffier creates a termijnagenda item "Herziening parkeerbeleid" with expectedType `raadsvoorstel`, plannedPeriod `2026-Q4`, and ownerType `college`
- THEN a TermijnagendaItem object is created in the decidesk register linked to the governance body
- AND the object validates against the schema (missing onderwerp, governanceBody, plannedPeriod, or expectedType is rejected by OpenRegister)

#### Scenario: Portefeuillehouder owner requires a person reference

- GIVEN a new termijnagenda item with ownerType `portefeuillehouder`
- WHEN it is saved without an `owner` Person reference
- THEN OpenRegister rejects the object per the schema's conditional requirement

#### Scenario: Register fragment is additive

- GIVEN a decidiq installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the TermijnagendaItem schema is registered from fragment 50
- AND no existing schema in `decidesk_register.json` is modified

### Requirement: REQ-LTA-002 Planned period at month or quarter granularity

The `plannedPeriod` property SHALL be a single string validated by the schema pattern accepting exactly two forms: quarter `YYYY-Qn` (n in 1–4, e.g. `2026-Q4`) or month `YYYY-MM` (e.g. `2026-11`). The system SHALL order mixed-granularity periods chronologically by a single documented sort-key convention (design.md) and SHALL treat an item as *overdue* when the last day of its planned period lies in the past while the item is non-terminal and has no realisation link. Free-form period text SHALL be rejected by schema validation.

#### Scenario: Month and quarter items sort chronologically together

- GIVEN items planned in `2026-11`, `2026-Q4`, and `2027-Q1`
- WHEN the board or list orders by period
- THEN they appear in chronological order with `2026-11` inside/before the end of `2026-Q4` and `2027-Q1` last, per the documented sort-key convention

#### Scenario: Invalid period rejected

- GIVEN a termijnagenda item being saved with plannedPeriod `november 2026`
- WHEN OpenRegister validates the object
- THEN the save is rejected by the schema pattern

#### Scenario: Overdue definition

- GIVEN today is 2027-01-15 and an item planned `2026-Q4` in lifecycle `gepland` with no realisation link
- WHEN overdue status is evaluated
- THEN the item is overdue
- AND an item planned `2026-Q4` in lifecycle `gerealiseerd` is not overdue

### Requirement: REQ-LTA-003 Lifecycle is declarative with shift and settle states

The `TermijnagendaItem` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`states`-only/`default`): field `lifecycle`, initial `gepland`, transitions `gepland → verschoven | gerealiseerd | vervallen`, `verschoven → verschoven | gerealiseerd | vervallen` (repeat shifts allowed), with `gerealiseerd` and `vervallen` terminal. Entering `vervallen` SHALL require `redenVervallen`. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Item is shifted and later realised

- GIVEN a TermijnagendaItem in lifecycle `gepland`
- WHEN the griffier reschedules it (per REQ-LTA-004) and later marks it `gerealiseerd` with a link to the actual agenda item
- THEN both transitions are accepted per the declared transition map and the object ends terminal

#### Scenario: Dropping an item requires a reason

- GIVEN a TermijnagendaItem in lifecycle `gepland`
- WHEN a user sets it to `vervallen` without `redenVervallen`
- THEN OpenRegister rejects the save
- AND with a reason ("Onderwerp opgegaan in omgevingsvisie") the transition is accepted

#### Scenario: Terminal states are final

- GIVEN a TermijnagendaItem in lifecycle `gerealiseerd`
- WHEN any user attempts to set the lifecycle back to `gepland`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-LTA-004 Every reschedule records a mandatory reason and preserves full shift history

Rescheduling a TermijnagendaItem SHALL (a) set the lifecycle to `verschoven`, (b) update `plannedPeriod` to the new period, and (c) append an entry to `verschuifHistorie` containing the previous period, the new period, the mandatory reason, the acting user, and a timestamp. The history array SHALL be append-only from the UI (existing entries are never edited or removed by the reschedule flow) and SHALL be visible on the item's detail page. A reschedule without a reason SHALL NOT be saveable through any UI path, including drag-and-drop (REQ-LTA-005).

#### Scenario: Reschedule appends to history

- GIVEN an item planned `2026-Q4` in lifecycle `gepland` with an empty shift history
- WHEN the griffier reschedules it to `2027-Q1` with reason "Wacht op uitkomst regionale samenwerking"
- THEN the item is in lifecycle `verschoven` with plannedPeriod `2027-Q1`
- AND `verschuifHistorie` contains one entry with from `2026-Q4`, to `2027-Q1`, that reason, the acting user, and a timestamp

#### Scenario: Second shift preserves the first

- GIVEN the item from the previous scenario
- WHEN it is rescheduled again to `2027-Q2` with a reason
- THEN `verschuifHistorie` contains two entries in chronological order and the first entry is unchanged

### Requirement: REQ-LTA-005 Per-body board view with drag-to-reschedule

The system SHALL provide a Termijnagenda board page (manifest fragment `src/manifest.d/`, ADR-037) that groups items of one selected governance body into period columns ordered chronologically (REQ-LTA-002 sort key). Dragging a card to another period column SHALL open a reason dialog (its own component per the modal-isolation convention); confirming SHALL perform the reschedule per REQ-LTA-004 via the standard object store (PUT-semantic saveObject carrying all fields forward); cancelling SHALL restore the card to its original column with no object write. The board SHALL offer a keyboard-accessible reschedule alternative to dragging (WCAG 2.2 — 2.5.7 Dragging Movements).

#### Scenario: Drag to a later quarter

- GIVEN the board for the gemeenteraad shows an item in column `2026-Q4`
- WHEN the griffier drags the card to column `2027-Q1` and confirms the reason dialog
- THEN the item moves to the `2027-Q1` column, is in lifecycle `verschoven`, and the shift history has a new entry

#### Scenario: Cancelled drag writes nothing

- GIVEN the same board
- WHEN the griffier drags a card to another column and cancels the reason dialog
- THEN the card returns to its original column
- AND no save request is issued for the object

#### Scenario: Reschedule without dragging

- GIVEN a keyboard-only user on the board
- WHEN they invoke the card's reschedule action and pick the target period and reason in the dialog
- THEN the same reschedule is performed as via drag-and-drop

### Requirement: REQ-LTA-006 Realisation linkage to the actual agenda item or decision

Marking an item `gerealiseerd` SHALL support linking it to the actual `AgendaItem` and/or `Decision` object that realised it (`realisedAgendaItem`, `realisedDecision` — nullable references). When an agenda item is scheduled for the same governance body, the realisation dialog SHOULD offer matching agenda items as suggestions (assistive auto-link); the link is always confirmed by a user — the system SHALL NOT create, move, or modify agenda items or meetings itself (scheduling into meetings is out of scope). Origin links (`originToezegging` → `toezegging`, `originMotie` → `Decision` of `decisionType: motion`, `originDecision` → `Decision` — all nullable) SHALL render as navigable references on the detail page; execution narrative for a linked motion SHALL remain exclusively on that motion's UitvoeringsUpdate log — the termijnagenda SHALL NOT duplicate it.

#### Scenario: Realise with an assistive suggestion

- GIVEN an item "Herziening parkeerbeleid" planned for the gemeenteraad and an AgendaItem "Raadsvoorstel herziening parkeerbeleid" on an upcoming raadsvergadering of that body
- WHEN the griffier marks the item `gerealiseerd`
- THEN the dialog suggests that agenda item and, after confirmation, `realisedAgendaItem` references it
- AND no meeting or agenda item was created or modified by the termijnagenda

#### Scenario: Origin links navigate without duplication

- GIVEN an item created from a toezegging and a motion (originToezegging and originMotie set)
- WHEN a user opens the item's detail page
- THEN both origins render as navigable references
- AND no execution-update objects exist on the termijnagenda side

### Requirement: REQ-LTA-007 List view and CSV export

The system SHALL provide a Termijnagenda index (list) page as a manifest page (`register: decidesk`, `schema: termijnagenda-item`; columns for onderwerp, governanceBody, plannedPeriod, expectedType, owner, lifecycle; quick filters on governance body, lifecycle, expectedType, and period). The index SHALL support CSV export via `ExportService` + `CnMassExportDialog` including onderwerp, governance body, planned period, expected type, owner, lifecycle, shift count, and reason fields.

#### Scenario: Filter and export the termijnagenda

- GIVEN items across two governance bodies and mixed lifecycles
- WHEN the griffier filters on the gemeenteraad and lifecycle `gepland`/`verschoven` and exports via the mass-export dialog
- THEN only matching rows are listed and the downloaded CSV contains those items with onderwerp, period, expected type, owner, and lifecycle
- AND clicking a row opens the TermijnagendaItem detail page with shift history, origin links, and realisation links

### Requirement: REQ-LTA-008 Period-arrival rappel is a declarative notification

Rappels SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on the `TermijnagendaItem` schema: a scheduled trigger that notifies the item's owner (the referenced Person for `portefeuillehouder` items; the griffie group for `griffie`/`college` items) and the griffie group when an item's planned period has arrived while the item is non-terminal and has no realisation link, with Dutch and English subjects. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Rappel when the period arrives unrealised

- GIVEN an item planned `2026-11` in lifecycle `gepland` with no realisation link
- WHEN the scheduled notification trigger evaluates on/after 1 November 2026
- THEN the owner and the griffie recipients receive a Nextcloud notification referencing the item

#### Scenario: No rappel for realised or dropped items

- GIVEN items planned in the current period in lifecycle `gerealiseerd` and `vervallen`, and a `gepland` item with `realisedAgendaItem` set
- WHEN the scheduled notification trigger evaluates
- THEN none of them produce a rappel

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- WHEN the notification-dialect gate scans the termijnagenda code paths
- THEN no imperative object-notification dispatch exists; all rappels are declarative rules in the register fragment

### Requirement: REQ-LTA-009 Public termijnagenda via the OR published-predicate

The system SHALL make the termijnagenda publicly readable through OpenRegister's anonymous RBAC published-predicate surface: the `TermijnagendaItem` schema declares an `authorization.read` rule granting the `public` group read access while `publicatiedatum <= $now`; staff publish an item by setting `publicatiedatum` and withdraw by setting `depublicatiedatum`. Because the predicate sits on the live object, the public termijnagenda SHALL reflect reschedules and lifecycle changes without republication. The schema SHALL NOT carry internal-only fields, so the whole object is publishable by construction; shift reasons are part of the public record (transparency instrument). The system SHALL NOT serve app-local anonymous pages. Publication SHALL never happen without an explicit staff action.

#### Scenario: Published item is anonymously readable and live

- GIVEN a published termijnagenda item (publicatiedatum in the past) planned `2026-Q4`
- WHEN it is rescheduled to `2027-Q1` and an unauthenticated client reads the OR published-predicate surface
- THEN the item is returned with plannedPeriod `2027-Q1`, lifecycle `verschoven`, and its shift history, without any republish step

#### Scenario: Unpublished item is not public

- GIVEN a termijnagenda item without `publicatiedatum`
- WHEN an unauthenticated client queries the published surface
- THEN the item is not returned

### Requirement: REQ-LTA-010 Dashboard KPI for overdue termijnagenda items

The Dashboard manifest page SHALL carry a declarative stat widget "Termijnagenda over termijn" counting TermijnagendaItem objects that are overdue per REQ-LTA-002 (planned period in the past, non-terminal lifecycle, no realisation link), sourced via the manifest widget aggregation (`register: decidesk`, `schema: termijnagenda-item`, `metric: count`) — no imperative counting endpoint. The widget SHALL route to the Termijnagenda index pre-filtered to the same set.

#### Scenario: KPI counts only overdue open items

- GIVEN two overdue items in lifecycle `gepland`/`verschoven`, one past-period `gerealiseerd` item, and one future-period `gepland` item
- WHEN the dashboard renders
- THEN the KPI shows 2
- AND clicking it opens the Termijnagenda index filtered to the overdue set

## Non-Functional Requirements

- **Performance:** the board loads one governance body's items in a single filtered OR list query; the index paginates via the standard OR list API; the KPI is a single count aggregation (no N+1).
- **Accessibility:** Target WCAG 2.2 AA; drag-to-reschedule has a full keyboard/pointer-free alternative (SC 2.5.7); reason dialogs use the shared modal components with focus management; manifest-v2 index/detail/stat components carry the fleet's gate-checked semantics.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] TermijnagendaItem schema registers from fragment 50, validates required fields, the period pattern, and the conditional owner reference
- [ ] Lifecycle transitions are enforced by x-openregister-lifecycle only; vervallen requires a reason; terminals are final
- [ ] Every reschedule (drag or dialog) records reason + from/to/actor/timestamp in an append-only shift history
- [ ] Board groups one body's items by period, drag-to-reschedule works with a keyboard alternative, cancel writes nothing
- [ ] Realisation links to AgendaItem/Decision are assistive and manual; no meeting or agenda item is ever created/modified by this capability
- [ ] Rappels fire declaratively when a period arrives unrealised, never for terminal/realised items
- [ ] Published items are anonymously readable and live through the OR predicate surface; unpublished ones are not
- [ ] Index/detail pages render from the manifest fragment; CSV export works
- [ ] Dashboard KPI counts overdue open items and deep-links to the filtered list

## Notes

- Related: `toezeggingen-register` (origin toezegging; rappel/lifecycle/KPI dialect patterns mirrored), `motie-amendement-administratie` (origin motie; UitvoeringsUpdate referenced, never duplicated), `agenda-management`/`meeting-series` (realisation targets and period vocabulary), `public-publication` (predicate-on-live-object carve-out, same rationale as the toezeggingenlijst), `governance-bodies` (per-body scoping).
- ORI/OpenRaadsinformatie defines no termijnagenda type; the schema.org annotation `schema:PlanAction` is used, consistent with the register's `x-schema-org` marker convention.
- Out of scope by design: automatic scheduling into meetings and college-internal project management (see proposal).
