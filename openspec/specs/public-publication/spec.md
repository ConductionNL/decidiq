---
status: in-progress
openspec-changes:
  - toezeggingen-ingekomen-stukken
  - vragenuur-interpellatie
---

# public-publication Specification

## Purpose
Publishes eligible decisions, public meeting agendas, and approved minutes as derived, PII-stripped payloads through OpenRegister's RBAC published-predicate surface and, when configured, into an OpenCatalogi catalog. It enforces server-side eligibility gates and a type deny-list, builds immutable allow-list payloads carrying vote totals (never individual votes or voter identities), aligns payloads to OpenRaadsinformatie mappings, and supports auditable withdraw and rectify flows so governance data can be opened to the public without exposing confidential material.
## Requirements
### Requirement: Publication eligibility gates

The system SHALL allow publication only of: `Decision` objects in status `decided` or `enacted`; meeting agendas whose parent `Meeting` has `isPublic: true` and whose convocation has been sent; and `Minutes` objects in lifecycle `approved`. Eligibility SHALL be enforced server-side on every publish request, independent of UI state. The system SHALL maintain a type-level deny-list — `BoardMeeting`, `BoardMinutes`, `BoardMaterial`, `BoardVote`, `ConflictOfInterest`, `BoardAuditLogEntry`, `Vote`, `VotingRound`, and `Resolution` objects of boards with a confidentiality classification — for which publication payload construction SHALL be structurally refused.

#### Scenario: Publish an enacted decision

- **GIVEN** a staff member with governance-body authority and a decision in status `enacted`
- **WHEN** they trigger the publish action on the decision detail view
- **THEN** a publication payload is created and the decision shows as published

#### Scenario: Draft decision refused

@e2e exclude eligibility-matrix contract — covered by PHPUnit (PublicationEligibilityServiceTest::testDraftDecisionRefused) and Newman against the publish endpoint
- **GIVEN** a decision in status `draft`
- **WHEN** a publish request is made for it
- **THEN** the request is rejected with an eligibility error and no publication payload or `PublicationRecord` is created

#### Scenario: Agenda of a non-public meeting refused

@e2e exclude eligibility-matrix contract — covered by Newman against the publish endpoint
- **GIVEN** a meeting with `isPublic: false` and a finalized agenda
- **WHEN** an agenda publish request is made
- **THEN** the request is rejected and nothing is published

#### Scenario: Board material structurally refused

@e2e exclude type deny-list contract — covered by PHPUnit on the payload service plus Newman negative test
- **WHEN** a publish request targets a `BoardMinutes` or `BoardMaterial` object (any status)
- **THEN** the payload service refuses with a not-publishable error before any eligibility evaluation and no object is created

#### Scenario: Non-staff publish rejected

@e2e exclude API authorization contract — covered by Newman, not a UI flow
- **WHEN** an authenticated user without governance-body authority calls the publish endpoint for an eligible decision
- **THEN** the request is rejected with HTTP 403 via OpenRegister per-object RBAC and nothing is published

---

### Requirement: Derived publication payloads with PII stripping

Publication SHALL create a derived, immutable payload object per publish action — never set the publication predicate (`publicatiedatum`) on the live `Decision`/`Meeting`/`Minutes` objects. Payloads SHALL be constructed allow-list style (field-by-field): decision payloads carry title, decision text, outcome, decisionDate, legalBasis, body name, and vote totals only; agenda payloads carry meeting metadata and the ordered agenda items with confidential items and their document references stripped; minutes payloads carry the approved content version with attendance rendered per the body's configured policy (counts, or names of role-holders). No payload SHALL contain individual votes, voter identities, NC UIDs, or contact details.

#### Scenario: Decision payload carries totals, not voters

@e2e exclude data-shape assertion on the published object — covered by Newman reading the published-predicate surface
- **GIVEN** a published decision that was decided in a roll-call vote
- **WHEN** the publication payload is read through the OR published-predicate surface
- **THEN** it contains for/against/abstain totals and contains no per-member vote records, voter identities, or NC UIDs

#### Scenario: Confidential agenda item stripped

- **GIVEN** a public meeting agenda with three items of which one is marked confidential
- **WHEN** staff publish the agenda
- **THEN** the published agenda payload lists exactly the two non-confidential items and no reference to the confidential item or its documents

#### Scenario: Published payload is immutable

@e2e exclude immutability contract — covered by Newman attempting a write to the payload object
- **GIVEN** an existing publication payload
- **WHEN** any user (including admin) attempts to modify it
- **THEN** the modification is rejected; corrections happen only through the rectify flow

---

### Requirement: Publication via the OR RBAC published-predicate and OpenCatalogi routing

On publish, the system SHALL set `publicatiedatum` on the payload object via the OpenRegister object API (a normal field write on a register-owned object). The PublicationPayload schema SHALL declare an `authorization.read` rule granting the `public` group read access while `publicatiedatum <= $now`, so the published payload becomes readable through OR's anonymous RBAC published-predicate surface. When OpenCatalogi is installed and a target catalog is configured for the governance body, the payload SHALL additionally be routed into that catalog as a publication, and the catalog publication reference SHALL be stored on the `PublicationRecord`. When OpenCatalogi is absent or unconfigured, the predicate step SHALL still run, the catalog step SHALL be skipped, and a staff-visible warning SHALL be shown. The system SHALL NOT serve app-local anonymous pages or unauthenticated read endpoints for published governance data. (`@self.published` is deprecated/removed from OpenRegister and SHALL NOT be used.)

#### Scenario: Published decision reaches the configured catalog

- **GIVEN** a governance body with a configured target OpenCatalogi catalog
- **WHEN** staff publish an enacted decision
- **THEN** the payload object carries a `publicatiedatum` in the past (making it public-group readable) and a publication referencing it exists in the configured catalog

#### Scenario: OpenCatalogi absent degrades gracefully

- **GIVEN** OpenCatalogi is not installed
- **WHEN** staff publish an approved set of minutes
- **THEN** the payload still receives `publicatiedatum` (and is anonymously readable via the public-group RBAC rule), the catalog step is skipped, and a staff-visible warning is shown

#### Scenario: No app-local public surface

@e2e exclude negative routing assertion — covered by Newman (unauthenticated requests to app routes)
- **WHEN** an unauthenticated client requests any decidiq route
- **THEN** no published governance data is returned by the app itself; the only anonymous read path is OR/OpenCatalogi's publication surface

---

### Requirement: OpenRaadsinformatie-aligned payload shape

Publication payloads SHALL carry the OpenRaadsinformatie mappings the decidiq specs already cite, as structured fields alongside the schema.org annotations: decision payloads as ORI `Besluit`, agenda payloads as ORI `Vergadering` with `AgendaPunt` items, minutes payloads as ORI `Verslag`. Each payload SHALL declare its `oriType` so OpenCatalogi/OR public API consumers can interpret records ORI-compatibly without a bespoke harvester. An OAI-PMH/ORI harvester endpoint is explicitly out of scope for decidiq.

#### Scenario: Published decision exposes Besluit mapping

@e2e exclude payload-shape contract — covered by Newman schema assertions on the published object
- **WHEN** a published decision payload is read through the publication surface
- **THEN** it declares `oriType: "Besluit"` and carries the ORI-mapped fields for title, text, outcome, and decision date

#### Scenario: Published agenda exposes Vergadering with AgendaPunt items

@e2e exclude payload-shape contract — covered by Newman
- **WHEN** a published agenda payload is read
- **THEN** it declares `oriType: "Vergadering"` and its items are ORI `AgendaPunt` entries preserving the agenda order

---

### Requirement: Withdraw and rectify

Staff with governance-body authority SHALL be able to withdraw a publication with a mandatory reason: the payload's `depublicatiedatum` is set (removing it from the public-group RBAC surface), the OpenCatalogi publication is retracted when one exists, the source object's published state is reset, and actor, reason, and timestamp are recorded on the `PublicationRecord` and in the source object's audit trail. The withdrawn payload SHALL be soft-retained for audit. Rectification SHALL publish a new payload version that references the version it rectifies (`rectifiesVersion`) and withdraw the old version in the same operation — published payloads are never edited in place. When retraction from the catalog fails, the system SHALL surface the failure to staff and retry rather than report success.

#### Scenario: Withdraw a published decision

- **GIVEN** a published decision and a staff member with governance-body authority
- **WHEN** they withdraw the publication with a reason
- **THEN** the payload carries a `depublicatiedatum` in the past (so the public-group RBAC rule no longer returns it), the catalog publication is retracted, the decision's `isPublished` becomes false, and the audit trail records actor, reason, and timestamp

#### Scenario: Rectify a publication

- **GIVEN** a published minutes payload containing an error
- **WHEN** staff rectify it after the minutes correction is approved
- **THEN** a new payload version is published carrying `rectifiesVersion` pointing at the old version, and the old version is withdrawn in the same operation

#### Scenario: Catalog retraction failure surfaced

@e2e exclude remote-failure branch — covered by PHPUnit with a failing catalog client
- **GIVEN** a withdraw operation whose OpenCatalogi retraction call fails
- **WHEN** the operation completes
- **THEN** the local predicate is cleared, the `PublicationRecord` marks the catalog retraction as pending, and staff see the failure instead of a success state

---

### Requirement: Publication administration and conventions

Admins SHALL configure, per governance body: the target OpenCatalogi catalog, the per-type publication policy (`manual-only` or `prompt-on-transition`), and the attendance-rendering policy for minutes payloads. All configuration SHALL be stored as OpenRegister objects or app config — no bespoke tables. `PublicationRecord` objects SHALL live in the decidesk register with OR per-object RBAC, and publication notifications SHALL be declared exclusively via the ADR-031 `x-openregister-notifications` dialect on `PublicationRecord` (created → body members notified; withdraw covered by the update trigger). Under `prompt-on-transition`, the system SHALL only prompt staff — it SHALL never publish without an explicit staff action.

#### Scenario: Admin configures a catalog target

- **GIVEN** an admin on the decidiq admin settings page
- **WHEN** they set a target catalog and `prompt-on-transition` policy for the council body
- **THEN** publishing council decisions routes to that catalog and staff are prompted to publish when a decision reaches `enacted`

#### Scenario: Prompt never auto-publishes

- **GIVEN** a body with `prompt-on-transition` policy
- **WHEN** a decision transitions to `enacted` and the prompt is dismissed
- **THEN** nothing is published and the decision remains unpublished until staff explicitly publish it

#### Scenario: No imperative notification dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate
- **WHEN** the notification-dialect gate scans the publication code paths
- **THEN** no imperative object-notification dispatch exists; all publication notifications are declarative rules in `decidesk_register.json`

