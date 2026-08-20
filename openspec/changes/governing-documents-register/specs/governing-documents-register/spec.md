# governing-documents-register Specification

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- governing-documents-register

## Purpose

Gives decidesk a versioned register of an organisation's own constitutive and internal governing documents — statuten, huishoudelijk reglement, reglement van orde, directiestatuut, splitsingsakte: `GoverningDocument` objects owned by a GovernanceBody with a `geldend → vervallen` lifecycle, immutable `GoverningDocumentVersie` objects where every amendment traces to the Decision that enacted it (besluit tot statutenwijziging etc.) and carries a consolidated-text document plus optional notarial-deed metadata, deterministic in-force resolution per date with a version timeline, a simple `{document, versie?, artikel?}` reference shape consumed by decisions and by the urgent-decision-procedure and vve-alv-pack siblings, member access by default with an optional public predicate, and declarative notifications when a new version takes effect. Boundary: the verordeningenregister sibling owns public-law regelingen with CVDR/DROP publication; this register owns private-law/internal documents and carries no bekendmaking machinery.

**Standards**: Schema.org (`DigitalDocument`, `Organization`), Akoma Ntoso (`doc`, FRBR work/expression versioning — mirrors the verordeningenregister `Regeling`/`RegelingVersie` conventions), OpenRaadsinformatie (`Besluit` linkage via the enacting Decision)
**Feature tier**: V1
**Legal reference**: BW 2:27 (statuten vereniging), BW 2:40-2:43 (statutenwijziging), Gemeentewet art. 16 (reglement van orde van de raad), BW 5:111 (splitsingsakte), BW 2:129/239 (bestuur; directiestatuut-praktijk)

## ADDED Requirements

### Requirement: REQ-GDR-001 GoverningDocument schema

The system MUST provide a `GoverningDocument` OpenRegister schema (Schema.org `DigitalDocument`, Akoma Ntoso `doc` work level) in the decidesk register carrying: `type` (required enum: `statuten`, `huishoudelijk-reglement`, `reglement-van-orde`, `directiestatuut`, `splitsingsakte`, `other`), `citeertitel` (required — the document's citable name), `omschrijving` (optional), `governingBody` (required UUID reference to the owning GovernanceBody), and `isPublic` (boolean, default false — see the access requirement). The schema MUST declare a lifecycle via `x-openregister-lifecycle` (canonical `field`/`initial`/`states`/`terminal`/`transitions` keys) with states `geldend → vervallen` (`geldend` initial, `vervallen` terminal); drafting happens at version level (`concept` versions), so no document-level draft state exists. Transitions outside the declared map MUST be rejected by OpenRegister; decidesk SHALL NOT implement a parallel state machine. This register MUST NOT carry CVDR identifiers, DROP delivery, or any bekendmaking fields — public-law regelingen belong to the verordeningenregister capability.

#### Scenario: Create the reglement van orde of a gemeenteraad

- GIVEN a staff user and the governance body "Gemeenteraad Amsterdam"
- WHEN they create a GoverningDocument with type `reglement-van-orde`, citeertitel "Reglement van orde gemeenteraad Amsterdam" and governingBody "Gemeenteraad Amsterdam"
- THEN an OpenRegister object MUST be created in the decidesk register with the `governing-document` schema in lifecycle state `geldend`
- AND the object MUST reference the GovernanceBody by UUID

#### Scenario: Citeertitel and governing body are required

- GIVEN a staff user creating a GoverningDocument
- WHEN they submit without a citeertitel or without a governingBody reference
- THEN OpenRegister schema validation MUST reject the request and no object is created

#### Scenario: Undeclared lifecycle transition rejected

- GIVEN a GoverningDocument in state `vervallen`
- WHEN a transition back to `geldend` is attempted
- THEN the transition MUST be rejected because it is not in the declared transition map

### Requirement: REQ-GDR-002 GoverningDocumentVersie traced to its enacting decision

The system MUST provide a `GoverningDocumentVersie` OpenRegister schema (Akoma Ntoso expression level, mirroring the verordeningenregister `RegelingVersie` conventions) carrying: `document` (UUID reference to the parent GoverningDocument, required), `versienummer` (positive integer, unique per document), `vastgesteldDoor` (UUID reference to the Decision that enacted this version — besluit tot statutenwijziging, vaststellingsbesluit, ALV-besluit), `inwerkingtreding` (date, required before activation), `vervaldatum` (optional date), `toelichting` (summary of what this version changes), a consolidated-text document attached through OpenRegister's file abstraction, and optional plain notarial-deed metadata for notarially enacted documents (statuten, splitsingsakte): `aktedatum` (date) and `notaris` (string, name only). The trace rule MUST be: every version of a document that already has at least one sealed version MUST carry a resolvable `vastgesteldDoor` Decision reference — creating such an amendment version without one MUST be rejected; only the first (constitutive/historical) version of a document MAY omit `vastgesteldDoor`, in which case notarial-deed metadata or a `toelichting` documenting its origin SHOULD be recorded. The version lifecycle MUST be declared via `x-openregister-lifecycle` with states `concept → vastgesteld → in-werking → vervangen | vervallen` (`vervangen` and `vervallen` terminal).

#### Scenario: Create a statutenwijziging version from an enacted decision

- GIVEN a GoverningDocument "Statuten Vereniging van Nederlandse Gemeenten" with a sealed version 1 and an enacted Decision "Besluit statutenwijziging VNG 2021"
- WHEN staff create GoverningDocumentVersie 2 with vastgesteldDoor pointing at that Decision, inwerkingtreding 2022-01-01, aktedatum 2021-12-15, notaris "mr. A. de Wit", and an attached consolidated-text document
- THEN a `governing-document-versie` object MUST be created in state `concept` referencing the document and the Decision by UUID
- AND the version's consolidated text MUST be stored via OpenRegister's file abstraction

#### Scenario: Amendment version without enacting decision refused

- GIVEN a GoverningDocument with one sealed version
- WHEN staff attempt to create a second GoverningDocumentVersie without a `vastgesteldDoor` Decision reference
- THEN the request MUST be rejected with a validation error naming the missing enacting-decision link
- AND no version object is created

#### Scenario: Constitutive first version may omit the decision link

- GIVEN a new GoverningDocument "Splitsingsakte VvE Parkstaete" with no versions
- WHEN staff create version 1 without `vastgesteldDoor` but with aktedatum 2005-06-01 and notaris "mr. B. Jansen"
- THEN the version MUST be accepted and stored with the notarial-deed metadata

#### Scenario: Version numbers are unique per document

- GIVEN a GoverningDocument whose latest version has versienummer 2
- WHEN staff attempt to create another version with versienummer 2 for the same document
- THEN the request MUST be rejected with a uniqueness error

### Requirement: REQ-GDR-003 Version immutability once effective

The system MUST seal a GoverningDocumentVersie when it transitions to `in-werking`: from that moment its `versienummer`, `vastgesteldDoor`, `inwerkingtreding`, notarial-deed metadata, and consolidated-text document reference MUST be immutable, enforced server-side independent of UI state. Corrections MUST be made as a new GoverningDocumentVersie traced to its own (rectification) decision — never by editing a sealed version. Sealed versions form the stable, immutable reference target that consuming capabilities (urgent-decision-procedure's statutes-permit-urgency reference, vve-alv-pack's splitsingsakte reference, decision citations per REQ-GDR-005) MUST be able to reference by UUID with the guarantee that the referenced content never changes.

#### Scenario: Editing a sealed version refused

- GIVEN a GoverningDocumentVersie in state `in-werking`
- WHEN any user (including an admin) attempts to change its inwerkingtreding date or replace its consolidated-text document
- THEN the write MUST be rejected with an error naming the sealed state
- AND the stored object MUST be unchanged

#### Scenario: Correction happens as a new version

- GIVEN a sealed GoverningDocumentVersie 2 containing a text error
- WHEN staff process the correction
- THEN they MUST create GoverningDocumentVersie 3 with vastgesteldDoor pointing at the rectifying decision
- AND version 2 MUST remain unchanged and transition to `vervangen` when version 3 takes force

#### Scenario: External capability references a sealed version

- GIVEN a sealed GoverningDocumentVersie referenced by UUID from another object (e.g. a Decision citation)
- WHEN the referencing object is read at any later time
- THEN resolving the reference MUST yield the identical versienummer, inwerkingtreding, and consolidated-text document as at sealing time

### Requirement: REQ-GDR-004 In-force resolution per date

The system MUST resolve, for any GoverningDocument and any date X, which GoverningDocumentVersie is in force on that date: the version with the latest `inwerkingtreding` less than or equal to X whose state is `in-werking`, or `vervangen` by a version taking force after X, and whose `vervaldatum` (if set) is after X; if the document itself is `vervallen` on X or no version qualifies, the resolution MUST return "no version in force". The resolution MUST be deterministic and exposed as a service method other capabilities can call, as a GET endpoint, and in the UI as a version timeline with a "geldend op" date control. Activation validation MUST refuse sealing a version whose `inwerkingtreding` is not after the inwerkingtreding of the currently latest sealed version, so in-force resolution can never be ambiguous.

#### Scenario: Resolve the version in force on a historical date

- GIVEN a GoverningDocument with sealed versions 1 (inwerkingtreding 1990-05-01) and 2 (2022-01-01)
- WHEN the in-force resolution is asked for date 2021-06-15
- THEN it MUST return version 1

#### Scenario: No version in force before the first inwerkingtreding

- GIVEN the same GoverningDocument
- WHEN the resolution is asked for date 1985-01-01
- THEN it MUST return "no version in force"

#### Scenario: Out-of-order activation refused

- GIVEN a GoverningDocument whose latest sealed version has inwerkingtreding 2022-01-01
- WHEN staff attempt to seal a new version with inwerkingtreding 2021-06-01
- THEN the activation MUST be refused with an error naming the ordering conflict

### Requirement: REQ-GDR-005 Governing-document reference shape and decision citations

The system MUST define one simple, canonical governing-document reference shape used by all consumers: an object carrying `document` (UUID of a GoverningDocument, required), `versie` (UUID of a sealed GoverningDocumentVersie, optional — omitted means "the version in force at reading time" per REQ-GDR-004), and `artikel` (optional plain string, e.g. "art. 14 lid 3"). The existing `decision` schema MUST gain an additive, optional `citesGoverningDocuments` array of this shape (fragment-located per ADR-037, no required field added, existing decisions stay valid) so a decision can cite the governing-document article it is based on. The citation is assistive: it MUST NOT block or gate any decision workflow. The urgent-decision-procedure sibling (which statutes permit urgency) and the vve-alv-pack sibling (splitsingsakte reference) SHALL use this same shape; their consuming wiring stays in those changes. The Decision detail page MUST render cited governing documents as navigable links, and the GoverningDocument detail page MUST list the decisions citing it via reverse lookup.

#### Scenario: A decision cites a statuten article

- GIVEN a Decision "Besluit spoedprocedure inkoop" and the GoverningDocument "Statuten Vereniging van Nederlandse Gemeenten"
- WHEN a secretary adds a citation with document = the statuten, artikel = "art. 14 lid 3"
- THEN the Decision stores the `{document, artikel}` entry in `citesGoverningDocuments`
- AND the Decision detail page renders the citation as a link navigating to the governing-document detail page

#### Scenario: Existing decisions remain valid after the additive delta

- GIVEN Decisions created before this change with no `citesGoverningDocuments` set
- WHEN the updated `decision` schema version is imported
- THEN those Decisions validate unchanged (the property is optional, no required field added)

#### Scenario: Cited-by list on the governing-document detail page

- GIVEN two Decisions citing the reglement van orde of Gemeenteraad Amsterdam
- WHEN a user opens that GoverningDocument's detail page
- THEN both citing decisions MUST be listed with their artikel strings, each navigating to the Decision detail page

### Requirement: REQ-GDR-006 Governing-documents list and detail pages

The system MUST provide an internal governing-documents list page (filterable by type, status, and governing body; searchable on citeertitel) and a detail page showing the document's metadata, its full version timeline (each version with versienummer, inwerkingtreding, state, link to its enacting decision where present, notarial-deed metadata where present, and consolidated text), a "geldend op" date control driving the REQ-GDR-004 resolution, and the citing-decisions list (REQ-GDR-005). The enacting-decision link on each version MUST navigate to the existing Decision detail page. Pages MUST follow the app's manifest/store conventions (manifest fragment with schema refs by slug) and be reachable from the app navigation.

#### Scenario: Browse and filter the register

- GIVEN eight governing documents of mixed types and states
- WHEN a staff user filters the list on type `statuten` and status `geldend`
- THEN only matching documents MUST be listed with citeertitel, type, governing body, and current-version inwerkingtreding

#### Scenario: Version timeline with decision traceability

- GIVEN a GoverningDocument with a constitutive version 1 and an amendment version 2
- WHEN a staff user opens its detail page
- THEN both versions MUST be shown in inwerkingtreding order with their states
- AND version 2's enacting-decision link MUST navigate to that Decision's detail page
- AND version 1 MUST show its notarial-deed metadata instead of a decision link

### Requirement: REQ-GDR-007 Member access by default, optional public predicate

Governing documents and their versions MUST be internal by default: readable by members of the organisation per OpenRegister RBAC (ADR-022; no app-local authorization service). A staff user MAY set the `isPublic` predicate on a GoverningDocument (a boolean on the live object, the same predicate-on-live-object pattern as `isPublished` — this change MUST NOT modify public-publication's eligibility-gates requirement). When `isPublic` is true, the system MUST expose, via OpenRegister's published-predicate RBAC surface following public-publication's conventions, only: the document's citeertitel, type, governing body name, and the current sealed version's inwerkingtreding and consolidated-text document. `concept` versions, internal `toelichting` annotations, and the `notaris` person name MUST never appear in the public payload (PII-stripping convention). Eligibility MUST be enforced server-side: a document with no sealed current version MUST NOT be publicly exposed even when `isPublic` is true.

#### Scenario: Vereniging publishes its statuten

- GIVEN the GoverningDocument "Statuten Vereniging van Nederlandse Gemeenten" in state `geldend` with a sealed current version and `isPublic=true`
- WHEN an anonymous visitor requests the public governing-document view
- THEN the citeertitel, type, current inwerkingtreding, and consolidated text MUST be accessible
- AND the `notaris` name and any `concept` version MUST be structurally absent from the payload

#### Scenario: Internal document stays internal

- GIVEN a GoverningDocument "Directiestatuut ACME B.V." with `isPublic=false`
- WHEN an anonymous visitor attempts to read it
- THEN access MUST be denied by the OR RBAC surface and no metadata is disclosed

#### Scenario: Public predicate without a sealed version exposes nothing

- GIVEN a GoverningDocument with `isPublic=true` whose only version is in state `concept`
- WHEN an anonymous visitor requests the public view
- THEN the document MUST NOT be exposed (server-side eligibility)

### Requirement: REQ-GDR-008 Notification on a new effective version

The system MUST notify members of the owning governance body's organisation when a GoverningDocumentVersie takes effect (transitions to `in-werking`). The notification MUST be declared via the canonical `x-openregister-notifications` dialect (ADR-031) using only verified keys (`trigger.type: "updated"` with a condition on the version's lifecycle state field equalling `in-werking`, `channels[]`, `recipients[]`, inline `subject{nl,en}` — e.g. nl "Nieuwe geldende versie: {{title}}" / en "New effective version: {{title}}"). Recipients MUST be routed via `kind:object-acl` and `kind:groups` (group `decidesk-members`); `kind:field` MUST NOT be used on any non-uid property. Decidesk SHALL NOT dispatch this notification imperatively.

#### Scenario: Members notified when a statutenwijziging takes effect

- GIVEN a GoverningDocumentVersie of the VNG statuten in state `vastgesteld`
- WHEN it transitions to `in-werking`
- THEN the notification rule fires an NC notification to `object-acl` readers and the `decidesk-members` group with the nl/en new-effective-version subject

#### Scenario: No notification on non-effective transitions

- GIVEN a GoverningDocumentVersie transitioning from `concept` to `vastgesteld`
- WHEN the notification evaluation runs
- THEN no new-effective-version notification MUST be sent

## Non-Functional Requirements

- **Performance:** the list page and in-force resolution MUST answer from indexed OR queries; resolving the current version for a list of 200 documents MUST NOT trigger per-document N+1 object fetches.
- **Accessibility:** list and detail pages MUST meet WCAG 2.1 AA; the version timeline MUST be navigable by keyboard and announced by screen readers.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); Dutch legal terms (statuten, reglement van orde, splitsingsakte, inwerkingtreding, citeertitel) remain untranslated domain vocabulary in both locales.

## Acceptance Criteria

- [ ] Register fragment `55-governing-documents-register.json` imports cleanly on a fresh instance with both schemas, lifecycles, relations, notifications, and the additive `decision.citesGoverningDocuments` property
- [ ] An amendment version cannot be created without an enacting decision, a constitutive first version can, and no version can be edited once in force
- [ ] In-force resolution returns the correct version for boundary dates (day of inwerkingtreding, day of vervaldatum)
- [ ] Public exposure shows only sealed current-version data for `isPublic` documents and strips `notaris`, `toelichting`, and concept versions
- [ ] Seed data provides statuten of a vereniging (with notarial metadata and a wijzigings-chain), a reglement van orde of a gemeenteraad, and a VvE splitsingsakte
- [ ] Notification fires exactly on the `in-werking` transition

## Notes

- Fragment number 55 is assigned to this change (ADR-037); 40–54 and 56–65 belong to sibling changes.
- Boundary with verordeningenregister: verordeningen = public-law regelingen with CVDR/DROP bekendmaking; this register = private-law/internal constitutive documents, no publication machinery. Whether the verordeningenregister `huishoudelijk-reglement-vng` seed should migrate here is a deferred question for that sibling.
- urgent-decision-procedure and vve-alv-pack consume the REQ-GDR-005 reference shape; their wiring stays in those changes.
- The version vocabulary (versienummer, vastgesteldDoor, inwerkingtreding, seal-on-effective, correction-as-new-version) deliberately mirrors verordeningenregister's RegelingVersie so staff learn one model.
