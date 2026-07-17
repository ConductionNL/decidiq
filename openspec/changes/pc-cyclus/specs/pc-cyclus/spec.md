# pc-cyclus Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- [pc-cyclus](../../changes/pc-cyclus/)

## Purpose

The Planning & Control cyclus: the recurring annual decision/document cycle a governance body runs — municipal kadernota → programmabegroting → bestuursrapportage(s) → jaarrekening, and the association/corporate analogue jaarplan → begroting → tussenrapportage → jaarstukken + decharge. A `PCCyclus` binds one governance body, one execution year, and one `CyclusTemplate`; `CyclusStap` objects are generated from the template and tracked from aanlevering through behandeling to vaststelling, with declarative rappels (ADR-031), a year-view timeline, and next-year generation. This capability owns the recurring document/decision cycle; `meeting-series` keeps owning recurring meetings, and statutory decision templates (VvE) stay in `vve-alv-pack`.

## ADDED Requirements

### Requirement: REQ-PCC-001 PCCyclus and CyclusTemplate schemas on OpenRegister

The system SHALL define `PCCyclus` and `CyclusTemplate` schemas in the decidesk register via the `lib/Settings/register.d/52-pc-cyclus.json` fragment (ADR-037, never editing `decidesk_register.json`), annotated `x-schema-org: schema:Schedule` (PCCyclus) and `schema:HowTo` (CyclusTemplate). `PCCyclus` SHALL carry at minimum: `name` (required), `year` (integer execution year, required), `governanceBody` (GovernanceBody reference, required), `template` (CyclusTemplate reference, required), and declarative progress aggregations `stepCount`, `completedStepCount` (steps in a terminal state), and `overdueStepCount` (non-terminal steps past `aanleverDeadline`), which consumers SHALL read and SHALL NOT recompute. `CyclusTemplate` SHALL carry: `name` (required), `context` (enum `legislative | association | corporate | operations`), `builtIn` (boolean, default false), and `steps[]` (required, ordered) where each template step declares `stepType`, `label`, default dates (month/day for aanlever-deadline, technische-vragen window, behandeling targets), `subjectYearOffset` (integer, default 0), and `documentSlots[]` (each with `name` and `required`). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference schemas by slug (`pc-cyclus`, `cyclus-template`, `cyclus-stap`).

#### Scenario: Griffier creates a P&C cyclus for a year

- GIVEN the municipal built-in template and the seeded gemeenteraad governance body
- WHEN the griffier creates a PCCyclus with year 2026, that body, and that template
- THEN a `pc-cyclus` object is created in the decidesk register referencing the body and template
- AND omitting the year, body, or template is rejected by OpenRegister schema validation

#### Scenario: Register fragment is additive

- GIVEN a decidesk installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the three schemas register from the `52-pc-cyclus.json` fragment
- AND no existing schema in `decidesk_register.json` is modified

### Requirement: REQ-PCC-002 Built-in cycle templates follow the process-configuration pattern

The system SHALL ship two built-in `CyclusTemplate` seeds via the register fragment's seed-data path, following the process-configuration built-in-template pattern: `municipal-pc-cyclus` (kadernota, berap-1, berap-2, programmabegroting, jaarrekening) and `association-jaarstukken` (jaarplan, begroting, tussenrapportage, jaarstukken, decharge). Each built-in SHALL carry `builtIn: true`, a complete step list with realistic default dates and subject-year offsets, and SHALL be read-only: edit and delete of a built-in template SHALL be refused, while duplication into an editable copy (fresh slug, `builtIn` cleared) SHALL be allowed. Custom templates SHALL be freely editable.

#### Scenario: Fresh install has usable templates

- GIVEN a fresh decidesk installation
- WHEN the user creates a PCCyclus and selects a template
- THEN both `municipal-pc-cyclus` and `association-jaarstukken` are available with their seeded step lists, immediately usable without configuration

#### Scenario: Built-in template is read-only but duplicable

- GIVEN the built-in `municipal-pc-cyclus` template
- WHEN a user attempts to edit or delete it
- THEN the operation is refused
- AND duplicating it yields an editable copy with a fresh slug and `builtIn` cleared, leaving the original unchanged

### Requirement: REQ-PCC-003 CyclusStap schema with typing, deadlines, behandeling targets, and document slots

The system SHALL define a `CyclusStap` schema (annotated `x-schema-org: schema:Action`) related to its parent `PCCyclus` (one cyclus → many steps, ordered by `sequence`). Each CyclusStap SHALL carry: `sequence` (required), `stepType` (string with canonical values `kadernota`, `begroting`, `berap`, `jaarrekening`, `jaarplan`, `jaarstukken`, `decharge` — extensible: templates MAY declare custom step types, so the property SHALL NOT be a closed enum), `label` (required), `betreftJaar` (subject year, derived at generation from `year + subjectYearOffset`), `aanleverDeadline` (date — when the organisation must deliver the documents), `technischeVragenStart` and `technischeVragenEind` (dates — the technical-questions window), `behandelendeCommissie` (GovernanceBody reference, optional) with `commissieDatum` (target date, optional), `besluitvormendOrgaan` (GovernanceBody reference — the raad/ALV) with `behandelingDatum` (target date), `documentSlots[]` (copied from the template step; each slot carries `name`, `required`, and its delivered file attached via OpenRegister's FileService — no app-local file storage), `agendaItem` (AgendaItem reference, optional — set once behandeling is scheduled), `decision` (Decision reference, optional — set once the vaststellingsbesluit exists), `status` (required, see REQ-PCC-005), and `outcome` (string, optional — e.g. `decharge-verleend`). Steps SHALL NOT carry financial figures or monetary properties.

#### Scenario: Generated begroting step carries the full behandeling shape

- GIVEN a municipal PCCyclus for 2026
- WHEN its programmabegroting step is inspected
- THEN it has `stepType: begroting`, `betreftJaar: 2027`, an aanlever-deadline, a technische-vragen window, an optional commissie target and a raad target date, and the document slots declared by the template
- AND its documents attach via FileService on the step object

#### Scenario: Custom step type from a duplicated template

- GIVEN a duplicated template where the administrator added a step with custom `stepType: kaderbrief`
- WHEN a cyclus is generated from that template
- THEN the step is created with `stepType: kaderbrief` and validates against the schema (the canonical list is not a closed enum)

### Requirement: REQ-PCC-004 Steps are generated from the template

When a `PCCyclus` is created, the system SHALL generate one `CyclusStap` per template step: concrete dates SHALL be resolved from the template step's default month/day within the cyclus `year`, `betreftJaar` SHALL be `year + subjectYearOffset`, document slots SHALL be copied, and all steps SHALL start in status `gepland` with `sequence` following the template order. Generated steps SHALL be individually editable (dates, targets, slots) without affecting the template or sibling steps — mirroring the meeting-series rule that editing an instance never mutates the template.

#### Scenario: Municipal cyclus generation

- GIVEN the built-in `municipal-pc-cyclus` template with five steps
- WHEN the griffier creates a PCCyclus for year 2026 from it
- THEN five CyclusStap objects are created in template order, all `gepland`, with concrete 2026 dates, `betreftJaar` 2027 for kadernota/begroting, 2026 for the beraps, and 2025 for the jaarrekening

#### Scenario: Editing one step leaves template and siblings untouched

- GIVEN a generated cyclus
- WHEN the griffier moves the kadernota behandeling date two weeks later
- THEN only that step changes; the template and the other steps are unmodified

### Requirement: REQ-PCC-005 Step lifecycle is declarative

The `CyclusStap` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`states`-only/`default`): field `status`, initial `gepland`, transitions `gepland → stukken-ontvangen → in-behandeling → vastgesteld | afgerond`, plus `stukken-ontvangen → afgerond` (steps without a formal vaststelling, e.g. a berap ter kennisname), with `vastgesteld` and `afgerond` terminal. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Begroting step runs the full lifecycle

- GIVEN a begroting step in `gepland`
- WHEN the stukken arrive, behandeling starts, and the raad adopts the begroting
- THEN the step moves `gepland → stukken-ontvangen → in-behandeling → vastgesteld` with each transition accepted by the declared map

#### Scenario: Invalid transition rejected declaratively

- GIVEN a step in `vastgesteld`
- WHEN any user attempts to set the status back to `gepland`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-PCC-006 Deadline rappels are declarative notifications

Rappels SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on the `CyclusStap` schema, with Dutch and English subjects: (1) **aanlevering late** — a scheduled trigger when a step is still `gepland` and its `aanleverDeadline` is past, notifying the griffie group; (2) **behandeling approaching unscheduled** — a scheduled trigger when a non-terminal step's `behandelingDatum` lies within the rappel window and its `agendaItem` reference is empty, notifying the griffie group. No rappel SHALL fire for steps in a terminal status. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Late aanlevering rappel

- GIVEN a step in `gepland` whose aanlever-deadline was last week
- WHEN the scheduled notification trigger evaluates
- THEN the griffie recipients receive a notification referencing the step and its cyclus

#### Scenario: Behandeling approaching without an agenda item

- GIVEN a step in `stukken-ontvangen` whose behandelingDatum is within the rappel window and whose `agendaItem` is empty
- WHEN the scheduled trigger evaluates
- THEN an "behandeling not yet scheduled" notification is sent
- AND once an agendaItem is linked or the step is terminal, no further rappel fires

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- WHEN the notification-dialect gate scans the pc-cyclus code paths
- THEN no imperative object-notification dispatch exists; all rappels are declarative rules in the register fragment

### Requirement: REQ-PCC-007 Behandeling links to the real AgendaItem and Decision

A CyclusStap SHALL link to the actual behandeling once it exists: when the item is placed on a meeting agenda, the step's `agendaItem` reference SHALL point to that AgendaItem; when the vaststellingsbesluit is taken, the step's `decision` reference SHALL point to that Decision. The step SHALL NOT create meetings, agenda items, or decisions itself — scheduling stays with the agenda-management flow and decision content stays with the decision-management/decision-route model. The step detail SHALL render both links as navigable references.

#### Scenario: Begroting behandeling scheduled and decided

- GIVEN a begroting step in `stukken-ontvangen`
- WHEN the griffier links the AgendaItem of the begrotingsraad and, after the vote, the vaststellingsbesluit Decision
- THEN the step shows both as navigable references and no duplicate agenda or decision object is created by this capability

### Requirement: REQ-PCC-008 Decharge is a step outcome, not a decision template

For a `decharge` step, the granting of decharge SHALL be recorded as the step's `outcome` (`decharge-verleend` or `decharge-geweigerd`) with the resulting besluit living in the normal Decision model via the step's `decision` reference. This capability SHALL NOT define statutory decision templates; VvE-specific statutory decision templates are owned by the sibling change `vve-alv-pack`.

#### Scenario: ALV grants decharge

- GIVEN an association cyclus whose jaarstukken step is `vastgesteld`
- WHEN the ALV grants decharge and the decharge step is resolved
- THEN the step's outcome is `decharge-verleend`, its `decision` references the besluit in the Decision model, and no decision-template object is created by this capability

### Requirement: REQ-PCC-009 Year-view timeline per governance body

The system SHALL provide a PCCycli index page and a cyclus detail page as manifest pages in a `src/manifest.d/pc-cyclus.json` fragment (ADR-037; `register: decidesk`, schema refs by slug). The index SHALL list cycli with columns for name, year, governance body, and progress, with quick filters on year and governance body. The cyclus detail SHALL render a **year-view timeline**: all steps of the cyclus in date order across the year, each showing step type, label, aanlever-deadline, technische-vragen window, behandeling target(s), status, and linked agenda item/decision, with overdue steps visually flagged and overall progress shown as `completedStepCount` of `stepCount` (from the REQ-PCC-001 declarative aggregations, never recomputed client-side). A step detail page SHALL show all step fields, document slots with their delivered files, and the behandeling links.

#### Scenario: Griffier reviews the year at a glance

- GIVEN the seeded municipal 2026 cyclus with mixed step statuses
- WHEN the griffier opens the cyclus detail
- THEN the timeline shows all five steps in date order with status, deadlines, and behandeling targets, the overdue step is flagged, and progress reads from the declarative counters

#### Scenario: Filter cycli by body and year

- GIVEN a municipal and an association cyclus
- WHEN the user filters the index on the gemeenteraad body and year 2026
- THEN only the municipal 2026 cyclus is listed and clicking it opens its detail

### Requirement: REQ-PCC-010 Dashboard KPI for steps past deadline

The Dashboard manifest page SHALL carry a declarative stat widget "P&C-stappen over deadline" counting CyclusStap objects in a non-terminal status whose `aanleverDeadline` lies in the past, sourced via the manifest widget aggregation (`register: decidesk`, `schema: cyclus-stap`, `metric: count`) — no imperative counting endpoint. The widget SHALL route to a pre-filtered step list.

#### Scenario: KPI counts only overdue non-terminal steps

- GIVEN two steps past their aanlever-deadline in `gepland`, one past-deadline `vastgesteld`, and one future-deadline `gepland`
- WHEN the dashboard renders
- THEN the KPI shows 2
- AND clicking it opens the step list filtered to the overdue set

### Requirement: REQ-PCC-011 Next-year generation with date shifting

The system SHALL generate the next year's cyclus from an existing one: a `generateNextYear` action creates a new PCCyclus for `year + 1` on the same body and template, with every step's dates shifted forward one year **from the source cyclus's actual (possibly customised) dates**, `betreftJaar` recomputed, document slots reset to empty, and all steps starting in `gepland`. The source cyclus SHALL be unmodified. Generation SHALL be refused when a cyclus for that body and year already exists.

#### Scenario: Generate 2027 from the customised 2026 cyclus

- GIVEN the 2026 municipal cyclus where the kadernota behandeling was moved two weeks later
- WHEN the griffier generates the next year
- THEN a 2027 cyclus is created with all step dates shifted +1 year including the moved kadernota date, empty document slots, all steps `gepland`, and the 2026 cyclus unchanged

#### Scenario: Duplicate year refused

- GIVEN an existing 2027 cyclus for the gemeenteraad
- WHEN the griffier triggers next-year generation from the 2026 cyclus again
- THEN the action is refused with a clear error and no objects are created

## Non-Functional Requirements

- **Performance:** the cycli index and step lists paginate via the standard OR list API; progress counters and the KPI are declarative aggregations (no N+1); a cyclus generation writes at most the template's step count of objects in one action.
- **Accessibility:** Target WCAG 2.2 AA; the year-view timeline is keyboard-navigable and does not rely on colour alone for overdue flagging; manifest pages use the fleet's gate-checked shared components.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] Three schemas register from `register.d/52-pc-cyclus.json` and validate required fields
- [ ] Both built-in templates seed on fresh install, are read-only, and duplicate into editable copies
- [ ] Cyclus creation generates steps with correct dates, subject years, slots, and `gepland` status
- [ ] Step lifecycle is enforced by x-openregister-lifecycle only (no app-side state machine)
- [ ] Rappels fire declaratively for late aanlevering and unscheduled behandeling, never for terminal steps
- [ ] Year-view timeline renders with overdue flagging and declarative progress; dashboard KPI counts overdue non-terminal steps and deep-links
- [ ] Next-year generation shifts customised dates, resets slots, and refuses duplicates
- [ ] No meeting/agenda/decision objects are created by this capability; decharge besluit lives in the Decision model

## Notes

- Related: `meeting-series` (owns recurring *meetings*; this capability deliberately does not reuse `seriesPattern` — a cycle is a curated step list per year, not a recurrence of one event — but next-year generation mirrors its "regenerate without mutating customised instances" semantics), `process-configuration` (built-in-template pattern followed for CyclusTemplate), `decision-route`/`decision-management` (behandeling and besluit linkage), `toezeggingen-register` (rappel dialect precedent), `agenda-management` (AgendaItem linkage), `vve-alv-pack` (owns VvE statutory decision templates — hard boundary).
- ORI/OpenRaadsinformatie defines no P&C-cycle type; schema.org annotations `schema:Schedule` / `schema:HowTo` / `schema:Action` follow the register's `x-schema-org` marker convention.
