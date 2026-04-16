# Data Model — Decidesk

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Platform:** OpenRegister (register/schema/object pattern)
**Entities:** 23

OpenRegister built-in fields available on ALL entities (do NOT redefine):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

OpenRegister built-in capabilities (do NOT rebuild):
CRUD REST API, CSV/JSON/XML import+export, full-text search, filtering,
pagination, audit trails, file attachments, relation management, locking.

---

## ActionItem
**Schema.org type:** `caldav:VTODO`
**Purpose:** A follow-up task from a meeting decision
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Task title |
| description | string | No | Task details |
| assignee | string | No | Assigned participant |
| dueDate | string | No | Due date |
| taskStatus | string | Yes | Current task status |
| completedAt | string | No | Completion timestamp |

---

## AgendaItem
**Schema.org type:** `meeting:AgendaItem`
**Purpose:** An item on a meeting agenda with type, time, and ordering
**Primary spec:** p2-agenda-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Agenda item title |
| itemType | string | Yes | Type of agenda item |
| orderNumber | integer | Yes | Position on the agenda |
| estimatedDuration | integer | No | Estimated minutes |
| actualDuration | integer | No | Actual minutes spent |
| description | string | No | Detailed description |
| isRecurring | boolean | No | Appears on every meeting |

---

## Amendment
**Schema.org type:** `meeting:Amendment`
**Purpose:** A proposed change to an existing motion
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Amendment title |
| text | string | Yes | Amendment text (change description) |
| proposer | string | Yes | Name of proposer |
| lifecycle | string | Yes | Amendment lifecycle state |
| submittedAt | string | Yes | Submission timestamp |

---

## Area
**Schema.org type:** `popolo:Area`
**Purpose:** A geographic or jurisdictional area. Popolo: Area. Links a governance body to its jurisdiction (municipality, province, waterboard district).
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Area name (Popolo: name) |
| identifier | string | No | Official code e.g. CBS gemeentecode (Popolo: identifier) |
| classification | string | No | Type: municipality, province, waterboard, national (Popolo: classification) |

**Relations:**
- → GovernanceBody (one-to-many)

---

## ContactDetail
**Schema.org type:** `popolo:ContactDetail`
**Purpose:** A means of contacting a person or organization. Popolo: ContactDetail. Replaces the single email field on Participant with typed, multi-value contacts.
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| label | string | No | Human-readable label (Popolo: label) |
| type | string | Yes | Channel type: email, phone, fax, cell, address, url (Popolo: type) |
| value | string | Yes | Contact value e.g. email address (Popolo: value) |
| note | string | No | Usage note (Popolo: note) |
| validFrom | datetime | No | Start of validity (Popolo: valid_from) |
| validUntil | datetime | No | End of validity (Popolo: valid_until) |

**Relations:**
- → Person (many-to-one)
- → GovernanceBody (many-to-one)

---

## Decision
**Schema.org type:** `custom:Decision`
**Purpose:** A formal decision resulting from a vote
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Decision title |
| text | string | Yes | Decision text |
| decisionDate | string | Yes | When the decision was made |
| outcome | string | Yes | Decision outcome |
| isPublished | boolean | No | Published via ORI API |
| publishedAt | string | No | Publication timestamp |
| legalBasis | string | No | Legal article or regulation |

---

## DigitalDocument
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Schema.org DigitalDocument for document metadata

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document name/title |
| documentType | string | Yes | Document type (contract, tender, report, etc.) |
| description | string | No | Document description |
| encodingFormat | string | No | MIME type (application/pdf, etc.) |
| contentSize | string | No | File size |

---

## GovernanceBody
**Schema.org type:** `org:Organization`
**Purpose:** A governance body (council, board, committee, assembly)
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Body name |
| bodyType | string | Yes | Type of governance body |
| domain | string | Yes | Governance domain preset |
| workflowTemplate | string | No | State machine workflow config |
| quorumRule | string | No | Quorum calculation method |
| votingDefault | string | No | Default voting method |
| termStart | string | No | Current term start |
| termEnd | string | No | Current term end |

---

## Meeting
**Schema.org type:** `meeting:Meeting`
**Purpose:** A scheduled governance meeting with agenda, participants, and lifecycle
**Primary spec:** p2-meeting-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Meeting title |
| meetingType | string | Yes | Type of meeting |
| scheduledDate | string | Yes | Start date and time |
| endDate | string | No | End date and time |
| location | string | No | Physical location or video link |
| meetingMode | string | Yes | Meeting mode |
| lifecycle | string | Yes | Meeting lifecycle state |
| quorumRequired | integer | No | Minimum participants for valid meeting |
| series | string | No | Meeting series identifier |

---

## Membership
**Schema.org type:** `org:Membership`
**Purpose:** Relationship between a person and an organization, including role and time bounds. Popolo: Membership. Replaces the role field on Participant — a person can have multiple memberships in different governance bodies.
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| role | string | Yes | Role in the organization: chair, vice-chair, secretary, member, observer, guest (Popolo: role) |
| label | string | No | Descriptive label for the membership |
| startDate | datetime | No | When the membership started (Popolo: start_date) |
| endDate | datetime | No | When the membership ended, null if active (Popolo: end_date) |
| votingWeight | number | No | Vote weight for this membership, default 1 |
| party | string | No | Political party or faction (Popolo: on_behalf_of) |

**Relations:**
- → Person (many-to-one)
- → GovernanceBody (many-to-one)
- → Post (many-to-one)

---

## Minutes
**Schema.org type:** `meeting:Report`
**Purpose:** Official record of a meeting's proceedings
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Minutes title |
| lifecycle | string | Yes | Minutes lifecycle state |
| content | string | No | Full minutes text |
| approvedAt | string | No | Approval timestamp |
| signedBy | array | No | Digital signers (chair + secretary) |
| version | integer | No | Revision number |

---

## MonetaryAmount
**Schema.org type:** `schema:MonetaryAmount`
**Purpose:** Schema.org MonetaryAmount for monetary values

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | number | Yes | Numeric value |
| currency | string | Yes | ISO 4217 currency code |

---

## Motion
**Schema.org type:** `opengov:Motion`
**Purpose:** A formal proposal submitted for debate and voting
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Motion title |
| text | string | Yes | Full motion text |
| motionType | string | Yes | Type of motion |
| proposer | string | Yes | Name of proposer |
| coSigners | array | No | List of co-signers |
| lifecycle | string | Yes | Motion lifecycle state |
| submittedAt | string | Yes | Submission timestamp |

---

## Offer
**Schema.org type:** `schema:Offer`
**Purpose:** Schema.org Offer for offer/quote data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Offer/quote name |
| price | number | Yes | Offered price |
| priceCurrency | string | Yes | Currency |
| validFrom | string | No | Offer valid from |
| validThrough | string | No | Offer valid until |
| availability | string | No | Availability status |

---

## Order
**Schema.org type:** `schema:Order`
**Purpose:** Schema.org Order for purchase order data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | string | Yes | Date of order |
| orderStatus | string | Yes | Order status |
| totalPrice | number | Yes | Total order amount |
| currency | string | Yes | ISO 4217 currency code |
| deliveryDate | string | No | Expected delivery date |
| paymentTerms | string | No | Payment terms (e.g., NET30) |

---

## Participant
**Schema.org type:** `foaf:Person`
**Purpose:** A member or attendee of a governance body
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| displayName | string | Yes | Display name |
| role | string | Yes | Role within the governance body |
| party | string | No | Political party or faction |
| email | string | No | Contact email |
| joinedAt | string | No | When they joined the body |
| leftAt | string | No | When they left (null = active) |
| votingWeight | number | No | Vote weight (default 1) |

---

## Person
**Schema.org type:** `foaf:Person`
**Purpose:** An individual person who participates in governance. Popolo: Person. Replaces Participant — person data separated from membership/role data.
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Full name (Popolo: name) |
| familyName | string | No | Family name (Popolo: family_name) |
| givenName | string | No | Given name (Popolo: given_name) |
| gender | string | No | Gender (Popolo: gender) |
| birthDate | date | No | Date of birth (Popolo: birth_date) |
| image | string | No | URL to photo (Popolo: image) |
| biography | string | No | Short bio (Popolo: biography) |
| email | string | No | Primary email (convenience field, full contacts via ContactDetail) |

**Relations:**
- → Membership (one-to-many)
- → ContactDetail (one-to-many)
- → Speech (one-to-many)
- → Vote (one-to-many)

---

## Post
**Schema.org type:** `org:Post`
**Purpose:** A formal position within a governance body that can be filled by a person via Membership. Popolo: Post. Examples: Chair, Secretary, Treasurer.
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| label | string | Yes | Position title (Popolo: label) |
| role | string | No | Role type: chair, vice-chair, secretary, member (Popolo: role) |
| startDate | datetime | No | When the post was created |
| endDate | datetime | No | When the post was abolished |

**Relations:**
- → GovernanceBody (many-to-one)

---

## Product
**Schema.org type:** `schema:Product`
**Purpose:** Schema.org Product for product/service data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product name |
| sku | string | No | Stock keeping unit |
| description | string | No | Product description |
| category | string | No | Product category |
| unitPrice | number | Yes | Unit price |
| currency | string | Yes | ISO 4217 currency code |
| unitCode | string | No | Unit of measure (UN/CEFACT) |
| taxRate | number | No | Applicable tax rate percentage |

---

## Report
**Schema.org type:** `schema:Report`
**Purpose:** Schema.org Report for report metadata

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type (financial, compliance, etc.) |
| period | string | No | Reporting period |
| generatedAt | string | No | When the report was generated |

---

## Speech
**Schema.org type:** `opengov:Speech`
**Purpose:** A speech or statement made during a meeting. Popolo: Speech. ORI extends this with SpeechQuestion, SpeechAnswer, SpeechNarrative, SpeechSummary subtypes. Later phase — not in initial implementation.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| text | string | Yes | Transcript text of the speech (Popolo: text) |
| role | string | No | Role of speaker: chair, member, guest (Popolo: role) |
| startDate | datetime | No | When the speech started (Popolo: start_date) |
| endDate | datetime | No | When the speech ended (Popolo: end_date) |
| audio | string | No | URL to audio recording (Popolo: audio) |
| video | string | No | URL to video recording (Popolo: video) |

**Relations:**
- → Meeting (many-to-one)
- → AgendaItem (many-to-one)
- → Person (many-to-one)

---

## Vote
**Schema.org type:** `opengov:Vote`
**Purpose:** An individual vote cast in a voting round
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | string | Yes | Vote value |
| weight | number | No | Vote weight (for weighted voting) |
| isProxy | boolean | No | Cast via proxy delegation |
| castAt | string | Yes | When the vote was cast |

---

## VotingRound
**Schema.org type:** `opengov:VoteEvent`
**Purpose:** A voting session on a motion or amendment
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| votingMethod | string | Yes | Method used for voting |
| isSecret | boolean | Yes | Secret ballot |
| openedAt | string | No | When voting opened |
| closedAt | string | No | When voting closed |
| quorumMet | boolean | No | Was quorum met |
| result | string | No | Voting result |
| votesFor | integer | No | Count of votes for |
| votesAgainst | integer | No | Count of votes against |
| votesAbstain | integer | No | Count of abstentions |

---
