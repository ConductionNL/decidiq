## ADDED Requirements

### Requirement: REQ-SDM-001 Register definition exists

The system SHALL provide a `decidesk` OpenRegister register defined in `lib/Settings/decidesk_register.json` following OpenAPI 3.0.0 format with `x-openregister` extensions.

#### Scenario: Register file is present and valid

- **WHEN** the Decidesk app is installed on a Nextcloud instance with OpenRegister active
- **THEN** the file `lib/Settings/decidesk_register.json` exists and is valid OpenAPI 3.0.0 JSON

#### Scenario: RepairStep imports register on install

- **WHEN** Nextcloud runs the Decidesk repair step (`RepairStep.php`)
- **THEN** `ConfigurationService::importFromApp('decidesk')` is called and the `decidesk` register appears in OpenRegister

---

### Requirement: REQ-SDM-002 GovernanceBody schema

The system SHALL define a `GovernanceBody` schema (type `schema:Organization`) with the following required properties: `name`, `bodyType`, `domain`. The `bodyType` field SHALL accept values: `legislative`, `association`, `corporate-board`, `operational`, `citizen-panel`.

#### Scenario: GovernanceBody created with valid bodyType

- **WHEN** a GovernanceBody object is submitted with `bodyType: "legislative"` and required fields
- **THEN** OpenRegister accepts the object and returns HTTP 201

#### Scenario: GovernanceBody rejected with invalid bodyType

- **WHEN** a GovernanceBody object is submitted with `bodyType: "unknown"`
- **THEN** OpenRegister returns HTTP 400 with a validation error on the `bodyType` field

---

### Requirement: REQ-SDM-003 Meeting schema

The system SHALL define a `Meeting` schema (type `schema:Event`) with required properties: `title`, `meetingType`, `scheduledDate`, `meetingMode`, `lifecycle`. The `lifecycle` field SHALL accept values: `draft`, `scheduled`, `opened`, `paused`, `adjourned`, `closed`.

#### Scenario: Meeting created in draft lifecycle

- **WHEN** a Meeting object is submitted with `lifecycle: "draft"` and all required fields
- **THEN** the object is stored and retrievable via the OpenRegister REST API

#### Scenario: Meeting linked to GovernanceBody

- **WHEN** a Meeting object includes an OpenRegister relation to a GovernanceBody
- **THEN** the relation is stored and accessible via the relations endpoint

---

### Requirement: REQ-SDM-004 AgendaItem schema

The system SHALL define an `AgendaItem` schema (type `custom:AgendaItem`) with required properties: `title`, `itemType`, `orderNumber`. The `itemType` field SHALL accept values: `informational`, `discussion`, `decision`.

#### Scenario: AgendaItem created with orderNumber

- **WHEN** an AgendaItem object is submitted with `orderNumber: 3`, `itemType: "decision"`, and `title`
- **THEN** the object is stored with the exact orderNumber value

#### Scenario: AgendaItem linked to Meeting

- **WHEN** an AgendaItem includes an OpenRegister relation to a Meeting
- **THEN** the relation is resolvable and the Meeting object is returned when the relation is expanded

---

### Requirement: REQ-SDM-005 Motion schema

The system SHALL define a `Motion` schema (type `custom:Motion`) with required properties: `title`, `text`, `motionType`, `proposer`, `lifecycle`, `submittedAt`. The `lifecycle` field SHALL accept values: `submitted`, `debating`, `voting`, `adopted`, `rejected`, `withdrawn`.

#### Scenario: Motion submitted successfully

- **WHEN** a Motion object is submitted with all required fields and `lifecycle: "submitted"`
- **THEN** OpenRegister stores the object and returns HTTP 201

#### Scenario: Motion co-signers stored as array

- **WHEN** a Motion object includes `coSigners: ["Jan Smit", "Lisa de Jong"]`
- **THEN** the `coSigners` field is stored as a JSON array and retrievable intact

---

### Requirement: REQ-SDM-006 Amendment schema

The system SHALL define an `Amendment` schema (type `custom:Amendment`) with required properties: `title`, `text`, `proposer`, `lifecycle`, `submittedAt`. The `lifecycle` field SHALL accept values: `submitted`, `debating`, `voting`, `adopted`, `rejected`. Amendments SHALL relate to a Motion.

#### Scenario: Amendment linked to Motion

- **WHEN** an Amendment object includes an OpenRegister relation to a Motion
- **THEN** the relation is stored and the Amendment appears in the Motion's related objects

---

### Requirement: REQ-SDM-007 VotingRound schema

The system SHALL define a `VotingRound` schema (type `custom:VotingRound`) with required properties: `votingMethod`, `isSecret`. The `votingMethod` field SHALL accept values: `for-against-abstain`, `ranked-choice`, `weighted`, `show-of-hands`.

#### Scenario: VotingRound created with secret ballot

- **WHEN** a VotingRound is created with `isSecret: true` and `votingMethod: "for-against-abstain"`
- **THEN** the object is stored and `isSecret` is preserved as boolean `true`

#### Scenario: VotingRound result recorded

- **WHEN** a VotingRound is updated with `result: "adopted"`, `votesFor: 28`, `votesAgainst: 3`, `votesAbstain: 2`
- **THEN** all count fields are stored as integers and the `result` enum is accepted

---

### Requirement: REQ-SDM-008 Vote schema

The system SHALL define a `Vote` schema (type `custom:Vote`) with required properties: `value`, `castAt`. The `value` field SHALL accept: `for`, `against`, `abstain`. Votes SHALL relate to a VotingRound and a Participant.

#### Scenario: Vote cast in a VotingRound

- **WHEN** a Vote is created with `value: "for"`, `castAt` timestamp, and relations to a VotingRound and Participant
- **THEN** the Vote is stored and both relations are resolvable

#### Scenario: Proxy vote recorded

- **WHEN** a Vote is created with `isProxy: true`
- **THEN** the `isProxy` boolean is stored as `true`

---

### Requirement: REQ-SDM-009 Decision schema

The system SHALL define a `Decision` schema (type `custom:Decision`) with required properties: `title`, `text`, `decisionDate`, `outcome`. The `outcome` field SHALL accept: `adopted`, `rejected`. Decisions SHALL relate to a Motion and have one-to-many relation to ActionItems.

#### Scenario: Decision created with outcome adopted

- **WHEN** a Decision object is submitted with `outcome: "adopted"` and all required fields
- **THEN** OpenRegister stores the Decision and returns HTTP 201

#### Scenario: Decision published via ORI flag

- **WHEN** a Decision is updated with `isPublished: true` and `publishedAt` timestamp
- **THEN** both fields are stored and the Decision is queryable by `isPublished`

---

### Requirement: REQ-SDM-010 ActionItem schema

The system SHALL define an `ActionItem` schema (type `custom:ActionItem`) with required properties: `title`, `taskStatus`. The `taskStatus` field SHALL accept: `open`, `in-progress`, `completed`, `overdue`. ActionItems SHALL relate to a Decision and a Meeting.

#### Scenario: ActionItem created as open task

- **WHEN** an ActionItem is created with `taskStatus: "open"`, `title`, and an assignee
- **THEN** the object is stored and retrievable

#### Scenario: ActionItem marked completed

- **WHEN** an ActionItem is updated with `taskStatus: "completed"` and `completedAt` timestamp
- **THEN** both fields are stored correctly

---

### Requirement: REQ-SDM-011 Minutes schema

The system SHALL define a `Minutes` schema (type `custom:Minutes`) with required properties: `title`, `lifecycle`. The `lifecycle` field SHALL accept: `draft`, `review`, `approved`, `signed`, `published`. Minutes SHALL have a one-to-one relation to a Meeting.

#### Scenario: Minutes linked to Meeting

- **WHEN** a Minutes object is created with a relation to a Meeting
- **THEN** the relation is stored and the Minutes is retrievable via the Meeting's related objects

#### Scenario: Minutes signed with multiple signatories

- **WHEN** Minutes are updated with `signedBy: ["Roos de Vries", "Jan Bakker"]` and `lifecycle: "signed"`
- **THEN** the `signedBy` array is stored intact

---

### Requirement: REQ-SDM-012 Participant schema

The system SHALL define a `Participant` schema (type `schema:Person`) with required properties: `displayName`, `role`. The `role` field SHALL accept: `chair`, `vice-chair`, `secretary`, `member`, `observer`, `guest`. Participants SHALL relate to a GovernanceBody.

#### Scenario: Participant with voting weight created

- **WHEN** a Participant is created with `role: "member"` and `votingWeight: 1`
- **THEN** the object is stored with the correct weight value

#### Scenario: Participant departure recorded

- **WHEN** a Participant is updated with a `leftAt` timestamp
- **THEN** the field is stored and the Participant can be filtered by `leftAt` presence

---

### Requirement: REQ-SDM-013 DigitalDocument schema

The system SHALL define a `DigitalDocument` schema (type `schema:DigitalDocument`) with required properties: `name`, `documentType`.

#### Scenario: DigitalDocument created with MIME type

- **WHEN** a DigitalDocument is created with `encodingFormat: "application/pdf"`
- **THEN** the field is stored as a string

---

### Requirement: REQ-SDM-014 MonetaryAmount schema

The system SHALL define a `MonetaryAmount` schema (type `schema:MonetaryAmount`) with required properties: `value`, `currency`. The `currency` field SHALL be an ISO 4217 code.

#### Scenario: MonetaryAmount stored with currency

- **WHEN** a MonetaryAmount is created with `value: 50000`, `currency: "EUR"`
- **THEN** both fields are stored and retrievable

---

### Requirement: REQ-SDM-015 Offer schema

The system SHALL define an `Offer` schema (type `schema:Offer`) with required properties: `name`, `price`, `priceCurrency`.

#### Scenario: Offer with validity period

- **WHEN** an Offer is created with `validFrom` and `validThrough` datetime values
- **THEN** both fields are stored as ISO 8601 datetimes

---

### Requirement: REQ-SDM-016 Order schema

The system SHALL define an `Order` schema (type `schema:Order`) with required properties: `orderNumber`, `orderDate`, `orderStatus`, `totalPrice`, `currency`.

#### Scenario: Order created with payment terms

- **WHEN** an Order is created with `paymentTerms: "NET30"`
- **THEN** the field is stored as a string

---

### Requirement: REQ-SDM-017 Product schema

The system SHALL define a `Product` schema (type `schema:Product`) with required properties: `name`, `unitPrice`, `currency`.

#### Scenario: Product with tax rate

- **WHEN** a Product is created with `taxRate: 21`
- **THEN** the field is stored as a number

---

### Requirement: REQ-SDM-018 Report schema

The system SHALL define a `Report` schema (type `schema:Report`) with required properties: `name`, `reportType`.

#### Scenario: Report with period

- **WHEN** a Report is created with `period: "2025-Q1"`
- **THEN** the field is stored as a string

---

### Requirement: REQ-SDM-019 Seed data loaded on install

The system SHALL load 3–5 seed objects per schema into the `decidesk` register when the RepairStep runs, using the `@self` envelope with Dutch organisational values.

#### Scenario: Seed data present after install

- **WHEN** the RepairStep completes successfully
- **THEN** at least 3 GovernanceBody objects, 3 Meeting objects, and 3 Motion objects exist in the `decidesk` register

#### Scenario: Seed data import is idempotent

- **WHEN** the RepairStep runs a second time (e.g., after upgrade)
- **THEN** no duplicate seed objects are created (upsert by slug)

---

### Requirement: REQ-SDM-020 Email-to-decision linking via _mail metadata

The system SHALL support linking incoming emails to Decision objects using OpenRegister's `_mail` metadata column, so that related correspondence is part of the decision dossier.

#### Scenario: Email linked to decision by reference number

- **WHEN** an email arrives mentioning a decision reference number
- **THEN** the email appears linked to the corresponding Decision object in OpenRegister AND is visible in the Nextcloud Mail sidebar for that Decision

#### Scenario: Linked email visible in decision dossier

- **WHEN** a user opens a Decision object in Decidesk
- **THEN** all linked emails are shown in the related mail section via `_mail` metadata

---

### Requirement: REQ-SDM-021 Resolution register supports ORI publication

The system SHALL support marking Decisions as published (`isPublished: true`) and recording the publication timestamp (`publishedAt`), enabling integration with the ORI open data API.

#### Scenario: Decision marked as published

- **WHEN** a Decision is updated with `isPublished: true`
- **THEN** the Decision is filterable by `isPublished=true` via the OpenRegister REST API

#### Scenario: Unpublished decisions excluded from public listing

- **WHEN** a public ORI API consumer queries Decisions
- **THEN** only Decisions with `isPublished: true` are returned
