# interpellatie-register Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [vragenuur-interpellatie](../../changes/vragenuur-interpellatie/)

## Purpose

Interpellation requests (interpellatieverzoeken) as first-class objects: a raadslid requests to interpellate a portefeuillehouder on a subject outside the order of the day (Gemeentewet art. 155 / local Reglement van Orde), collects support signatures against a per-body configurable threshold, the council decides on admission (verlof), and an admitted interpellation is scheduled as its own agenda item and its treatment recorded. Explicitly deferred to its own spec by `motie-amendement-administratie` (proposal.md line 90); motie van wantrouwen remains a separate, out-of-scope instrument.

## ADDED Requirements

### Requirement: REQ-VRI-009 Interpellatieverzoek schema on OpenRegister

The system SHALL define an `Interpellatieverzoek` schema in the decidesk register via the same assigned fragment `lib/Settings/register.d/49-vragenuur-interpellatie.json` (ADR-037), annotated `x-schema-org: schema:AskAction` (agent = verzoeker; in Akoma Ntoso terms the treatment is a debate section with `question`/`answer`/`speech` elements). The schema SHALL carry at minimum: `verzoekNummer` (string, format `INT-{jaar}-{volgnummer}`, auto-assigned, same per-body-per-year sequencing as MV/SV numbers), `verzoeker` (Raadslid/Person reference, required), `fractie` (Fractie reference, required), `onderwerp` (string, required, max 200), `vragen` (rich text — the questions to be put, required), `motivering` (rich text), `portefeuillehouder` (Person reference, required), `governanceBody` (GovernanceBody reference, required), `steunbetuigingen` (array of Raadslid references), `lifecycle` (required), `raadsbesluitDatum` (date — date of the council's admission decision), `afwijzingsReden` (string, required when rejected), `behandeldIn` (Meeting reference), `agendaItem` (AgendaItem reference — the interpellation's own agenda item), and `behandelingVerslag` (rich text — treatment record). Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `interpellatieverzoek`.

#### Scenario: Raadslid submits an interpellation request

- GIVEN a raadslid and a governance body with a VragenuurConfiguratie
- WHEN the raadslid submits an interpellatieverzoek with onderwerp, vragen, motivering, and the addressed portefeuillehouder
- THEN an Interpellatieverzoek object is created with an auto-assigned number `INT-{jaar}-{volgnummer}`
- AND the object validates against the schema (missing onderwerp or vragen is rejected by OpenRegister)

### Requirement: REQ-VRI-010 Declarative lifecycle for interpellation requests

The `Interpellatieverzoek` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`): field `lifecycle`, initial `ingediend`, states `ingediend → toegelaten | afgewezen` (the council's admission decision), `toegelaten → geagendeerd` (scheduled as its own agenda item), `geagendeerd → behandeld`, and `ingediend | toegelaten | geagendeerd → ingetrokken`, with `behandeld`, `afgewezen`, and `ingetrokken` terminal. Rejection SHALL carry an `afwijzingsReden`; admission and rejection SHALL record `raadsbesluitDatum`. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Council admits an interpellation

- GIVEN an Interpellatieverzoek in lifecycle `ingediend`
- WHEN the griffier records the council's decision as `toegelaten` with the raadsbesluitDatum
- THEN the transition is accepted and the request becomes schedulable

#### Scenario: Council rejects an interpellation

- GIVEN an Interpellatieverzoek in lifecycle `ingediend`
- WHEN the griffier records the council's decision as `afgewezen` with afwijzingsReden and raadsbesluitDatum
- THEN the object is terminal and the reason and decision date are stored

#### Scenario: Invalid transition rejected declaratively

@e2e exclude lifecycle-map contract — covered by Newman against the OR objects endpoint
- GIVEN an Interpellatieverzoek in lifecycle `behandeld`
- WHEN any user attempts to set the lifecycle back to `geagendeerd`
- THEN OpenRegister rejects the transition per the declared transition map

### Requirement: REQ-VRI-011 Support recording against the per-body threshold

The system SHALL record support signatures as `steunbetuigingen` (Raadslid references, added while the request is `ingediend`) and SHALL display the support status against the body's configured threshold from `VragenuurConfiguratie` (`interpellatieSteunDrempelType`/`-Waarde`, e.g. `breukdeel` 0.2 = 1/5 of the body's members per Reglement van Orde). The threshold status ("N of M required supporters") SHALL be computed for display from the configuration and the body's member count; it SHALL inform, not gate, the council's admission decision (the raad may grant verlof regardless — the threshold is a Reglement van Orde figure, not a hard system guard). A body without a VragenuurConfiguratie shows no threshold and the decision remains recordable.

#### Scenario: Support reaches the threshold

- GIVEN a 19-member council whose configuratie sets breukdeel 0.2 (4 supporters required, rounded up) and an `ingediend` interpellatieverzoek with 3 steunbetuigingen
- WHEN a fourth raadslid records support
- THEN the detail page shows the threshold as met (4 of 4)
- AND the request remains `ingediend` until the council's decision is recorded

#### Scenario: Admission recordable below the threshold

- GIVEN an `ingediend` interpellatieverzoek with support below the configured threshold
- WHEN the griffier records the council's decision as `toegelaten`
- THEN the transition is accepted (the threshold informs, it does not gate)
- AND the support count at decision time remains visible on the object

### Requirement: REQ-VRI-012 Admitted interpellation is scheduled as its own agenda item

The system SHALL let the griffier schedule a `toegelaten` interpellatieverzoek by linking it to its own `AgendaItem` on a target meeting (created via the ordinary `agenda-item-crud` flow — a separate item, never merged into the vragenuur item) and transitioning it to `geagendeerd`, setting `behandeldIn` and `agendaItem`.

#### Scenario: Interpellation gets its own agenda item

- GIVEN a toegelaten interpellatieverzoek and an upcoming raadsvergadering
- WHEN the griffier creates an agenda item "Interpellatie: veiligheid stationsgebied" on that meeting and links the request to it
- THEN the request becomes `geagendeerd` with behandeldIn and agendaItem set
- AND the agenda-item detail shows the linked interpellatieverzoek

### Requirement: REQ-VRI-013 Treatment recording

The system SHALL record the outcome of a `geagendeerd` interpellation by transitioning it to `behandeld` with `behandelingVerslag` (required at this transition — a summary of the interpellation debate and the portefeuillehouder's answers). Follow-up instruments raised during the debate (moties, toezeggingen) live in their own registers; the verslag MAY reference them, and a toezegging made during the treatment SHALL be registered in the sibling toezeggingen register, not duplicated here. Motie van wantrouwen is explicitly out of scope.

#### Scenario: Treatment recorded after the debate

- GIVEN a `geagendeerd` interpellatieverzoek treated in yesterday's raadsvergadering
- WHEN the griffier records the behandelingsverslag and transitions it to `behandeld`
- THEN the object is terminal with the verslag stored
- AND the detail page shows the meeting and agenda item where it was treated

### Requirement: REQ-VRI-014 Notifications and list/detail pages for interpellations

The `Interpellatieverzoek` schema SHALL declare notifications exclusively via `x-openregister-notifications` (ADR-031): `created` confirming submission to the verzoeker and the griffie group, and `updated` triggers notifying the verzoeker and portefeuillehouder on the admission decision (including afwijzingsReden on rejection) and on scheduling, with nl and en subjects. The system SHALL provide an Interpellaties index page (columns: verzoekNummer, onderwerp, verzoeker, fractie, portefeuillehouder, lifecycle, support status; quick filters on lifecycle and fractie) and a detail page (all fields; support recording; navigable meeting/agenda-item references; Files leaf and audit-trail sidebar; actions in explicit dialogs) as manifest-v2 pages in the same `src/manifest.d/` fragment, schema referenced by slug `interpellatieverzoek`. No decidiq CRUD controllers SHALL be added for listing or reading.

#### Scenario: Submission confirmation for an interpellation

@e2e exclude notification-dialect contract — covered by PHPUnit asserting the fragment's trigger declarations (gate-18 guards the dialect)
- GIVEN the schema's notification declarations
- WHEN a raadslid submits an interpellatieverzoek
- THEN the verzoeker and the griffie group receive a confirmation via the declared `created` trigger

#### Scenario: Index shows the support pipeline

- GIVEN seeded interpellatieverzoeken in mixed lifecycles
- WHEN a user opens the Interpellaties index
- THEN each row shows its lifecycle and support status against the body's threshold
- AND opening a row shows the detail page with support recording available while `ingediend`
