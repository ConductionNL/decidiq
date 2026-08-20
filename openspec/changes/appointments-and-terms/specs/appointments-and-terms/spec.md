# appointments-and-terms Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- appointments-and-terms

## Purpose

Provides the appointments & terms register: `Voordracht` (nomination) objects with candidates, nominating party, motivation, and a declarative status lifecycle linked to the deciding agenda item and voting round; benoemingsbesluit→Membership traceability via assistive Membership creation; a `TermijnRegeling` per body-role (term length, max consecutive terms) from which term numbers and end-of-term dates are derived; a regeneratable, CSV-exportable, optionally publicly published rooster van aftreden per body; declarative herbenoemingsrappels; and a vacancy flow that opens the Post and suggests a voordracht. Reuses `person-and-membership` (Person/Membership/Post — REQ-PMB-011/012), `governance-body-crud` (GovernanceBody), and the ballot mechanics of `voting-system`/`secret-ballot`/`preferential-ballot`; hands off post-benoeming onboarding to `member-onboarding`. A voordracht is a `schema:Action` (agent = the voordragende partij, object = the candidacy for a Post).

## ADDED Requirements

### Requirement: REQ-APT-001 Voordracht schema on OpenRegister

The system SHALL define a `Voordracht` schema in the decidesk register via a `lib/Settings/register.d/61-appointments-and-terms.json` fragment (ADR-037 — never by editing `decidesk_register.json`), annotated `x-schema-org: schema:Action`. The schema SHALL carry at minimum: `body` (GovernanceBody reference, required), `post` (Post reference, optional — required when the voordracht targets a specific formal position), `targetRole` (enum matching the Membership role enum of `person-and-membership` REQ-PMB-011: `chair`, `vice-chair`, `secretary`, `treasurer`, `member`, `observer`, `guest`; required), `kandidaten` (array of structured candidate objects, each carrying either a `persoon` Person reference or an `externeNaam` free-text name for not-yet-registered candidates, plus an optional `toelichting`; required, minimum one), `voordragendePartij` (structured: `type` enum `fractie`/`orgaan`/`persoon` plus a reference or free-text name — aligned with the Raadslid/fractie vocabulary of `fractievoorzitter-fractie-koppeling`), `motivering` (text), `lifecycle` (required, see REQ-APT-002), `agendapunt` (AgendaItem reference, optional until scheduled), `votingRound` (VotingRound reference, optional), `besluit` (Decision reference, optional until decided), and `membership` (Membership reference, optional until appointed). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `voordracht`.

#### Scenario: Fragment adds the schemas without touching existing schemas
- GIVEN the register fragment `61-appointments-and-terms.json` is loaded
- WHEN the decidesk register imports
- THEN the `voordracht` schema exists with all required fields and property titles
- AND no schema outside fragment 61 is created or modified
- AND the Membership and Post schemas of `person-and-membership` are unchanged

#### Scenario: External candidate without a Person record
- GIVEN a corporate RvC voordracht for an external candidate not yet known as a Person
- WHEN the secretary records the candidate with `externeNaam` "Mw. J. van Duin"
- THEN the voordracht validates without a Person reference
- AND the candidate can later be linked to a Person record without losing the voordracht history

### Requirement: REQ-APT-002 Declarative voordracht lifecycle

The `Voordracht` schema SHALL declare `x-openregister-lifecycle` (ADR-031, canonical `initial` keyword) with states `ingediend → behandeld → benoemd | niet-benoemd | ingetrokken`, where `benoemd`, `niet-benoemd`, and `ingetrokken` are terminal. `ingetrokken` SHALL be reachable from both `ingediend` and `behandeld` (a voordragende partij can withdraw before or during treatment). The transition to `benoemd` SHALL require a `besluit` (Decision) reference to be present. No imperative status writes SHALL bypass the lifecycle.

#### Scenario: Happy path to appointment
- GIVEN a voordracht in `ingediend`
- WHEN it is treated in the meeting (`behandeld`) and the appointment decision is linked
- THEN the transition to `benoemd` succeeds

#### Scenario: Appointment without a decision reference is refused
- GIVEN a voordracht in `behandeld` with no `besluit` reference
- WHEN the transition to `benoemd` is attempted
- THEN the transition is refused with a message naming the missing benoemingsbesluit

#### Scenario: Withdrawal before treatment
- GIVEN a voordracht in `ingediend`
- WHEN the voordragende partij withdraws it
- THEN the voordracht reaches the terminal state `ingetrokken`

### Requirement: REQ-APT-003 Voordracht references the deciding agenda item and voting round

The system SHALL let a voordracht reference the AgendaItem where it is decided and the `VotingRound` that decided it, declared via `x-openregister-relations`. Ballot mechanics SHALL NOT be redefined: a contested or secret appointment vote uses the existing `voting-system`, `secret-ballot` (REQ-SBL-001..004), and `preferential-ballot` (REQ-PRF-001..005) capabilities as-is, and the voordracht only stores the resulting round reference. This change SHALL introduce no new voting method, tally logic, or ballot schema.

#### Scenario: Secret appointment vote is referenced, not reimplemented
- GIVEN a voordracht with two candidates treated at an agenda item
- WHEN the chair opens a VotingRound with `isSecret=true` for the appointment
- THEN the voordracht references that round via `votingRound`
- AND individual vote masking is enforced by the existing secret-ballot capability without any voordracht-specific vote handling

#### Scenario: Uncontested appointment without a voting round
- GIVEN a voordracht with a single candidate decided by acclamation
- WHEN the voordracht is linked to its benoemingsbesluit and reaches `benoemd`
- THEN `votingRound` remains empty and the voordracht is valid

### Requirement: REQ-APT-004 Benoemingsbesluit linkage and assistive Membership creation

When a voordracht reaches `benoemd`, the system SHALL offer assistive creation of the resulting Membership, prefilled from the voordracht: person (the appointed candidate), governance body, role (`targetRole`), post, and `startDate` (defaulting to the decision date, editable). The created Membership is a normal `person-and-membership` Membership object — this change SHALL NOT modify that schema. The voordracht SHALL store the created (or manually linked) Membership in its `membership` property, so every appointed Membership traces to its appointing decision via the voordracht (`membership` + `besluit`). Assistive creation SHALL be explicitly confirmed by the secretary/griffie — never automatic — and SHALL refuse when the appointed candidate has no Person record yet (pointing at Person creation first). After linkage, the system SHALL surface a reference-only handoff suggestion to start a `member-onboarding` OnboardingTraject when that capability is present, and SHALL degrade gracefully (no suggestion, no error) when it is absent.

#### Scenario: Membership prefilled from the voordracht
- GIVEN a voordracht for "Auditcommissie" role `member` with candidate Person "S. Jansen" that reached `benoemd` with a besluit dated 2026-09-10
- WHEN the griffie confirms the assistive Membership creation
- THEN a Membership is created for S. Jansen in the Auditcommissie with role `member` and startDate 2026-09-10
- AND the voordracht's `membership` property references it

#### Scenario: Appointment traceability from the Membership
- GIVEN the created Membership
- WHEN its appointing decision is looked up
- THEN the voordracht referencing that Membership yields the benoemingsbesluit via its `besluit` reference

#### Scenario: External candidate blocks assistive creation until a Person exists
- GIVEN a voordracht whose appointed candidate carries only `externeNaam`
- WHEN assistive Membership creation is attempted
- THEN it refuses with a message pointing at creating/linking the Person record first

#### Scenario: Onboarding handoff is reference-only
- GIVEN the member-onboarding capability is not installed or its schemas are absent
- WHEN a Membership is created from a voordracht
- THEN no onboarding suggestion is shown and no error occurs

### Requirement: REQ-APT-005 TermijnRegeling schema per body-role

The system SHALL define a `TermijnRegeling` schema in fragment 61 carrying: `body` (GovernanceBody reference, required), `role` (Membership role enum, optional — empty means the rule applies to all roles in the body), `termijnDuurMaanden` (positive integer, required — e.g. 48 for a 4-year term), `maxAansluitendeTermijnen` (positive integer, optional — empty means unlimited), and `toelichting` (text). At most one regeling SHALL be effective per body-role combination (a role-specific regeling overrides the body-wide one). Every property SHALL carry a `title`; the schema slug SHALL be `termijn-regeling`.

#### Scenario: Role-specific rule overrides the body-wide rule
- GIVEN a body-wide TermijnRegeling of 48 months and a chair-specific regeling of 36 months for the same body
- WHEN the effective rule for a chair Membership is resolved
- THEN the 36-month chair regeling applies

#### Scenario: Duplicate regeling for the same body-role is rejected
- GIVEN an existing TermijnRegeling for body "RvC Vereniging De Toekomst" with empty role
- WHEN a second body-wide regeling for the same body is saved
- THEN validation rejects it naming the existing regeling

### Requirement: REQ-APT-006 Derived term number and end-of-term date per Membership

The system SHALL derive, per active Membership under an effective TermijnRegeling: the **term number** (1-based count of the person's consecutive terms in the same body-role, where a term ends `termijnDuurMaanden` after its start and a reappointment without a gap increments the count; a gap or role change resets the series) and the **end-of-term date** (start of the current term plus `termijnDuurMaanden`). Derived values SHALL be computed from Membership `startDate`/`endDate` history (`person-and-membership`) and SHALL NOT be written onto the Membership schema. When the derived term number exceeds `maxAansluitendeTermijnen`, the system SHALL surface an advisory warning (on the rooster and on any voordracht for the same person/body-role) — it SHALL NOT hard-block, since bodies may deviate by explicit decision recorded in the motivering.

#### Scenario: Reappointment increments the term number
- GIVEN P. de Wit has a Membership 2018-06-01..2022-06-01 and a consecutive Membership from 2022-06-01 in the same body-role under a 48-month regeling
- WHEN term data is derived
- THEN the current term is number 2 with end-of-term date 2026-06-01

#### Scenario: Gap resets the term series
- GIVEN a person whose previous Membership in the body-role ended in 2019 and whose current Membership started in 2024
- WHEN term data is derived
- THEN the current term is number 1

#### Scenario: Max consecutive terms yields an advisory warning, not a block
- GIVEN a regeling with `maxAansluitendeTermijnen` 2 and a candidate whose derived next term would be number 3
- WHEN a voordracht for that candidate is edited
- THEN an advisory warning names the exceeded maximum
- AND saving the voordracht remains possible

### Requirement: REQ-APT-007 Rooster van aftreden generation and regeneration

The system SHALL define `RoosterVanAftreden` (slug `rooster-van-aftreden`; one live object per governance body carrying `body`, `gegenereerdOp`, `gegenereerdDoor`, and publication fields per REQ-APT-009) and `RoosterRegel` (slug `rooster-regel`; one object per active Membership under a regeling, carrying `rooster` and `membership` references, `persoonNaam`, `role`, `termijnNummer`, `eindeTermijnDatum`, `herbenoembaar` (boolean derived from `maxAansluitendeTermijnen`), and `rappelStatus`) in fragment 61. Generation SHALL compute the regels from live Memberships and the effective TermijnRegelingen (REQ-APT-006), ordered by ascending `eindeTermijnDatum`. Regeneration SHALL be explicitly triggered, SHALL replace the body's regels with freshly derived ones, and SHALL update `gegenereerdOp` — a rooster is a regeneratable projection, never hand-maintained truth.

#### Scenario: Rooster orders members by retirement date
- GIVEN a body with three active Memberships whose derived end-of-term dates are 2026-11-01, 2027-06-01, and 2029-06-01
- WHEN the rooster is generated
- THEN three RoosterRegel objects exist ordered 2026-11-01, 2027-06-01, 2029-06-01
- AND each regel references its Membership and carries the derived term number

#### Scenario: Regeneration reflects a new appointment
- GIVEN a generated rooster and a subsequently created Membership in the same body
- WHEN the secretary regenerates the rooster
- THEN the new member appears as a regel with derived term data
- AND stale regels for ended Memberships are removed
- AND `gegenereerdOp` is updated

### Requirement: REQ-APT-008 Rooster CSV export

The system SHALL export a body's rooster van aftreden as CSV, containing per regel at minimum: member name, role, term number, term start, end-of-term date, and herbenoembaar. The export SHALL reflect the stored regels of the most recent generation, ordered by end-of-term date, and SHALL carry a UTF-8 BOM so Dutch names open correctly in Excel.

#### Scenario: CSV download of the rooster
- GIVEN a generated rooster with three regels
- WHEN the secretary exports it as CSV
- THEN a CSV downloads with one row per regel in end-of-term order and the specified columns

### Requirement: REQ-APT-009 Public publication of the rooster via the OR published-predicate

The system SHALL support optional public publication of a rooster van aftreden by setting a `publicatiedatum` predicate on the live `RoosterVanAftreden` object (and exposing its regels through the same rule), with `authorization.read` granting the `public` group read access while `publicatiedatum <= $now` — the same OR RBAC published-predicate surface used by `public-publication`, and no app-local anonymous pages or endpoints. This is deliberately predicate-on-live-object rather than a derived payload: the rooster is itself a generated, allow-list projection (see REQ-APT-007) containing only member name, role, term dates, and herbenoembaar — never contact details, NC UIDs, addresses, or vote data. Publication SHALL be opt-in per rooster and withdrawable by clearing the predicate.

#### Scenario: Published RvC rooster is anonymously readable
- GIVEN an RvC rooster with `publicatiedatum` set to yesterday
- WHEN an anonymous client reads the OR published-predicate surface
- THEN the rooster and its regels are readable with names, roles, term numbers, and end-of-term dates only

#### Scenario: Unpublished rooster stays private
- GIVEN a municipal committee rooster whose `publicatiedatum` is empty
- WHEN an anonymous client reads the published-predicate surface
- THEN the rooster and its regels are not returned

#### Scenario: Withdrawal by clearing the predicate
- GIVEN a published rooster
- WHEN the secretary clears `publicatiedatum`
- THEN the rooster is no longer anonymously readable

### Requirement: REQ-APT-010 Declarative herbenoemingsrappels ahead of term end

The `RoosterRegel` schema SHALL declare `x-openregister-notifications` (ADR-031) scheduled triggers that notify the body's secretary/griffie group when a regel's `eindeTermijnDatum` enters each configured rappel window (default 6 and 3 months ahead), with Dutch and English subjects naming the member, body, role, and end-of-term date. Rappel windows SHALL be configurable. No bespoke reminder background job SHALL be introduced (the notification-dialect gate hard-fails imperative dispatch — same dialect as the toezeggingen deadline rappels).

#### Scenario: Six-month rappel fires
- GIVEN a regel with `eindeTermijnDatum` five months and three weeks from now and default windows 6/3 months
- WHEN the scheduled notification evaluation runs
- THEN the secretary group receives the 6-month herbenoemingsrappel naming member, body, role, and date

#### Scenario: No rappel for distant terms
- GIVEN a regel with `eindeTermijnDatum` two years from now
- WHEN the scheduled notification evaluation runs
- THEN no rappel is sent

### Requirement: REQ-APT-011 Vacancy flow opens the Post and suggests a voordracht

When a Membership ends (endDate reached or set — resignation) or a regel's end-of-term date passes without reappointment, the system SHALL treat the associated Post as vacant per the existing `person-and-membership` Post semantics (a Post exists independently of its occupant, REQ-PMB-012 — no new vacancy schema) and SHALL surface a suggestion to create a voordracht prefilled with the body, post, and role. Suggestions SHALL be griffie/secretary-confirmed — the system SHALL NOT create voordrachten automatically.

#### Scenario: Term end surfaces a prefilled voordracht suggestion
- GIVEN a regel whose `eindeTermijnDatum` has passed and whose Membership has ended
- WHEN the secretary opens the vacancy overview
- THEN the Post is listed as vacant with a suggestion to create a voordracht prefilled with body, post, and role
- AND no voordracht exists until the secretary confirms

#### Scenario: Reappointment produces no vacancy
- GIVEN a member whose term ended but whose reappointment voordracht reached `benoemd` with a consecutive Membership
- WHEN the vacancy overview is opened
- THEN the Post is not listed as vacant

### Requirement: REQ-APT-012 Pages, menu, and expiring-terms KPI

The system SHALL provide manifest.d pages (schema refs by slug, never PascalCase): a `voordracht` index (columns: candidates, body, target role, voordragende partij, lifecycle, decision link; quick filters: lifecycle, body, voordragende partij) and detail page (candidates, motivering, linked agenda item / voting round / besluit / membership as navigable relations, audit-trail sidebar); a per-body rooster van aftreden page rendering the ordered regels with term warnings, regenerate, CSV export, and publication actions; and a `termijn-regeling` configuration surface. The dashboard SHALL carry a "termijnen die binnen N maanden aflopen" KPI (default N=6) sourced from a declarative widget aggregation over `rooster-regel` end-of-term dates, routing to the pre-filtered rooster view; when the widget filter DSL lacks a relative-date token for the window cut, the documented fallback applies (count non-ended regels and cut the window on the pre-filtered index — never a silently wrong count).

#### Scenario: Voordrachten index with lifecycle filter
- GIVEN seeded voordrachten in different lifecycle states
- WHEN the user filters the index on `ingediend`
- THEN only submitted voordrachten are listed with the specced columns

#### Scenario: Expiring-terms KPI routes to the rooster
- GIVEN seeded regels with an end-of-term date within 6 months
- WHEN the user clicks the expiring-terms KPI
- THEN the pre-filtered rooster view opens showing exactly those regels

### Requirement: REQ-APT-013 Seed data for all four schemas

Fragment 61 SHALL seed realistic demo objects per ADR-016 covering both the municipal and the corporate governance domain: at least 2 TermijnRegelingen (a municipal committee and a corporate RvC rule with `maxAansluitendeTermijnen`), 3 Voordrachten (one `ingediend`, one `benoemd` with besluit + membership links, one `ingetrokken`), 2 RoosterVanAftreden (one published RvC rooster with `publicatiedatum` set, one unpublished municipal committee rooster), and at least 5 RoosterRegels including one inside the 6-month rappel window (driving the KPI and rappel demo) and one at its maximum consecutive term (driving the advisory warning). Seeds SHALL link to already-seeded Persons/GovernanceBodies/Memberships, with nil-UUID placeholders only where a reference cannot resolve at seed time.

#### Scenario: Fresh install is demoable
- GIVEN a fresh install with fragment 61 seeded
- WHEN the voordrachten index, rooster pages, and dashboard render
- THEN all lifecycle states, the published/unpublished rooster pair, the expiring-terms KPI, and the max-term warning are visible without manual data entry

## Non-Functional Requirements

- **Performance:** rooster generation for a 45-member body completes interactively (<2s) without N+1 object reads (bulk membership/regeling queries); the KPI uses declarative aggregation, no imperative counting endpoint.
- **Accessibility:** rooster tables and voordracht forms meet WCAG 2.1 AA; term warnings are conveyed in text, not color alone.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); i18n keys in English; Dutch governance terms (voordracht, rooster van aftreden) remain untranslated domain vocabulary in both locales where customary.

## Acceptance Criteria

- [ ] All scenarios above pass against a seeded install on the Postgres environment
- [ ] Register fragment 61 adds exactly four schemas; no existing schema modified
- [ ] Lifecycle, rappels, publication predicate, and KPI are declarative (gates 18/28/30/51/52 pass)
- [ ] Every Membership created via REQ-APT-004 traces to its benoemingsbesluit

## Notes

- Boundaries: election mechanics = `voting-system`/`secret-ballot`/`preferential-ballot`; onboarding after appointment = `member-onboarding`; Membership/Post schemas = `person-and-membership`; fractie vocabulary = `fractievoorzitter-fractie-koppeling`; rappel dialect = as in `toezeggingen-ingekomen-stukken`.
- ORI mapping: voordrachten decided in public meetings surface through the existing agenda/decision publication paths; the rooster itself is not an ORI entity.
- Related ADRs: ADR-016 (seed data), ADR-031 (declarative dialects), ADR-037 (register.d fragments).
