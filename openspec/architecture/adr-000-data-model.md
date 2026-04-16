# ADR-000: Data Model — Decidesk

**Status:** accepted
**Standard:** Popolo (popoloproject.com) + ORI extensions (VNG Open Raadsinformatie)
**Storage:** CalDAV-first for meetings/tasks, OpenRegister for governance entities
**Entities:** 17 active (2 deprecated)

## Context

The data model follows the **Popolo international standard** as its primary schema, with
**ORI (Open Raadsinformatie)** extensions for Dutch municipal governance concepts.

Storage is split across two layers:
- **CalDAV (Nextcloud Calendar/Tasks):** Meetings as VEVENT, ActionItems as VTODO — native
  Nextcloud integration, no sync layer needed. Governance metadata stored as RFC 5545
  X-DECIDESK-* extended properties.
- **OpenRegister:** All governance-specific entities (motions, votes, amendments, minutes,
  people, organizations) that have no CalDAV equivalent. Thin wrapper objects reference
  CalDAV UIDs for relational queries.

OpenRegister built-in fields (NOT listed below, always available):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

## CalDAV-Primary Entities

### Meeting
**Popolo/ORI:** `meeting:Meeting` (subclass of `schema:Event`)
**Storage:** CalDAV VEVENT with X-DECIDESK-* properties + OpenRegister wrapper
_A scheduled governance meeting with agenda, participants, and lifecycle_
**Primary spec:** p2-meeting-management

| Property | Type | Required | CalDAV Mapping | Description |
|----------|------|----------|----------------|-------------|
| title | string | Yes | SUMMARY | Meeting title |
| meetingType | string | Yes | X-DECIDESK-MEETING-TYPE | regular, extraordinary, committee, public hearing |
| scheduledDate | datetime | Yes | DTSTART | Start date and time |
| endDate | datetime | No | DTEND | End date and time |
| location | string | No | LOCATION | Physical location or video link |
| meetingMode | string | Yes | X-DECIDESK-MEETING-MODE | in-person, digital, hybrid |
| lifecycle | string | Yes | X-DECIDESK-LIFECYCLE | draft, scheduled, opened, paused, adjourned, closed |
| quorumRequired | integer | No | X-DECIDESK-QUORUM-REQUIRED | Minimum participants for valid meeting |
| series | string | No | X-DECIDESK-SERIES | Meeting series identifier |
| description | string | No | DESCRIPTION | Meeting description/notes |

**CalDAV attendees:** Participants mapped to ATTENDEE properties with ROLE parameter.
**OpenRegister wrapper:** Stores CalDAV UID reference for relational queries.

**Relations:**
- → GovernanceBody (many-to-one, via X-DECIDESK-BODY-UID)
- → AgendaItem (one-to-many, via OpenRegister)

### ActionItem
**Popolo/ORI:** Custom (not in Popolo)
**Storage:** CalDAV VTODO in Nextcloud Tasks
_A follow-up task from an adopted motion_
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | CalDAV Mapping | Description |
|----------|------|----------|----------------|-------------|
| title | string | Yes | SUMMARY | Task title |
| description | string | No | DESCRIPTION | Task details |
| assignee | string | No | ATTENDEE | Assigned participant |
| dueDate | datetime | No | DUE | Due date |
| taskStatus | string | Yes | STATUS | NEEDS-ACTION, IN-PROCESS, COMPLETED |
| completedAt | datetime | No | COMPLETED | Completion timestamp |

**Relations:**
- → Motion (many-to-one, via X-DECIDESK-MOTION-UID)
- → Meeting (many-to-one, via X-DECIDESK-MEETING-UID)

## OpenRegister Entities — Popolo Core

### Person
**Popolo:** `foaf:Person`
_An individual who participates in governance_
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| name | string | Yes | name | Full display name |
| familyName | string | No | family_name | Family name |
| givenName | string | No | given_name | Given name |
| gender | string | No | gender | Gender |
| birthDate | date | No | birth_date | Date of birth |
| image | string | No | image | URL to photo |
| biography | string | No | biography | Short bio |
| email | string | No | email | Primary email (convenience) |

**Relations:**
- → Membership (one-to-many)
- → ContactDetail (one-to-many)
- → Vote (one-to-many)
- → Speech (one-to-many)

### GovernanceBody
**Popolo:** `org:Organization`
**ORI:** `meeting:Committee` (subclass for committees)
_A governance body (council, board, committee, assembly). Managed by OpenRegister organizations._
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| name | string | Yes | name | Body name |
| bodyType | string | Yes | classification | legislative, association, corporate-board, operational, citizen-panel |
| domain | string | Yes | — | Governance domain preset |
| workflowTemplate | string | No | — | State machine workflow config |
| quorumRule | string | No | — | Quorum calculation method |
| votingDefault | string | No | — | Default voting method |
| termStart | datetime | No | founding_date | Current term start |
| termEnd | datetime | No | dissolution_date | Current term end |

**Relations:**
- → Meeting (one-to-many)
- → Membership (one-to-many)
- → Post (one-to-many)
- → Area (many-to-one)

### Membership
**Popolo:** `org:Membership`
_Relationship between a Person and a GovernanceBody, with role and time bounds_
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| role | string | Yes | role | chair, vice-chair, secretary, member, observer, guest |
| label | string | No | label | Descriptive label |
| startDate | datetime | No | start_date | When the membership started |
| endDate | datetime | No | end_date | When the membership ended (null = active) |
| votingWeight | number | No | — | Vote weight (default 1) |
| party | string | No | on_behalf_of | Political party or faction |

**Relations:**
- → Person (many-to-one)
- → GovernanceBody (many-to-one)
- → Post (many-to-one)

### Post
**Popolo:** `org:Post`
_A formal position within a governance body_
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| label | string | Yes | label | Position title |
| role | string | No | role | chair, vice-chair, secretary, member |
| startDate | datetime | No | start_date | When the post was created |
| endDate | datetime | No | end_date | When the post was abolished |

**Relations:**
- → GovernanceBody (many-to-one)

### ContactDetail
**Popolo:** `popolo:ContactDetail`
_A means of contacting a person or organization_
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| type | string | Yes | type | email, phone, fax, cell, address, url |
| value | string | Yes | value | Contact value |
| label | string | No | label | Human-readable label |
| note | string | No | note | Usage note |
| validFrom | datetime | No | valid_from | Start of validity |
| validUntil | datetime | No | valid_until | End of validity |

**Relations:**
- → Person (many-to-one)
- → GovernanceBody (many-to-one)

### Area
**Popolo:** `popolo:Area`
_A geographic or jurisdictional area_
**Primary spec:** p3-governance-bodies

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| name | string | Yes | name | Area name |
| identifier | string | No | identifier | Official code (e.g. CBS gemeentecode) |
| classification | string | No | classification | municipality, province, waterboard, national |

**Relations:**
- → GovernanceBody (one-to-many)

## OpenRegister Entities — Motions & Voting

### Motion
**Popolo:** `opengov:Motion`
_A formal proposal submitted for debate and voting. When adopted, includes decision outcome.
No separate Decision entity — follows Popolo where the result lives on the Motion._
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| title | string | Yes | name | Motion title |
| text | string | Yes | text | Full motion text |
| motionType | string | Yes | classification | motion, amendment, order, procedural |
| proposer | string | Yes | creator | Name of proposer |
| coSigners | array | No | — | List of co-signers |
| lifecycle | string | Yes | — | submitted, debating, voting, adopted, rejected, withdrawn |
| submittedAt | datetime | Yes | proposal_date | Submission timestamp |
| requirement | string | No | requirement | Requirement for adoption (e.g. simple majority) |
| decisionText | string | No | — | Formal decision text when adopted |
| decisionDate | datetime | No | — | When the decision was formally made |
| isPublished | boolean | No | — | Published via ORI API |
| publishedAt | datetime | No | — | ORI publication timestamp |
| legalBasis | string | No | — | Legal article or regulation |

**Relations:**
- → AgendaItem (many-to-one)
- → Amendment (one-to-many)
- → VotingRound (one-to-many)
- → ActionItem (one-to-many)

### Amendment
**Popolo/ORI:** `meeting:Amendment` (subclass of `opengov:Motion`)
_A proposed change to an existing motion_
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| title | string | Yes | name | Amendment title |
| text | string | Yes | text | Amendment text (change description) |
| proposer | string | Yes | creator | Name of proposer |
| lifecycle | string | Yes | — | submitted, debating, voting, adopted, rejected |
| submittedAt | datetime | Yes | proposal_date | Submission timestamp |

**Relations:**
- → Motion (many-to-one, ORI: amends)

### VotingRound
**Popolo:** `opengov:VoteEvent`
_A voting session on a motion or amendment_
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| votingMethod | string | Yes | classification | for-against-abstain, ranked-choice, weighted, show-of-hands |
| isSecret | boolean | Yes | — | Secret ballot |
| openedAt | datetime | No | start_date | When voting opened |
| closedAt | datetime | No | end_date | When voting closed |
| quorumMet | boolean | No | — | Was quorum met |
| result | string | No | result | adopted, rejected, tied, invalid (Popolo: pass/fail) |
| votesFor | integer | No | — | Count of votes for (Popolo: Count with YesCount) |
| votesAgainst | integer | No | — | Count of votes against (Popolo: Count with NoCount) |
| votesAbstain | integer | No | — | Count of abstentions (Popolo: Count with AbstainCount) |

**Relations:**
- → Motion (many-to-one, Popolo: motion)
- → Vote (one-to-many, Popolo: votes)

### Vote
**Popolo:** `opengov:Vote`
_An individual vote cast in a voting round_
**Primary spec:** p2-motion-and-voting

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| value | string | Yes | option | for, against, abstain (Popolo: yes/no/abstain) |
| weight | number | No | weight | Vote weight (for weighted voting) |
| isProxy | boolean | No | — | Cast via proxy delegation |
| castAt | datetime | Yes | — | When the vote was cast |

**Relations:**
- → VotingRound (many-to-one, Popolo: vote_event)
- → Person (many-to-one, Popolo: voter)

## OpenRegister Entities — Records & Agenda

### AgendaItem
**ORI:** `meeting:AgendaItem` (subclass of `schema:Event`)
_An item on a meeting agenda with type, time, and ordering_
**Primary spec:** p2-agenda-management

| Property | Type | Required | ORI Field | Description |
|----------|------|----------|-----------|-------------|
| title | string | Yes | name | Agenda item title |
| itemType | string | Yes | — | informational, discussion, decision |
| orderNumber | integer | Yes | position | Position on the agenda |
| estimatedDuration | integer | No | — | Estimated minutes |
| actualDuration | integer | No | — | Actual minutes spent |
| description | string | No | description | Detailed description |
| isRecurring | boolean | No | — | Appears on every meeting |

**Relations:**
- → Meeting (many-to-one, via OpenRegister wrapper CalDAV UID)
- → Motion (one-to-many)
- → Speech (one-to-many)

### Minutes
**ORI:** `meeting:Report` (subclass of `schema:Event` + `schema:CreativeWork`)
_Official record of a meeting's proceedings_
**Primary spec:** p2-minutes-and-decisions

| Property | Type | Required | ORI Field | Description |
|----------|------|----------|-----------|-------------|
| title | string | Yes | — | Minutes title |
| lifecycle | string | Yes | — | draft, review, approved, signed, published |
| content | string | No | — | Full minutes text |
| approvedAt | datetime | No | — | Approval timestamp |
| signedBy | array | No | — | Digital signers (chair + secretary) |
| version | integer | No | — | Revision number |

**Relations:**
- → Meeting (one-to-one, via OpenRegister wrapper CalDAV UID)

### Speech
**Popolo:** `opengov:Speech`
**ORI:** Subtypes: SpeechQuestion, SpeechAnswer, SpeechNarrative, SpeechSummary
_A speech or statement made during a meeting (later phase)_

| Property | Type | Required | Popolo Field | Description |
|----------|------|----------|--------------|-------------|
| text | string | Yes | text | Transcript text |
| role | string | No | role | Speaker role: chair, member, guest |
| startDate | datetime | No | start_date | When the speech started |
| endDate | datetime | No | end_date | When the speech ended |
| audio | string | No | audio | URL to audio recording |
| video | string | No | video | URL to video recording |

**Relations:**
- → Meeting (many-to-one, Popolo: event)
- → AgendaItem (many-to-one)
- → Person (many-to-one, Popolo: creator)

## Deprecated Entities

### ~~Decision~~ (merged into Motion)
Decision is now the outcome of a Motion. When a motion is adopted, the `decisionText`,
`decisionDate`, `isPublished`, `publishedAt`, and `legalBasis` fields on Motion capture
the decision. This follows the Popolo standard which has no separate Decision class.

### ~~Participant~~ (split into Person + Membership + Post)
Participant has been decomposed into three Popolo-aligned entities: Person (identity),
Membership (organization relationship with role and time bounds), and Post (formal positions).

## Popolo Coverage

| Popolo Class | DecideDesk Entity | Notes |
|---|---|---|
| Person | Person | Direct |
| Organization | GovernanceBody | + bodyType, domain fields |
| Membership | Membership | Direct |
| Post | Post | Direct |
| ContactDetail | ContactDetail | Direct |
| Motion | Motion | + decision outcome fields |
| VoteEvent | VotingRound | + counts flattened |
| Vote | Vote | Direct |
| Count | (fields on VotingRound) | Flattened into votesFor/Against/Abstain |
| Event | Meeting (CalDAV VEVENT) | CalDAV-primary storage |
| Area | Area | Direct |
| Speech | Speech | Later phase |

## ORI Extensions

| ORI Class | DecideDesk Entity | Notes |
|---|---|---|
| AgendaItem | AgendaItem | Direct |
| Amendment | Amendment | Subclass of Motion |
| Report | Minutes | Direct |
| Committee | GovernanceBody (bodyType) | Flat field, not subclass |
