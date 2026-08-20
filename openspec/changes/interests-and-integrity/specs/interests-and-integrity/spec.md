# interests-and-integrity Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- [interests-and-integrity](../../changes/interests-and-integrity/)

## Purpose

Structured interest and integrity registers for all five decidesk governance domains: a nevenfunctiesregister (person-linked other-positions declarations with a public-disclosure lifecycle, statutorily public for councils per Gemeentewet, internal by default for corporate boards) and a geschenken/uitnodigingenregister (gifts and invitations with a per-body value-threshold policy per gedragscode). A per-body `Integriteitsbeleid` policy object drives disclosure defaults, the gift threshold, and a configurable integrity notification to a designated role. The capability integrates with — and never re-specs — the per-agenda-item `conflict-of-interest` capability (REQ-COI-001..004): COI stays the declaration mechanism on agenda items; this register is the standing context behind it. It supersedes the register mechanics of `fractievoorzitter-fractie-koppeling` REQ-012 (council-scoped nevenfuncties) and supersedes `Membership.otherPositions` (`person-and-membership`) for new data. A nevenfunctie is a `schema:Role` held by a person at an organisation; a geschenk-registratie is a `schema:Action` (agent = recipient).

## ADDED Requirements

### Requirement: REQ-INT-001 Nevenfunctie schema on OpenRegister

The system SHALL define a `Nevenfunctie` schema in the decidesk register via `lib/Settings/register.d/62-interests-and-integrity.json` (ADR-037; `decidesk_register.json` is never edited), annotated `x-schema-org: schema:Role`. The schema SHALL carry at minimum: `person` (Person reference, required), `governanceBody` (GovernanceBody reference, required — the body under whose disclosure regime the declaration is made), `organisatie` (string, required), `functie` (function description, required), `bezoldigd` (boolean, required), `urenIndicatie` (string, optional), `startDate` (date, required), `endDate` (date, optional), `qualitateQua` (boolean, default false — held uit hoofde van het ambt), `declaredAt` (date, required), `reviewedAt` (date, optional — set by the annual self-review), `lifecycle` (required), and `publicatiedatum`/`depublicatiedatum` (datetime, optional — publication predicate). Every property SHALL carry a `title`. The schema SHALL NOT carry remuneration amounts, private contact data, or any free-form internal-remarks property, so the whole object is publishable by construction. The manifest and all widget/filter sources SHALL reference the schema by its slug `nevenfunctie`. `Membership.otherPositions` (person-and-membership, corp-mode free-text array) is superseded for new data: it remains readable legacy data, and Membership editing surfaces SHALL point users to this register for structured declarations.

#### Scenario: Raadslid declares a paid nevenfunctie

- GIVEN a Person with an active Membership in the gemeenteraad
- WHEN the member registers a nevenfunctie with organisatie "Stichting Welzijn Noord", functie "bestuurslid", bezoldigd true, urenIndicatie "4 uur/week", and startDate
- THEN a Nevenfunctie object is created in the decidesk register in lifecycle `gemeld`, linked to the person and the gemeenteraad
- AND omitting organisatie, functie, bezoldigd, or person is rejected by OpenRegister schema validation

#### Scenario: Q.q.-functie flagged as held ex officio

- GIVEN a wethouder who sits on a gemeenschappelijke-regeling board because their portefeuille requires it
- WHEN the nevenfunctie is registered with `qualitateQua: true`
- THEN the register lists it with a q.q. marker distinguishing it from personal nevenfuncties

#### Scenario: Register fragment is additive

- GIVEN a decidesk installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the Nevenfunctie, Geschenk, and Integriteitsbeleid schemas are registered from fragment `62-interests-and-integrity.json`
- AND no existing schema in `decidesk_register.json` is modified

### Requirement: REQ-INT-002 Nevenfunctie disclosure lifecycle is declarative

The `Nevenfunctie` schema SHALL declare its disclosure workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`default`): field `lifecycle`, initial `gemeld`, states `gemeld → openbaar | intern → beëindigd`, with transitions `gemeld → openbaar`, `gemeld → intern`, `intern → openbaar`, `openbaar → intern` (rectification), `openbaar → beëindigd`, and `intern → beëindigd`; `beëindigd` is terminal. Ending a nevenfunctie SHALL record `endDate`. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Griffie verifies and discloses a declaration

- GIVEN a Nevenfunctie in lifecycle `gemeld` for a raadslid
- WHEN the griffie sets it to `openbaar`
- THEN the transition is accepted per the declared transition map
- AND no app-side guard code is involved

#### Scenario: Invalid transition rejected declaratively

- GIVEN a Nevenfunctie in lifecycle `beëindigd`
- WHEN any user attempts to set the lifecycle back to `gemeld`
- THEN OpenRegister rejects the transition per the declared transition map

#### Scenario: Nevenfunctie ends

- GIVEN an `openbaar` Nevenfunctie whose holder resigns from the position
- WHEN the member or griffie sets lifecycle `beëindigd` with an endDate
- THEN the object is terminal and the register shows it as ended per that date

### Requirement: REQ-INT-003 Per-body integrity policy with body-type defaults

The system SHALL define an `Integriteitsbeleid` schema (slug `integriteitsbeleid`, same fragment, one object per governance body) carrying: `governanceBody` (reference, required), `nevenfunctieDisclosureDefault` (enum `openbaar`/`intern`, required), `geschenkDrempelbedrag` (number in EUR, default 50), `geschenkenOpenbaar` (boolean, default false), and `integriteitsNotificatieGroep` (string — NC group receiving integrity notifications, e.g. mapped to burgemeester, voorzitter, or compliance officer, optional). When no policy object exists for a body, the system SHALL apply body-type defaults: legislative/democratic bodies default to `openbaar` (Gemeentewet openbaarmaking of nevenfuncties), all other body types default to `intern`, and the gift threshold defaults to EUR 50 (model-gedragscode). The disclosure default SHALL preselect the target state offered when a `gemeld` declaration is processed; it SHALL NOT bypass the explicit transition.

#### Scenario: Council default is public disclosure

- GIVEN a gemeenteraad with no Integriteitsbeleid object
- WHEN the griffie processes a `gemeld` Nevenfunctie
- THEN the preselected disclosure target is `openbaar` per the legislative body-type default

#### Scenario: Corporate board defaults to internal

- GIVEN a raad van commissarissen with no Integriteitsbeleid object
- WHEN the bestuurssecretaris processes a `gemeld` Nevenfunctie
- THEN the preselected disclosure target is `intern`
- AND the declaration is never published unless staff explicitly transition it to `openbaar`

#### Scenario: Admin sets a stricter gift threshold

- GIVEN an Integriteitsbeleid object for the gemeenteraad with geschenkDrempelbedrag 25
- WHEN a geschenk with estimated value EUR 30 is registered for a member of that body
- THEN the above-threshold handling of REQ-INT-006 applies using the configured EUR 25

### Requirement: REQ-INT-004 Public nevenfunctiesregister via the OR published-predicate on the live object

The system SHALL make the public nevenfunctiesregister available through OpenRegister's anonymous RBAC published-predicate surface: the `Nevenfunctie` schema declares an `authorization.read` rule granting the `public` group read access while `publicatiedatum <= $now`. Staff with governance-body authority publish a verified `openbaar` nevenfunctie by setting `publicatiedatum` and withdraw by setting `depublicatiedatum`; publication SHALL never happen without this explicit staff action. Because the predicate sits on the live object, the public register SHALL reflect changes (e.g. `beëindigd` with endDate) without republication. This capability deliberately uses predicate-on-live-object (same carve-out as `toezeggingen-register`): the `public-publication` eligibility gates and derived-payload machinery SHALL NOT be modified or invoked for nevenfuncties. The system SHALL NOT serve app-local anonymous pages for the register. (`@self.published` is removed from OpenRegister and SHALL NOT be used.)

#### Scenario: Published nevenfunctie is anonymously readable

- GIVEN an `openbaar` Nevenfunctie published by the griffie (publicatiedatum in the past)
- WHEN an unauthenticated client reads the OR published-predicate surface
- THEN the nevenfunctie is returned with person, organisatie, functie, bezoldigd, q.q. flag, and dates

#### Scenario: Ended position is live on the public register

- GIVEN a published Nevenfunctie
- WHEN the holder ends it (lifecycle `beëindigd`, endDate set)
- THEN the next anonymous read shows the ended state without any republish step

#### Scenario: Internal declaration is not public

- GIVEN an `intern` Nevenfunctie of an RvC member without `publicatiedatum`
- WHEN an unauthenticated client queries the published surface
- THEN the nevenfunctie is not returned

### Requirement: REQ-INT-005 Geschenk schema on OpenRegister

The system SHALL define a `Geschenk` schema (slug `geschenk`, same fragment), annotated `x-schema-org: schema:Action` (agent = recipient), carrying at minimum: `recipient` (Person reference, required), `governanceBody` (GovernanceBody reference, required), `type` (enum `geschenk`/`uitnodiging`, required), `gever` (string, required — the giving party as a plain field, never a Person reference, so no external-party PII enters the people register), `omschrijving` (string, required), `geschatteWaarde` (number in EUR, required), `ontvangenOp` (date, required), `besluit` (enum `aanvaard`/`geweigerd`/`overgedragen`, required), `toelichting` (string, optional), `declaredAt` (date, required), and `publicatiedatum`/`depublicatiedatum` (datetime, optional). Every property SHALL carry a `title`. When the body's policy sets `geschenkenOpenbaar: true`, the same public-group predicate rule as REQ-INT-004 SHALL make published geschenken anonymously readable; publication remains an explicit staff action per registration.

#### Scenario: Member registers a received gift

- GIVEN a raadslid who received a book worth ~EUR 20 from a bezoekende delegatie
- WHEN the member registers it with type `geschenk`, gever, omschrijving, geschatteWaarde 20, ontvangenOp, and besluit `aanvaard`
- THEN a Geschenk object is created linked to the member and the body
- AND omitting gever, geschatteWaarde, or besluit is rejected by schema validation

#### Scenario: Invitation registered and handed over

- GIVEN a bestuurslid invited to a paid conference dinner (estimated EUR 120)
- WHEN the registration is saved with type `uitnodiging` and besluit `overgedragen`
- THEN the register shows the invitation with its decision and toelichting

#### Scenario: Gift register publication follows body policy

- GIVEN a body whose Integriteitsbeleid has `geschenkenOpenbaar: false`
- WHEN staff view a Geschenk of that body
- THEN no publish action is offered and no Geschenk of that body is anonymously readable

### Requirement: REQ-INT-006 Gift threshold per gedragscode drives badge and notification

The system SHALL evaluate each registered Geschenk against the body's `geschenkDrempelbedrag` (policy or EUR 50 default). Geschenken with `geschatteWaarde` at or above the threshold SHALL be visibly badged as boven-drempel in list and detail views and SHALL trigger the integrity notification of REQ-INT-009. The threshold is registration policy only: the system SHALL NOT block saving any decision value (the gedragscode text governing what must be refused lives in the governing-documents register, out of scope here).

#### Scenario: Above-threshold gift is badged

- GIVEN a body with the default EUR 50 threshold
- WHEN a Geschenk with geschatteWaarde 75 is registered
- THEN the geschenken list shows a boven-drempel badge on that row
- AND the integrity notification of REQ-INT-009 fires

#### Scenario: Below-threshold gift stays quiet

- GIVEN the same body
- WHEN a Geschenk with geschatteWaarde 15 and besluit `aanvaard` is registered
- THEN no boven-drempel badge is shown and no integrity notification is sent for it

### Requirement: REQ-INT-007 Assistive nevenfuncties context on the COI surfaces

The system SHALL surface registered nevenfuncties as assistive context on the existing conflict-of-interest surfaces, without altering the COI mechanism itself: (a) in the "Belangenverstrengeling melden" dialog of REQ-COI-001 (`conflict-of-interest`), the declaring participant's own active (non-`beëindigd`) nevenfuncties SHALL be listed for reference, with entries whose `organisatie` or `functie` text matches words of the agenda item's title highlighted; (b) in the chair's COI summary panel of REQ-COI-002, each declaring participant SHALL carry a link to their registered nevenfuncties. This is display-only assistance: COI declarations remain the REQ-COI-001 notes mechanism, and the system SHALL NOT auto-declare, block, or score conflicts.

#### Scenario: Declaration dialog shows own nevenfuncties

- GIVEN a participant with two active nevenfuncties who opens "Belangenverstrengeling melden" on agenda item "Subsidie Stichting Welzijn Noord"
- WHEN the dialog renders
- THEN both nevenfuncties are listed as reference context
- AND the nevenfunctie at "Stichting Welzijn Noord" is highlighted as matching the item's subject

#### Scenario: Chair panel links to the register

- GIVEN a meeting where a participant with registered nevenfuncties declared COI on an item
- WHEN the chair opens the REQ-COI-002 summary panel
- THEN the participant's entry links to their nevenfuncties in the register

#### Scenario: No automatic conflict handling

- GIVEN a participant whose nevenfunctie matches an agenda item's subject
- WHEN the participant does not declare COI
- THEN the system creates no COI note, no warning to the chair, and no vote restriction

### Requirement: REQ-INT-008 Annual review rappel is a declarative notification

The annual review prompt SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on the `Nevenfunctie` schema: a scheduled trigger notifying the `person` of a non-`beëindigd` nevenfunctie whose `reviewedAt` (or `declaredAt` when never reviewed) lies more than 12 months in the past, with Dutch and English subjects. The member confirms via self-service (REQ-INT-010), which writes `reviewedAt` (carrying all other fields forward — OR saveObject is PUT-semantic); confirming SHALL NOT require staff involvement. The app SHALL NOT dispatch these rappels imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Stale declaration triggers a rappel

- GIVEN an `openbaar` Nevenfunctie last reviewed 13 months ago
- WHEN the scheduled notification trigger evaluates
- THEN the holder receives a Nextcloud notification prompting review of their declarations

#### Scenario: Reviewed and ended declarations stay quiet

- GIVEN one Nevenfunctie reviewed 2 months ago and one in lifecycle `beëindigd`
- WHEN the scheduled trigger evaluates
- THEN no rappel is sent for either

#### Scenario: Member confirms own declaration

- GIVEN a member on the MyDeclarations page with a rappel-flagged nevenfunctie
- WHEN the member confirms it is still accurate
- THEN `reviewedAt` is set to today on that object and no other field changes

### Requirement: REQ-INT-009 Integrity notification to the designated role

New-declaration alerts SHALL be declared exclusively via `x-openregister-notifications` (ADR-031): `created` and `updated` triggers on `Nevenfunctie`, and a `created` trigger on `Geschenk` scoped to boven-drempel registrations (REQ-INT-006), notifying the body's designated integrity recipient (the policy's `integriteitsNotificatieGroep` — burgemeester, voorzitter, or compliance officer as configured per body), with Dutch and English subjects. This generalizes the burgemeester integriteits-toets notification proposed in `fractievoorzitter-fractie-koppeling` REQ-012 across all governance domains and supersedes that requirement's notification mechanics. The app SHALL NOT dispatch these notifications imperatively.

#### Scenario: Burgemeester notified of a new paid nevenfunctie

- GIVEN a gemeenteraad whose policy routes integrity notifications to the burgemeester group
- WHEN a raadslid registers a bezoldigde nevenfunctie
- THEN the burgemeester group receives a Nextcloud notification referencing the declaration

#### Scenario: Compliance officer notified of an above-threshold gift

- GIVEN an RvC whose policy names a compliance-officer group and threshold EUR 50
- WHEN a Geschenk with geschatteWaarde 200 is registered
- THEN the compliance-officer group is notified
- AND a below-threshold Geschenk triggers no such notification

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- WHEN the notification-dialect gate scans the interests-and-integrity code paths
- THEN no imperative object-notification dispatch exists; all triggers are declarative rules in the register fragment

### Requirement: REQ-INT-010 Self-service, register pages, compliance view, and dashboard KPI

The system SHALL provide, as manifest pages in `src/manifest.d/interests-and-integrity.json` (ADR-037, manifest-v2 conventions, `register: decidesk`, schemas by slug): a `MyDeclarations` self-service page listing the current user's own nevenfuncties and geschenken with add/edit, end, and annual-confirm actions; `Nevenfuncties`/`NevenfunctieDetail` and `Geschenken`/`GeschenkDetail` pages with quick filters on governance body, lifecycle/besluit, and boven-drempel, plus CSV export via `ExportService` + `CnMassExportDialog`. The Nevenfuncties index SHALL carry a per-body compliance panel listing active members (from `person-and-membership` Memberships) with their declaration status, marking members with no declaration or none reviewed in the past 12 months — assistive, computed client-side from standard OR list queries. The Dashboard manifest page SHALL carry a declarative stat widget "Nevenfuncties zonder actuele review" counting non-`beëindigd` Nevenfunctie objects whose review is more than 12 months old (source aggregation `metric: count`), deep-linking to the pre-filtered Nevenfuncties index. `MyDeclarations` is the stable deep-link target for the `member-onboarding` nevenfuncties-intake step.

#### Scenario: Member manages own declarations

- GIVEN a logged-in member with one nevenfunctie and one geschenk
- WHEN they open MyDeclarations
- THEN they see only their own objects and can add a nevenfunctie, register a geschenk, end a position, and confirm the annual review
- AND they cannot edit another member's declarations

#### Scenario: Griffie filters the per-body register and exports

- GIVEN nevenfuncties across two governance bodies
- WHEN the griffie filters the Nevenfuncties index on the gemeenteraad and exports via the mass-export dialog
- THEN only that body's declarations are listed and the CSV contains person, organisatie, functie, bezoldigd, q.q., dates, and lifecycle

#### Scenario: Compliance panel flags members without reviewed declarations

- GIVEN a body with three active members of whom one has no Nevenfunctie object and one has only a declaration reviewed 14 months ago
- WHEN the griffie opens the compliance panel on the Nevenfuncties index
- THEN those two members are marked as lacking a current reviewed declaration and the third shows as current

#### Scenario: Dashboard KPI counts overdue reviews

- GIVEN two non-terminal nevenfuncties with reviews older than 12 months, one current, and one `beëindigd`
- WHEN the dashboard renders
- THEN the KPI shows 2 and clicking it opens the Nevenfuncties index filtered to the overdue set

## Non-Functional Requirements

- **Performance:** register indexes paginate via the standard OR list API; the KPI is a single count aggregation; the compliance panel issues at most two list queries per body (memberships + nevenfuncties) — no N+1.
- **Accessibility:** Target WCAG 2.2 AA; pages use standard manifest-v2 components (index/detail/stat) carrying the fleet's gate-checked semantics; badges and highlights carry text alternatives (no colour-only meaning).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] All three schemas register from fragment 62 and validate required fields; no existing schema modified
- [ ] Nevenfunctie lifecycle enforced by x-openregister-lifecycle only (canonical `initial`); no app-side state machine
- [ ] Published nevenfuncties (and geschenken where policy allows) are anonymously readable via the OR predicate; internal/unpublished ones are not; public-publication eligibility gates untouched
- [ ] Body-type defaults apply when no policy object exists (council public, others internal, EUR 50)
- [ ] Annual rappel and integrity notifications fire declaratively per the specced conditions and never imperatively
- [ ] COI dialog and chair panel show assistive nevenfuncties context without any automatic conflict handling
- [ ] MyDeclarations, register pages, compliance panel, CSV export, and dashboard KPI work from the manifest fragment
- [ ] member-onboarding's stable names hold: capability `interests-and-integrity`, slug `nevenfunctie`, page `MyDeclarations`

## Notes

- Integrates with `conflict-of-interest` (REQ-COI-001 dialog, REQ-COI-002 panel) — never re-specs it; COI declarations remain agenda-item notes with audit (REQ-COI-004).
- Supersedes `fractievoorzitter-fractie-koppeling` REQ-012's register/notification/rappel mechanics (generalized to all five domains, OR predicate surface instead of an app-local `/raad/nevenfuncties` page); composes with its fractie-portaal, which deep-links here.
- Supersedes `Membership.otherPositions` for new data; the legacy free-text field is not removed or migrated automatically.
- Related: `toezeggingen-register` (predicate-on-live-object precedent), `public-publication` (conventions; untouched), `governing-documents-register` (stores the gedragscode text), `member-onboarding` (consumes stable names above).
- ORI/OpenRaadsinformatie defines no nevenfunctie/gift types; `schema:Role` and `schema:Action` annotations follow the register's x-schema-org marker convention.
