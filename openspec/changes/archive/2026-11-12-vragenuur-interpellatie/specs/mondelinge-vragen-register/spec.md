# mondelinge-vragen-register Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [vragenuur-interpellatie](../../changes/vragenuur-interpellatie/)

## Purpose

Oral questions (mondelinge vragen) for the vragenuur as first-class objects: a raadslid submits a question to a portefeuillehouder before a per-body configurable deadline, the chair/griffier admits or rejects it, admitted questions are scheduled into the vragenuur agenda item of the target meeting, and the spoken answer is recorded with optional follow-ups (a toezegging in the sibling toezeggingen register, or a follow-up written question). Complements `SchriftelijkeVraag` (written questions, `fractievoorzitter-fractie-koppeling`) with bidirectional escalation linkage; implements the vragenrecht side of Gemeentewet art. 155 / the local Reglement van Orde. The live speaking-time clock stays with `digital-meetings-and-recurrence` REQ-STM.

## ADDED Requirements

### Requirement: REQ-VRI-001 MondelingeVraag schema on OpenRegister

The system SHALL define a `MondelingeVraag` schema in the decidesk register via the assigned fragment `lib/Settings/register.d/49-vragenuur-interpellatie.json` (ADR-037, never by editing `decidesk_register.json`), annotated `x-schema-org: schema:Question` (the recorded answer maps to `schema:Answer`/`acceptedAnswer`; in Akoma Ntoso terms the question and its oral answer are `question`/`answer` debate elements). The schema SHALL carry at minimum: `vraagNummer` (string, format `MV-{jaar}-{volgnummer}`, auto-assigned, mirroring the `SV-{jaar}-{volgnummer}` pattern), `indiener` (Raadslid/Person reference, required), `fractie` (Fractie reference, required), `onderwerp` (string, required, max 200), `motivering` (rich text, required), `portefeuillehouder` (Person reference — the addressed college member, required), `governanceBody` (GovernanceBody reference, required), `targetMeeting` (Meeting reference, required at submission), `vragenuurAgendaItem` (AgendaItem reference, set at scheduling), `volgorde` (integer, order within the vragenuur), `lifecycle` (required), `afwijzingsReden` (string, required when rejected), `antwoordSamenvatting` (rich text — summary of the spoken answer), `beantwoordDoor` (Person reference), `vervolgToezegging` (Toezegging reference, optional), `vervolgSchriftelijkeVraag` (SchriftelijkeVraag reference, optional), and `bronSchriftelijkeVraag` (SchriftelijkeVraag reference, optional — set when a written question escalates to oral treatment). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `mondelinge-vraag`.

#### Scenario: Raadslid submits an oral question for the next vragenuur

- GIVEN a raadslid with an active fractie membership and an upcoming raadsvergadering with a vragenuur
- WHEN the raadslid submits an oral question with onderwerp, motivering, the addressed portefeuillehouder, and the target meeting
- THEN a MondelingeVraag object is created in the decidesk register with an auto-assigned number `MV-{jaar}-{volgnummer}` (sequence per governance body per year)
- AND the object validates against the schema (missing onderwerp, motivering, or portefeuillehouder is rejected by OpenRegister)

#### Scenario: Register fragment is additive

@e2e exclude register-config contract — covered by PHPUnit on ConfigurationService import, not a UI flow
- GIVEN a decidiq installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the MondelingeVraag, Interpellatieverzoek, and VragenuurConfiguratie schemas are registered from fragment 49
- AND no existing schema in `decidesk_register.json` is modified

### Requirement: REQ-VRI-002 Declarative lifecycle with admission and scheduling states

The `MondelingeVraag` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`states`-only/`default`): field `lifecycle`, initial `ingediend`, states `ingediend → toegelaten | afgewezen`, `toegelaten → ingepland`, `ingepland → beantwoord | niet-behandeld`, `niet-behandeld → ingepland` (re-scheduled to a later vragenuur), and `ingediend | toegelaten | ingepland | niet-behandeld → ingetrokken`, with `beantwoord`, `afgewezen`, and `ingetrokken` terminal. The admission decision (`toegelaten`/`afgewezen`) SHALL be recorded by the chair or griffier; rejection SHALL carry an `afwijzingsReden`. Transition authority SHALL be enforced via OR RBAC on the schema (raadsleden create; griffie/chair update), not via an imperative app-side state machine.

#### Scenario: Griffier admits a question

- GIVEN a MondelingeVraag in lifecycle `ingediend`
- WHEN the griffier sets it to `toegelaten`
- THEN the transition is accepted and the question becomes schedulable

#### Scenario: Chair rejects a question with a reason

- GIVEN a MondelingeVraag in lifecycle `ingediend`
- WHEN the chair sets it to `afgewezen` with afwijzingsReden "Betreft een individuele casus, geen algemeen belang"
- THEN the object is terminal and the reason is stored and visible to the indiener

#### Scenario: Invalid transition rejected declaratively

@e2e exclude lifecycle-map contract — covered by Newman against the OR objects endpoint
- GIVEN a MondelingeVraag in lifecycle `beantwoord`
- WHEN any user attempts to set the lifecycle back to `ingediend`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-VRI-003 Per-body vragenuur configuration and submission deadline

The system SHALL define a `VragenuurConfiguratie` schema (same fragment, slug `vragenuur-configuratie`, annotated `x-schema-org: schema:StructuredValue`) with at most one object per governance body: `governanceBody` (reference, required), `indieningstermijnUren` (integer, default 24 — hours before the target meeting's start by which oral questions must be submitted), `interpellatieSteunDrempelType` (enum `breukdeel` | `aantal`), and `interpellatieSteunDrempelWaarde` (number, e.g. `0.2` for 1/5 of members per Reglement van Orde, or an absolute count). At submission the system MUST compute the deadline as `targetMeeting.start − indieningstermijnUren` and refuse late submissions server-side (this cross-object comparison is a justified imperative spot per the ADR-031 decision table in design.md); the griffier SHALL be able to override a late submission explicitly.

#### Scenario: Late submission refused

- GIVEN a body whose VragenuurConfiguratie sets indieningstermijnUren to 24 and a raadsvergadering starting in 3 hours
- WHEN a raadslid submits an oral question targeting that meeting
- THEN the submission is refused server-side with a message naming the passed deadline
- AND no MondelingeVraag object is created

#### Scenario: Griffier override for a late but urgent question

- GIVEN the same passed deadline
- WHEN the griffier submits the question on the raadslid's behalf with the explicit override flag
- THEN the MondelingeVraag is created and the override is visible in the object's audit trail

### Requirement: REQ-VRI-004 Scheduling into the vragenuur agenda item

The system SHALL let the griffier schedule a `toegelaten` question into the vragenuur `AgendaItem` of the target meeting by setting `vragenuurAgendaItem` and `volgorde` and transitioning the lifecycle to `ingepland`. The vragenuur agenda item is an ordinary agenda item managed by `agenda-item-crud`; this capability only references it. A question not reached during the vragenuur SHALL be set to `niet-behandeld`, after which it can be re-scheduled to a later vragenuur (new `targetMeeting`/`vragenuurAgendaItem`) or escalate to a written question (REQ-VRI-006). Per-fractie question time remains governed by `Fractie.vrageUrentijdMinuten` (`fractievoorzitter-fractie-koppeling`); the live clock is out of scope (REQ-STM).

#### Scenario: Griffier schedules an admitted question

- GIVEN a toegelaten MondelingeVraag targeting next week's raadsvergadering, which has a vragenuur agenda item
- WHEN the griffier assigns the question to that agenda item at position 2
- THEN the question becomes `ingepland` with `vragenuurAgendaItem` and `volgorde` set
- AND the vragenuur agenda-item detail lists the question at position 2

#### Scenario: Question not reached is carried over

- GIVEN an `ingepland` question whose vragenuur ended without treating it
- WHEN the griffier marks it `niet-behandeld` and re-schedules it to the next raadsvergadering's vragenuur
- THEN the question returns to `ingepland` with the new meeting and agenda-item references
- AND the earlier scheduling remains visible in the audit trail

### Requirement: REQ-VRI-005 Answer recording with toezegging follow-up linkage

The system SHALL record the outcome of an `ingepland` question by transitioning it to `beantwoord` with `antwoordSamenvatting` (summary of the spoken answer, required at this transition) and `beantwoordDoor`. When the portefeuillehouder makes a commitment during the answer, the griffier SHALL be able to register a toezegging in the sibling toezeggingen register (`toezeggingen-ingekomen-stukken`) pre-filled from the question (meeting, agenda item, portefeuillehouder as madeBy) and link it via `vervolgToezegging`; the toezegging's own lifecycle and afdoening stay entirely in that register — this capability SHALL NOT duplicate execution tracking. A follow-up written question MAY be linked via `vervolgSchriftelijkeVraag`.

#### Scenario: Answer with a commitment recorded during the vragenuur

- GIVEN an `ingepland` question about wachtlijsten jeugdzorg
- WHEN the griffier records the answer summary, sets beantwoordDoor to the wethouder, and registers a follow-up toezegging "raadsbrief vóór 1 maart"
- THEN the question is `beantwoord` with the summary stored
- AND `vervolgToezegging` references a new Toezegging object whose madeBy, meeting, and agendaItem match the question's context
- AND no execution-update log is created on the question itself

### Requirement: REQ-VRI-006 Escalation linkage between written and oral questions

The system SHALL support both escalation directions between `SchriftelijkeVraag` and `MondelingeVraag`. Written → oral: creating a MondelingeVraag from an open SchriftelijkeVraag SHALL pre-fill onderwerp, motivering, indiener, fractie, and portefeuillehouder and set `bronSchriftelijkeVraag`; when that oral question reaches `beantwoord`, the system SHALL set the linked SchriftelijkeVraag's status to `vervallen-door-mondelinge-beantwoording` (a value that schema already declares) via a PUT-semantic save that carries all other SV fields forward unchanged. Oral → written: from a `niet-behandeld` or `beantwoord` oral question, a follow-up SchriftelijkeVraag can be created pre-filled and linked via `vervolgSchriftelijkeVraag`; its answering workflow stays in `fractievoorzitter-fractie-koppeling`.

#### Scenario: Written question answered orally lapses

- GIVEN a SchriftelijkeVraag SV-2026-014 in status `ingediend` and a MondelingeVraag created from it (bronSchriftelijkeVraag set)
- WHEN the oral question is recorded as `beantwoord`
- THEN SV-2026-014's status becomes `vervallen-door-mondelinge-beantwoording`
- AND SV-2026-014's other fields (onderwerp, vraagTekst, antwoordTermijn) are unchanged after the save

#### Scenario: Unlinked oral question touches no written question

@e2e exclude negative side-effect contract — covered by PHPUnit on OralQuestionService
- GIVEN a MondelingeVraag without bronSchriftelijkeVraag
- WHEN it is recorded as `beantwoord`
- THEN no SchriftelijkeVraag object is modified

### Requirement: REQ-VRI-007 Declarative notifications for oral questions

The `MondelingeVraag` schema SHALL declare its notifications exclusively via the canonical `x-openregister-notifications` dialect (ADR-031; gate-18 hard-fails legacy/imperative dispatch): a `created` trigger confirming submission to the indiener and the griffie group; `updated` triggers on the admission decision notifying the indiener (including the afwijzingsReden on rejection) and the portefeuillehouder on admission; and an `updated` trigger on scheduling (`ingepland`) notifying the indiener and portefeuillehouder with the target meeting date. Subjects SHALL be provided in nl and en. The app SHALL NOT dispatch these notifications imperatively.

#### Scenario: Submission confirmation

@e2e exclude notification-dialect contract — covered by PHPUnit asserting the fragment's trigger declarations (gate-18 guards the dialect)
- GIVEN the schema's notification declarations
- WHEN a raadslid submits an oral question
- THEN the indiener and the griffie group receive a submission confirmation via the declared `created` trigger
- AND no imperative notification dispatch for this event exists in app code

#### Scenario: Rejection notifies with reason

- GIVEN an `ingediend` question
- WHEN the chair rejects it with an afwijzingsReden
- THEN the indiener receives a notification containing the reason via the declared `updated` trigger

### Requirement: REQ-VRI-008 List and detail pages for oral questions

The system SHALL provide a Mondelinge vragen index page (columns: vraagNummer, onderwerp, indiener, fractie, portefeuillehouder, targetMeeting, lifecycle; quick filters on lifecycle, fractie, and portefeuillehouder) and a MondelingeVraag detail page (all fields; navigable references to meeting, agenda item, bron/vervolg written question, and vervolgToezegging; Files leaf and audit-trail sidebar; submission, admission, scheduling, and answer actions in explicit dialogs) as manifest-v2 pages in a `src/manifest.d/` fragment rendered by `CnPageRenderer`, referencing the schema by slug `mondelinge-vraag`. No decidiq CRUD controllers SHALL be added for listing or reading (redundant-controller gate).

#### Scenario: Griffier works the vragenuur pipeline from the index

- GIVEN seeded oral questions in mixed lifecycles
- WHEN the griffier opens the Mondelinge vragen index and filters on lifecycle `ingediend`
- THEN only submitted questions awaiting an admission decision are shown
- AND opening a row shows the detail page with the admission action available

#### Scenario: Detail page links the question's context

- GIVEN a `beantwoord` question with a bronSchriftelijkeVraag and a vervolgToezegging
- WHEN a user opens its detail page
- THEN the written question and the toezegging render as navigable references
- AND the audit trail shows submission, admission, scheduling, and answering
