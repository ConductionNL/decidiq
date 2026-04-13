# Data Model — Decidesk

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Platform:** OpenRegister (register/schema/object pattern)
**Entities:** 17

OpenRegister built-in fields available on ALL entities (do NOT redefine):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

OpenRegister built-in capabilities (do NOT rebuild):
CRUD REST API, CSV/JSON/XML import+export, full-text search, filtering,
pagination, audit trails, file attachments, relation management, locking.

---

## ActionItem
**Schema.org type:** `custom:ActionItem`
**Purpose:** A follow-up task from a meeting decision
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Task title |
| description | string | No | Task details |
| assignee | string | No | Assigned participant |
| dueDate | datetime | No | Due date |
| taskStatus | string | Yes | open, in-progress, completed, overdue |
| completedAt | datetime | No | Completion timestamp |

**Relations:**
- → Decision (many-to-one)
- → Meeting (many-to-one)

---

## AgendaItem
**Schema.org type:** `custom:AgendaItem`
**Purpose:** An item on a meeting agenda with type, time, and ordering
**Primary spec:** p2-agenda-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Agenda item title |
| itemType | string | Yes | informational, discussion, decision |
| orderNumber | integer | Yes | Position on the agenda |
| estimatedDuration | integer | No | Estimated minutes |
| actualDuration | integer | No | Actual minutes spent |
| description | string | No | Detailed description |
| isRecurring | boolean | No | Appears on every meeting |

**Relations:**
- → Meeting (many-to-one)
- → Motion (one-to-many)

---

## Amendment
**Schema.org type:** `custom:Amendment`
**Purpose:** A proposed change to an existing motion
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Amendment title |
| text | string | Yes | Amendment text (change description) |
| proposer | string | Yes | Name of proposer |
| lifecycle | string | Yes | submitted, debating, voting, adopted, rejected |
| submittedAt | datetime | Yes | Submission timestamp |

**Relations:**
- → Motion (many-to-one)

---

## Decision
**Schema.org type:** `custom:Decision`
**Purpose:** A formal decision resulting from a vote
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Decision title |
| text | string | Yes | Decision text |
| decisionDate | datetime | Yes | When the decision was made |
| outcome | string | Yes | adopted, rejected |
| isPublished | boolean | No | Published via ORI API |
| publishedAt | datetime | No | Publication timestamp |
| legalBasis | string | No | Legal article or regulation |

**Relations:**
- → Motion (many-to-one)
- → ActionItem (one-to-many)

---

## DigitalDocument
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Schema.org DigitalDocument — standard vocabulary for digitaldocument data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document name/title |
| documentType | string | Yes | Document type (contract, tender, report, etc.) |
| description | string | No | Document description |
| encodingFormat | string | No | MIME type (application/pdf, etc.) |
| contentSize | string | No | File size |

---

## GovernanceBody
**Schema.org type:** `schema:Organization`
**Purpose:** A governance body (council, board, committee, assembly)
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Body name |
| bodyType | string | Yes | legislative, association, corporate-board, operational, citizen-panel |
| domain | string | Yes | Governance domain preset |
| workflowTemplate | string | No | State machine workflow config |
| quorumRule | string | No | Quorum calculation method |
| votingDefault | string | No | Default voting method |
| termStart | datetime | No | Current term start |
| termEnd | datetime | No | Current term end |

**Relations:**
- → Meeting (one-to-many)
- → Participant (one-to-many)

---

## Meeting
**Schema.org type:** `schema:Event`
**Purpose:** A scheduled governance meeting with agenda, participants, and lifecycle
**Primary spec:** p2-meeting-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Meeting title |
| meetingType | string | Yes | Type: regular, extraordinary, committee, public hearing |
| scheduledDate | datetime | Yes | Start date and time |
| endDate | datetime | No | End date and time |
| location | string | No | Physical location or video link |
| meetingMode | string | Yes | in-person, digital, hybrid |
| lifecycle | string | Yes | State: draft, scheduled, opened, paused, adjourned, closed |
| quorumRequired | integer | No | Minimum participants for valid meeting |
| series | string | No | Meeting series identifier |

**Relations:**
- → GovernanceBody (many-to-one)
- → AgendaItem (one-to-many)

---

## Minutes
**Schema.org type:** `custom:Minutes`
**Purpose:** Official record of a meeting's proceedings
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Minutes title |
| lifecycle | string | Yes | draft, review, approved, signed, published |
| content | string | No | Full minutes text |
| approvedAt | datetime | No | Approval timestamp |
| signedBy | array | No | Digital signers (chair + secretary) |
| version | integer | No | Revision number |

**Relations:**
- → Meeting (one-to-one)

---

## MonetaryAmount
**Schema.org type:** `schema:MonetaryAmount`
**Purpose:** Schema.org MonetaryAmount — standard vocabulary for monetaryamount data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | number | Yes | Numeric value |
| currency | string | Yes | ISO 4217 currency code |

---

## Motion
**Schema.org type:** `custom:Motion`
**Purpose:** A formal proposal submitted for debate and voting
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Motion title |
| text | string | Yes | Full motion text |
| motionType | string | Yes | motion, amendment, order, procedural |
| proposer | string | Yes | Name of proposer |
| coSigners | array | No | List of co-signers |
| lifecycle | string | Yes | submitted, debating, voting, adopted, rejected, withdrawn |
| submittedAt | datetime | Yes | Submission timestamp |

**Relations:**
- → AgendaItem (many-to-one)
- → Amendment (one-to-many)
- → VotingRound (one-to-many)

---

## Offer
**Schema.org type:** `schema:Offer`
**Purpose:** Schema.org Offer — standard vocabulary for offer data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Offer/quote name |
| price | number | Yes | Offered price |
| priceCurrency | string | Yes | Currency |
| validFrom | datetime | No | Offer valid from |
| validThrough | datetime | No | Offer valid until |
| availability | string | No | Availability status |

---

## Order
**Schema.org type:** `schema:Order`
**Purpose:** Schema.org Order — standard vocabulary for order data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | datetime | Yes | Date of order |
| orderStatus | string | Yes | Order status |
| totalPrice | number | Yes | Total order amount |
| currency | string | Yes | ISO 4217 currency code |
| deliveryDate | datetime | No | Expected delivery date |
| paymentTerms | string | No | Payment terms (e.g., NET30) |

---

## Participant
**Schema.org type:** `schema:Person`
**Purpose:** A member or attendee of a governance body
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| displayName | string | Yes | Display name |
| role | string | Yes | chair, vice-chair, secretary, member, observer, guest |
| party | string | No | Political party or faction |
| email | string | No | Contact email |
| joinedAt | datetime | No | When they joined the body |
| leftAt | datetime | No | When they left (null = active) |
| votingWeight | number | No | Vote weight (default 1) |

**Relations:**
- → GovernanceBody (many-to-one)

---

## Product
**Schema.org type:** `schema:Product`
**Purpose:** Schema.org Product — standard vocabulary for product data

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
**Purpose:** Schema.org Report — standard vocabulary for report data

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type (financial, compliance, etc.) |
| period | string | No | Reporting period |
| generatedAt | datetime | No | When the report was generated |

---

## Vote
**Schema.org type:** `custom:Vote`
**Purpose:** An individual vote cast in a voting round
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | string | Yes | for, against, abstain (or rank for ranked-choice) |
| weight | number | No | Vote weight (for weighted voting) |
| isProxy | boolean | No | Cast via proxy delegation |
| castAt | datetime | Yes | When the vote was cast |

**Relations:**
- → VotingRound (many-to-one)
- → Participant (many-to-one)

---

## VotingRound
**Schema.org type:** `custom:VotingRound`
**Purpose:** A voting session on a motion or amendment
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| votingMethod | string | Yes | for-against-abstain, ranked-choice, weighted, show-of-hands |
| isSecret | boolean | Yes | Secret ballot |
| openedAt | datetime | No | When voting opened |
| closedAt | datetime | No | When voting closed |
| quorumMet | boolean | No | Was quorum met |
| result | string | No | adopted, rejected, tied, invalid |
| votesFor | integer | No | Count of votes for |
| votesAgainst | integer | No | Count of votes against |
| votesAbstain | integer | No | Count of abstentions |

**Relations:**
- → Motion (many-to-one)
- → Vote (one-to-many)

---
