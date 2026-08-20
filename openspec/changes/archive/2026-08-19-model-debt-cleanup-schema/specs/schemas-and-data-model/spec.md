# schemas-and-data-model Delta — model-debt-cleanup-schema

## ADDED Requirements

### Requirement: REQ-SDM-022 Decision declares its meeting and agendaItem joins

The `decision` schema SHALL declare optional `meeting` ($ref `Meeting`, `facetable: true`) and `agendaItem` ($ref `AgendaItem`, `facetable: true`) properties. Neither is required — a `decision` MAY exist without either (e.g. a citizen-panel or nomination decision created outside a meeting flow).

#### Scenario: Decision created from a meeting tab carries a validated meeting reference

- **GIVEN** the `meeting` property is declared on `decision`
- **WHEN** `MeetingDecisionsTab.vue` creates a decision with `meeting: <meetingUuid>`
- **THEN** OpenRegister validates the property against the `decision` schema instead of silently accepting an undeclared field
- **AND** the decision is facetable/filterable by `meeting`

#### Scenario: Decision created from an agenda item carries a validated agendaItem reference

- **GIVEN** the `agendaItem` property is declared on `decision`
- **WHEN** `AgendaMotionsTab.vue` creates a decision with `agendaItem: <agendaItemUuid>`
- **THEN** OpenRegister validates the property against the `decision` schema
- **AND** the decision is facetable/filterable by `agendaItem`

---

### Requirement: REQ-SDM-023 ConflictOfInterest.boardMember references Membership, not the Participant shim

`ConflictOfInterest.boardMember` SHALL `$ref: Membership` (was `$ref: Participant`). The property name (`boardMember`) is unchanged; only its reference target changes, since a conflict-of-interest declaration is inherently a statement about a person's role/relationship in a specific governance body (`Membership` already carries `independenceStatus`, the exact MCCG-adjacent field a COI declaration is evaluated against) rather than a bare identity.

#### Scenario: New conflict-of-interest declaration references a Membership

- **WHEN** a `conflict-of-interest` object is created after this change
- **THEN** its `boardMember` property MUST hold a `Membership` UUID
- **AND** OpenRegister rejects a `Participant` UUID as a type mismatch once the retarget is live

---

### Requirement: REQ-SDM-024 ProxyAuthorization references Person, gains proxyStatus; BoardProxy is retired

`ProxyAuthorization.grantor` and `ProxyAuthorization.holder` SHALL `$ref: Person` (was `$ref: Participant`). `ProxyAuthorization` SHALL additionally declare an optional `proxyStatus` property (enum `pending-approval`/`active`/`suspended`/`revoked`, default `pending-approval`), carrying the approval-workflow concept previously unique to `BoardProxy`. The `board-proxy` schema SHALL be marked `x-openregister.active: false` with a description pointing readers at `ProxyAuthorization` + `proxyStatus`; it SHALL NOT be deleted (existing rows and the `hardDelete: false` convention both require this).

#### Scenario: New proxy authorization references Person and carries an approval state

- **WHEN** a `proxyAuthorization` object is created after this change
- **THEN** `grantor` and `holder` MUST hold `Person` UUIDs
- **AND** `proxyStatus` defaults to `pending-approval` when omitted

#### Scenario: BoardProxy is inactive but not deleted

- **GIVEN** the decidesk register is imported after this change
- **WHEN** the `board-proxy` schema definition is inspected
- **THEN** `x-openregister.active` is `false`
- **AND** the schema definition and its slug are still present (not removed from the register)

---

### Requirement: REQ-SDM-025 GoverningDocument carries a current-in-force convenience property

`GoverningDocument` SHALL declare an optional `currentEffectiveDate` property (nullable `date`, `facetable: true`), mirroring `Regeling.currentEffectiveDate` in shape and the same maintenance caveat (a convenience field, not a live-computed aggregation — see design.md).

#### Scenario: GoverningDocument gains the property with no value on existing rows

- **GIVEN** an existing `GoverningDocument` object created before this change
- **WHEN** the register is re-imported with the new fragment
- **THEN** the object's `currentEffectiveDate` reads as `null` until a future write populates it (schema-only change; no retroactive backfill in this change)

---

### Requirement: REQ-SDM-026 Slug hygiene — advice-request and proxy-authorization

The schema previously slugged `adviceRequest` SHALL be slugged `advice-request`; the schema previously slugged `proxyAuthorization` SHALL be slugged `proxy-authorization`. Both renames SHALL be reflected in `components.registers.decidesk.schemas`, in every manifest reference (`src/manifest.json`, `src/manifest.d/advisory-opinion-workflow.json`, `src/manifest.d/member-proxy-authorization.json`), and in the schema's own seed-data object collection key. The unrelated `ConsultationRequest.type` enum literal `"adviceRequest"` (register.d/47-works-council-consultation.json) is NOT part of this rename — it is a coincidental string collision with a different field on a different schema.

#### Scenario: Advice-request schema resolves under its new kebab-case slug

- **WHEN** the register is imported after this change
- **THEN** `components.registers.decidesk.schemas` contains `advice-request` and no longer contains `adviceRequest`
- **AND** every manifest page/widget that referenced `"schema": "adviceRequest"` now reads `"schema": "advice-request"`

#### Scenario: Proxy-authorization schema resolves under its new kebab-case slug

- **WHEN** the register is imported after this change
- **THEN** `components.registers.decidesk.schemas` contains `proxy-authorization` and no longer contains `proxyAuthorization`
- **AND** every manifest page/widget that referenced `"schema": "proxyAuthorization"` now reads `"schema": "proxy-authorization"`

#### Scenario: The unrelated WOR consultation-request enum value is untouched

- **GIVEN** `register.d/47-works-council-consultation.json`'s `ConsultationRequest.type` enum, which includes the literal `"adviceRequest"` (art. 25 WOR advice request — an unrelated concept)
- **WHEN** this change's slug rename is applied
- **THEN** that enum literal is unchanged, and the works-council-consultation quick filter `{ "type": "adviceRequest" }` continues to match on the `type` field, not a schema slug
