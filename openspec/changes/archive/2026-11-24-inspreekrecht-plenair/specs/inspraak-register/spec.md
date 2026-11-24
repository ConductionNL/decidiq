# inspraak-register Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [inspreekrecht-plenair](../../changes/inspreekrecht-plenair/)

## Purpose

One canonical, meeting-generic inspraak registration record for every meeting type whose governance body enables speaking rights — plenary raadsvergaderingen, ALVs, board meetings with public sessions, and (via adoption) commissievergaderingen. Generalizes the `InspraakAanmelding` defined by the `commissievergaderingen` change (REQ-CVG-009): same privacy field split (contactgegevens internal, onderwerp public), same status enum, but anchored on a generic `meeting` reference and governed by a per-body `inspraak-beleid` policy object. The public FORM stays with portaliq/`portal-contribution`; decidiq owns the API, moderation, and the record.

## ADDED Requirements

### Requirement: REQ-INS-001 Generalized inspraak-aanmelding schema on OpenRegister

The system SHALL define an `inspraak-aanmelding` schema in the decidesk register via the assigned fragment `lib/Settings/register.d/64-inspreekrecht-plenair.json` (ADR-037, never by editing `decidesk_register.json`), annotated `x-schema-org: schema:RegisterAction`. The schema SHALL preserve the two field groups from REQ-CVG-009 exactly: `contactgegevens` (object: `naam`, `email`, `telefoon`, `adres` — visible to griffie only, never published) and `onderwerp` (object: `sprekerNaam` (pseudonym allowed), `organisatie` (optional), `onderwerpTekst`, `spreektijdAanvraagMinuten` — publishable). It SHALL additionally carry: `meeting` (Meeting reference, required — any meeting type, since `CommissieVergadering` inherits from `Meeting`), `agendaItem` (AgendaItem reference — required when the body's policy `niveau` is `per-agendapunt`, absent for meeting-level inspraak), `governanceBody` (GovernanceBody reference, required), `status` (lifecycle field, REQ-INS-002), `afwijzingsReden` (string, required when `afgewezen`), `spreektijdToegewezenMinuten` (integer, set at approval, defaulting from the body policy), `volgorde` (integer, order among insprekers on the same item), `bijdrageTekst` (rich text, optional — REQ-INS-006), and `transcriptSegment` (TranscriptSegment reference, optional — REQ-INS-006). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `inspraak-aanmelding`.

#### Scenario: Citizen registers to speak on a plenary agenda item

- GIVEN a governance body with an `inspraak-beleid` object with `inspraakMogelijk: true` and `niveau: per-agendapunt`, and an upcoming raadsvergadering with a published agenda
- WHEN a registration is submitted via the decidiq API with contactgegevens, onderwerp fields, the meeting, and an agenda item
- THEN an `inspraak-aanmelding` object is created in the decidesk register with status `aangemeld`
- AND the object validates against the schema (missing sprekerNaam, onderwerpTekst, meeting, or governanceBody is rejected by OpenRegister)

#### Scenario: Register fragment is additive

@e2e exclude register-config contract — covered by PHPUnit on ConfigurationService import, not a UI flow
- GIVEN a decidiq installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the `inspraak-aanmelding` and `inspraak-beleid` schemas are registered from fragment 64
- AND no existing schema in `decidesk_register.json` is modified

#### Scenario: Single canonical definition — no duplicate in the commissie register

@e2e exclude cross-change coordination contract — asserted by PHPUnit on the loaded register configurations
- GIVEN the coordinated `commissievergaderingen` change is also installed
- WHEN both register configurations are loaded
- THEN exactly one `inspraak-aanmelding` schema definition exists (fragment 64), `commissievergaderingen_register.json` defines no InspraakAanmelding schema of its own
- AND commissie inspraak registrations reference a CommissieVergadering through the generic `meeting` reference

### Requirement: REQ-INS-002 Declarative lifecycle with post-approval field-group immutability

The `inspraak-aanmelding` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`states`-only/`default`): field `status`, initial `aangemeld`, transitions `aangemeld → goedgekeurd | afgewezen` and `goedgekeurd → gesproken | niet-verschenen`, with `afgewezen`, `gesproken`, and `niet-verschenen` terminal — the exact status enum from REQ-CVG-009, unchanged. Rejection SHALL carry an `afwijzingsReden`. The REQ-CVG-009 immutability constraint SHALL be scoped to the citizen-entered field groups: after `goedgekeurd`, `contactgegevens` and `onderwerp` are immutable, while status transitions and the griffie/chair-owned fields (`spreektijdToegewezenMinuten`, `volgorde`, `bijdrageTekst`, `transcriptSegment`) remain writable. Transition and field authority SHALL be enforced via OR RBAC on the schema (griffie moderates; chair marks outcome; citizens never update), not via an imperative app-side state machine.

#### Scenario: Griffier approves an aanmelding

- GIVEN an inspraak-aanmelding with status `aangemeld`
- WHEN the griffier sets it to `goedgekeurd` with a spreektijdToegewezenMinuten of 5
- THEN the transition is accepted and the aanmelding becomes an approved speaking slot

#### Scenario: Citizen field groups frozen after approval

@e2e exclude authorization contract — covered by Newman against the OR objects endpoint
- GIVEN an inspraak-aanmelding with status `goedgekeurd`
- WHEN any user attempts to change `onderwerp.sprekerNaam` or any `contactgegevens` field
- THEN OpenRegister rejects the write
- AND a later griffie write to `bijdrageTekst` on the same object is accepted

#### Scenario: Invalid transition rejected declaratively

@e2e exclude lifecycle-map contract — covered by Newman against the OR objects endpoint
- GIVEN an inspraak-aanmelding with status `gesproken`
- WHEN any user attempts to set the status back to `aangemeld`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-INS-003 Per-body inspraak policy schema

The system SHALL define an `inspraak-beleid` schema (same fragment, slug `inspraak-beleid`, annotated `x-schema-org: schema:StructuredValue`) with at most one object per governance body: `governanceBody` (reference, required), `inspraakMogelijk` (boolean, default false), `aanmeldDeadlineUren` (integer, default 24 — hours before the meeting start at which registration closes, generalizing the commissie `inspraak-deadline-uren` setting), `standaardSpreektijdMinuten` (integer, default 5), `niveau` (enum `per-agendapunt` | `vergadering` — whether citizens register on a specific agenda item or on the meeting as a whole), and `publiekeWeergave` (enum `aantal` | `voornaam` | `spreker-naam` — how approved insprekers appear in public views, REQ-INS-008). A body without an `inspraak-beleid` object SHALL be treated as inspraak disabled.

#### Scenario: Body without a policy object refuses registration

- GIVEN a governance body with no inspraak-beleid object
- WHEN a registration is submitted for one of its meetings
- THEN the registration is refused server-side with a message that this body does not offer inspraak
- AND no inspraak-aanmelding object is created

#### Scenario: ALV enables meeting-level inspraak

- GIVEN a vereniging governance body whose inspraak-beleid sets `inspraakMogelijk: true` and `niveau: vergadering`
- WHEN a member registers to speak at the upcoming ALV without naming an agenda item
- THEN the inspraak-aanmelding is created with `meeting` set and no `agendaItem`

### Requirement: REQ-INS-004 Registration API enforces policy and closes at the deadline

The system SHALL expose a server-side registration endpoint (consumed by portaliq/the public portal — the form itself is out of scope per `portal-contribution`) that validates every registration against the body's `inspraak-beleid`: refuse when `inspraakMogelijk` is false or absent, require an `agendaItem` when `niveau` is `per-agendapunt` (and refuse one when `vergadering`), default the spreektijd request handling from `standaardSpreektijdMinuten`, and refuse registrations after `meeting.start − aanmeldDeadlineUren` (this cross-object datetime comparison is a justified imperative spot per the ADR-031 decision table in design.md). The griffier SHALL be able to register a citizen after the deadline via an explicit override, recorded in the object's audit trail. On acceptance the citizen SHALL receive a confirmation; the confirmation of spreektijd-toewijzing follows at approval (REQ-INS-005).

#### Scenario: Late registration refused

- GIVEN a body whose inspraak-beleid sets aanmeldDeadlineUren to 24 and a raadsvergadering starting in 3 hours
- WHEN a citizen submits a registration for that meeting
- THEN the registration is refused server-side with a message naming the passed deadline
- AND no inspraak-aanmelding object is created

#### Scenario: Griffier override after the deadline

- GIVEN the same passed deadline
- WHEN the griffier submits the registration on the citizen's behalf with the explicit override flag
- THEN the inspraak-aanmelding is created and the override is visible in the object's audit trail

### Requirement: REQ-INS-005 Griffier moderation: approve, reject, refer

The system SHALL let the griffier moderate each `aangemeld` registration with three actions, mirroring REQ-CVG-009: **approve** (transition to `goedgekeurd`, setting `spreektijdToegewezenMinuten` — defaulted from the body policy, adjustable — and `volgorde`), **reject** (transition to `afgewezen` with a mandatory `afwijzingsReden`), and **refer** (re-target the still-`aangemeld` registration to a different meeting and/or agenda item — e.g. from the plenary to the responsible commissie — without a status change). Approval and rejection SHALL notify the registrant declaratively (`x-openregister-notifications` on the status update, nl/en); a referral SHALL notify the registrant of the new target.

#### Scenario: Rejection requires a reason

- GIVEN an inspraak-aanmelding with status `aangemeld`
- WHEN the griffier rejects it with afwijzingsReden "Onderwerp staat niet op de agenda van deze vergadering"
- THEN the object is terminal `afgewezen` and the reason is stored
- AND rejection without a reason is refused

#### Scenario: Referral to a commissievergadering

- GIVEN an inspraak-aanmelding targeting a plenary raadsvergadering on a topic scheduled for commissie treatment
- WHEN the griffier refers it to the commissievergadering's agenda item
- THEN the `meeting` and `agendaItem` references are updated, the status remains `aangemeld`
- AND the registrant is notified of the new meeting

### Requirement: REQ-INS-006 Written bijdrage and transcript-segment linkage

The system SHALL let the griffie attach the inspreker's contribution to the aanmelding after the meeting: a written `bijdrageTekst` (rich text — the submitted spoken text or summary) and/or a `transcriptSegment` reference to the segment(s) where the inspreker spoke (the `raadsvergadering-livestream-transcript` change assigns such segments to a Spreker with `rol: inspreker`; both fields are nullable and degrade gracefully when that change is absent). The agenda item's detail view SHALL surface these contributions via the relation — the contribution is stored once on the aanmelding, never duplicated onto the AgendaItem. Published verslag output SHALL include only the public projection (onderwerp fields + bijdrage), mirroring REQ-CVG-011.

#### Scenario: Written contribution attached after the meeting

- GIVEN an inspraak-aanmelding with status `gesproken`
- WHEN the griffier attaches the bijdrageTekst supplied by the inspreker
- THEN the text is stored on the aanmelding and rendered on the linked agenda item's detail view under its insprekers

#### Scenario: Transcript segment linked

- GIVEN a published transcript whose segments include a Spreker with rol `inspreker` matching a gesproken aanmelding
- WHEN the griffier links that segment to the aanmelding
- THEN the agenda item's inspreker entry offers a deeplink to the transcript segment
- AND no contactgegevens appear anywhere in the public rendering

## Non-Functional Requirements

- **Performance:** The griffie overview and per-meeting inspreker lists MUST load via indexed register queries (status + meeting filters); no N+1 per-aanmelding fetches.
- **Accessibility:** All moderation dialogs and lists MUST meet WCAG 2.1 AA; status is never conveyed by colour alone.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); i18n keys in English.
- **Privacy (AVG):** `contactgegevens` MUST be structurally absent from every public payload (allow-list projection, REQ-INS-008); retention follows the body's archival policy.

## Acceptance Criteria

- [ ] Fragment 64 loads additively; `inspraak-aanmelding` and `inspraak-beleid` validate objects per the scenarios above
- [ ] Lifecycle, RBAC, and notifications are declarative dialects (gate-18 clean); the only imperative spots are those named in design.md D3
- [ ] The commissievergaderingen change defines no duplicate InspraakAanmelding after coordination

## Notes

Canonical home decision and the extension mechanism (whole-schema fragment merge, ADR-037) in design.md D1; coordination with the `commissievergaderingen` author change is a tracked task. Status enum and field split are contractually identical to REQ-CVG-009.
