# delegatie-mandaatregister Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- delegatie-mandaatregister

## Purpose

A queryable delegatie- en mandaatregister: `Bevoegdheidstoedeling` objects recording who may exercise which authority on whose behalf — delegatie, mandaat, volmacht, or machtiging (Awb afdeling 10.1.1/10.1.2) — with delegans and delegataris, scope and limits, wettelijke grondslag, a trace to the authorizing delegatie-/mandaatbesluit (`Decision`), a validity window, ondermandaat chains, register views (per delegans, per delegataris, in-force-on-date, search, CSV export), live public publication via OpenRegister's published-predicate, an assistive bevoegdheidsgrondslag link from Decision details, and declarative expiry notifications. The register stores *relations*, not documents: publication of the delegatiebesluit text itself (CVDR/DROP) belongs to the verordeningenregister capability. The register documents authority; it never enforces it.

**Standards**: Awb afdeling 10.1.1 (mandaat) / 10.1.2 (delegatie), BW Boek 3 titel 3 (volmacht), Schema.org (`AuthorizeAction` via the register's `x-schema-org` marker convention), OpenRaadsinformatie (`Besluit` linkage)
**Feature tier**: V1
**Legal reference**: Awb art. 10:3-10:12 (mandaat), 10:13-10:21 (delegatie); mandaatregister raadpleegbaarheid

## ADDED Requirements

### Requirement: REQ-DMR-001 Bevoegdheidstoedeling schema

The system MUST provide a `Bevoegdheidstoedeling` OpenRegister schema in the decidesk register, shipped as fragment `lib/Settings/register.d/54-delegatie-mandaatregister.json` (ADR-037 — the base `decidesk_register.json` is never edited for the new schema), carrying: `type` (required enum: `delegatie`, `mandaat`, `volmacht`, `machtiging`); the delegans/mandaatgever as `delegans` (UUID reference to a GovernanceBody) and/or `delegansOmschrijving` (role description, e.g. "burgemeester") — at least one MUST be present; the delegataris/gemandateerde as `delegatarisBody` (GovernanceBody reference), `delegatarisFunctie` (function/role description, e.g. "afdelingshoofd Samenleving"), and/or `delegatarisPersoon` (Person reference) — at least one MUST be present; `onderwerp` (subject/scope description, required); limits `financieelPlafond` (monetary amount, optional), `beperkingen` (subject constraints text, optional), and `ondermandaatToegestaan` (boolean, default false); `wettelijkeGrondslag` (array of legal-basis citations, same shape as the verordeningenregister capability, e.g. "Awb art. 10:3"); `besluit` (required UUID reference to the authorizing `Decision` — the delegatie-/mandaatbesluit); `geldigVanaf` (date, required) and `geldigTot` (date, optional); and `ingetrokkenDoor` (optional UUID reference to the revoking `Decision`). Every property MUST carry a `title`; the manifest and all widget/filter sources MUST reference the schema by its slug `bevoegdheidstoedeling`.

#### Scenario: Register a mandaat traced to its mandaatbesluit

- GIVEN the governance body "College van B&W" and a seed Decision "Algemeen mandaatbesluit 2026"
- WHEN staff create a Bevoegdheidstoedeling with type `mandaat`, delegans = College van B&W, delegatarisFunctie "gemeentesecretaris", onderwerp "afdoening subsidieaanvragen", financieelPlafond 25000, besluit = that Decision, geldigVanaf 2026-01-01
- THEN a `bevoegdheidstoedeling` object MUST be created in the decidesk register referencing the GovernanceBody and Decision by UUID

#### Scenario: Missing authorizing decision refused

- GIVEN a staff user creating a Bevoegdheidstoedeling
- WHEN they submit without a `besluit` reference or without any delegataris field
- THEN OpenRegister schema validation MUST reject the request and no object is created

#### Scenario: Register fragment is additive

- GIVEN a decidiq installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the Bevoegdheidstoedeling schema is registered from fragment `54-delegatie-mandaatregister.json`
- AND no existing schema definition in `decidesk_register.json` is removed or restructured

### Requirement: REQ-DMR-002 Declarative status lifecycle with intrekking traced to a revoking decision

The `Bevoegdheidstoedeling` schema MUST declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keys `field`/`initial`/`states`/`terminal`/`transitions`, keyword `initial` — never `initialState`/`default`): field `status`, initial `concept`, states `concept → van-kracht → ingetrokken | vervallen`, with `ingetrokken` and `vervallen` terminal. Transitions outside the declared map MUST be rejected by OpenRegister; the app SHALL NOT implement a parallel state machine. The intrekking UI action MUST require selecting the revoking Decision and MUST store it in `ingetrokkenDoor` in the same save, so every `ingetrokken` toedeling traces to the besluit that revoked it. `vervallen` records lapse by time (geldigTot passed) or by the underlying besluit expiring, without a revoking decision.

#### Scenario: Toedeling comes into force and is later revoked

- GIVEN a Bevoegdheidstoedeling in status `concept`
- WHEN staff set it to `van-kracht`, and later revoke it selecting the Decision "Intrekkingsbesluit mandaat inkoop 2026"
- THEN the object transitions `concept → van-kracht → ingetrokken`
- AND `ingetrokkenDoor` references that revoking Decision by UUID

#### Scenario: Undeclared transition rejected declaratively

- GIVEN a Bevoegdheidstoedeling in status `ingetrokken`
- WHEN any user attempts to set the status back to `van-kracht`
- THEN OpenRegister MUST reject the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-DMR-003 Ondermandaat chains are permitted, displayed, and cycle-free

A `Bevoegdheidstoedeling` MAY reference its parent toedeling via `parentToedeling` (UUID self-reference, optional). The system MUST accept a `parentToedeling` only when the referenced parent exists and has `ondermandaatToegestaan: true`; the guard MUST be enforced server-side on save, MUST fail closed, and MUST reject any chain in which walking the parent references revisits a node (self-parent and longer cycles alike). The detail view MUST display the chain: the toedeling's depth (hoofdmandaat = depth 0) and its ancestors up to the root, and the register views MUST allow following the chain in both directions (parent link, list of ondermandaten).

#### Scenario: Ondermandaat under a permitting parent

- GIVEN a van-kracht mandaat to the gemeentesecretaris with `ondermandaatToegestaan: true`
- WHEN staff create an ondermandaat with `parentToedeling` set to it, delegatarisFunctie "afdelingshoofd Samenleving", and financieelPlafond 5000
- THEN the ondermandaat is created and its detail page shows depth 1 with the parent chain to the hoofdmandaat

#### Scenario: Parent forbids ondermandaat

- GIVEN a toedeling with `ondermandaatToegestaan: false`
- WHEN staff attempt to create a toedeling with `parentToedeling` referencing it
- THEN the save MUST be rejected with an error naming the parent's ondermandaat prohibition
- AND no object is created

#### Scenario: Cycle refused

- GIVEN toedeling A with parent B, and B with parent A attempted (or A referencing itself)
- WHEN the save runs
- THEN the guard MUST reject the write with a cycle error
- AND the stored objects are unchanged

### Requirement: REQ-DMR-004 Register views — per delegans, per delegataris, in-force-on-date, search, CSV export

The system MUST provide a Bevoegdheidstoedelingen index page and detail page as manifest pages in a `src/manifest.d/` fragment (ADR-037; `register: decidesk`, `schema: bevoegdheidstoedeling`, slug references only). The index MUST support: filtering per delegans (GovernanceBody or role description), per delegataris (body, function, or person), per `type`, and per `status`; a "geldig op" date filter answering "which toedelingen are in force on date X" as a pure query — status `van-kracht` AND `geldigVanaf <= X` AND (`geldigTot` empty OR `geldigTot >= X`) — with no per-row resolution service; full-text search over onderwerp, beperkingen, and delegataris description; and CSV export via `ExportService` + `CnMassExportDialog` including type, delegans, delegataris, onderwerp, financieelPlafond, wettelijke grondslag, besluit, geldigheid, and status.

#### Scenario: Who may decide what on a given date

- GIVEN toedelingen with geldigVanaf/geldigTot windows around 2026-06-01, of which one is `ingetrokken`
- WHEN a staff user sets the "geldig op" filter to 2026-06-01
- THEN exactly the `van-kracht` toedelingen whose validity window covers that date are listed
- AND the ingetrokken toedeling is absent

#### Scenario: Per-delegataris view and export

- GIVEN five toedelingen of which two name the delegatarisFunctie "gemeentesecretaris"
- WHEN the user filters on that delegataris and exports via the mass-export dialog
- THEN the list shows the two toedelingen and the CSV contains their type, delegans, onderwerp, financieelPlafond, besluit reference, geldigheid, and status

### Requirement: REQ-DMR-005 Public register via the OR published-predicate on the live object

The system MUST make the public delegatie- en mandaatregister available through OpenRegister's anonymous RBAC published-predicate surface, following the toezeggingen-register predicate-on-live-object pattern: the `Bevoegdheidstoedeling` schema declares an `authorization.read` rule granting the `public` group read access while `publicatiedatum <= $now`, and staff publish a toedeling by setting `publicatiedatum` (withdraw by setting `depublicatiedatum`). Because the predicate sits on the live object, the public register MUST reflect status changes (an intrekking, a vervallen lapse) without any republication step. The schema SHALL NOT carry internal-only fields (no confidential remarks property), so the whole object is publishable by construction; this constraint MUST be recorded in the schema `description`. The system SHALL NOT serve app-local anonymous pages and SHALL NOT introduce a derived publication payload for this register. Publication MUST never happen without an explicit staff action.

#### Scenario: Published toedeling is anonymously readable

- GIVEN a Bevoegdheidstoedeling published by staff (publicatiedatum in the past)
- WHEN an unauthenticated client reads the OR published-predicate surface
- THEN the toedeling is returned with type, delegans, delegataris, onderwerp, limits, grondslag, geldigheid, and status

#### Scenario: Intrekking is live on the public register

- GIVEN a published toedeling in status `van-kracht`
- WHEN staff revoke it (status `ingetrokken`, `ingetrokkenDoor` set)
- THEN the next anonymous read shows status `ingetrokken` without any republish step

#### Scenario: Unpublished toedeling is not public

- GIVEN a Bevoegdheidstoedeling without `publicatiedatum`
- WHEN an unauthenticated client queries the published surface
- THEN the toedeling is not returned

### Requirement: REQ-DMR-006 Assistive bevoegdheidsgrondslag link on Decision — never enforcement

The existing `Decision` schema MUST gain a nullable `bevoegdheidsgrondslag` property (UUID reference to a `Bevoegdheidstoedeling`), and the Decision detail page MUST display it, when set, as "genomen krachtens" with a link to the toedeling's detail page. The linkage is assistive documentation only: the system SHALL NOT block, warn on, or otherwise gate any Decision creation, transition, or enactment on the presence, validity window, limits, or status of a referenced (or absent) toedeling. The toedeling detail page SHOULD list decisions that reference it (reverse lookup).

#### Scenario: Decision records the mandate it was taken under

- GIVEN a van-kracht mandaat "afdoening subsidieaanvragen" and a Decision taken by the gemandateerde
- WHEN staff set the Decision's bevoegdheidsgrondslag to that toedeling
- THEN the Decision detail shows the toedeling as "genomen krachtens" linking to its detail page

#### Scenario: No enforcement without or outside a mandate

- GIVEN a Decision with no bevoegdheidsgrondslag, and another Decision referencing an `ingetrokken` toedeling
- WHEN either Decision is created, transitioned, or enacted
- THEN no block, error, or warning is raised on account of the bevoegdheidsgrondslag
- AND both flows behave identically to a Decision without the property

### Requirement: REQ-DMR-007 Declarative geldigheid-expiry notifications

Expiry reminders MUST be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on the `Bevoegdheidstoedeling` schema: scheduled triggers that notify the responsible staff group when a toedeling in status `van-kracht` approaches its `geldigTot` (provisional windows: 60 days and 14 days before), with Dutch and English subjects. No notification MUST be sent for toedelingen without `geldigTot` or in a terminal status. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Expiry rappel fires

- GIVEN a `van-kracht` toedeling whose geldigTot is 10 days from now
- WHEN the scheduled notification trigger evaluates
- THEN the responsible staff recipients receive a Nextcloud notification naming the toedeling and its expiry date

#### Scenario: Terminal and open-ended toedelingen stay silent

- GIVEN an `ingetrokken` toedeling with geldigTot 10 days from now and a `van-kracht` toedeling without geldigTot
- WHEN the scheduled notification trigger evaluates
- THEN no notification is sent for either

## Non-Functional Requirements

- **Performance:** the index, the "geldig op" filter, and the chain display answer from indexed OR list queries; rendering a 200-row register or one detail chain MUST NOT trigger per-row N+1 object fetches.
- **Accessibility:** list, detail, and public pages MUST meet WCAG 2.1 AA; the ondermandaat chain display MUST be navigable by keyboard and announced by screen readers.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); Dutch legal terms (mandaat, delegatie, volmacht, machtiging, ondermandaat, geldig op) remain untranslated domain vocabulary in both locales; i18n keys in English.

## Acceptance Criteria

- [ ] Register fragment `54-delegatie-mandaatregister.json` imports cleanly on a fresh instance with schema, lifecycle, relations, publication predicate, and notifications
- [ ] A toedeling cannot be created without besluit/delegataris; undeclared lifecycle transitions are rejected by OR alone
- [ ] Ondermandaat guard rejects forbidden parents, self-parents, and cycles server-side
- [ ] "Geldig op" filter returns exactly the in-force set for boundary dates (day of geldigVanaf, day of geldigTot)
- [ ] Published toedelingen are anonymously readable and reflect intrekking live; unpublished ones are not returned
- [ ] Decision detail shows the assistive bevoegdheidsgrondslag link; no flow blocks on its absence or invalidity
- [ ] Expiry notifications fire declaratively and never for terminal or open-ended toedelingen
- [ ] Seed data provides a delegatie, a mandaat with a published ondermandaat chain, a concept volmacht, and an ingetrokken machtiging traced to a revoking decision

## Notes

- Fragment number 54 is assigned to this change (ADR-037); 40–53 and 55–65 belong to sibling changes.
- Boundary with `verordeningenregister`: that sibling owns regeling-type *document* publication (CVDR/DROP, consolidated texts, wijzigingsbesluit versioning). A delegatie-/mandaatbesluit publishes its text there when it qualifies as a regeling; this register holds the queryable *relations* the besluit establishes and shares the `wettelijkeGrondslag` citation vocabulary.
- Adjacency with `urgent-decision-procedure`: spoedbevoegdheid (who may trigger an urgent procedure) is per-body `urgencyPolicy` configuration, not a Bevoegdheidstoedeling; a spoedbesluit MAY reference a toedeling via REQ-DMR-006 like any other Decision.
- Deliberately no MODIFIED delta on public-publication's eligibility-gates requirement: this register uses the predicate-on-live-object pattern (toezeggingen-register D4), and the carve-out lives in this capability's own ADDED requirements.
- ORI defines no delegation/mandate type; the schema uses the register's `x-schema-org` marker convention (`schema:AuthorizeAction`) with agent = delegans, participant = delegataris, object = the authorizing Decision.
