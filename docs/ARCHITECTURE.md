# Decidesk — Architecture & Data Model

## 1. Overview

Decidesk is a universal decision-making platform for Nextcloud, built as a thin client on OpenRegister. It manages meetings, agendas, motions, amendments, voting, minutes, and decision tracking with configurable workflows. Decidesk covers five governance domains: legislative bodies (municipal councils, provincial states), associations (ALV, boards), corporate governance (AGM, supervisory boards), corporate operations (management teams, steering committees), and citizen participation (citizens' assemblies, referenda).

### Architecture Pattern

```
+------------------------------------------------------------+
|  Decidesk Frontend (Vue 2 + Pinia)                         |
|  - Organization & body management                          |
|  - Meeting calendar & agenda builder                       |
|  - Decision lifecycle & state machine visualization        |
|  - Motion & amendment editor (Akoma Ntoso-inspired)        |
|  - Voting & ballot collection (configurable methods)       |
|  - Resolution register                                     |
|  - Minutes editor & approval workflow                      |
|  - Process template designer (Symfony Workflow YAML)       |
|  - Admin settings                                          |
+---------------------------+--------------------------------+
                            | REST API calls
+---------------------------v--------------------------------+
|  OpenRegister API                                          |
|  /api/objects/{register}/{schema}/{id}                     |
|  - CRUD operations                                         |
|  - Search, pagination, filtering                           |
|  - Symfony Workflow state transitions                       |
+---------------------------+--------------------------------+
                            |
+---------------------------v--------------------------------+
|  OpenRegister Storage (PostgreSQL)                          |
|  - JSON object storage                                     |
|  - Schema validation                                       |
+------------------------------------------------------------+
```

Decidesk owns **no database tables**. All data is stored as OpenRegister objects, defined by schemas in a dedicated register. State machine logic is driven by Symfony Workflow definitions stored as ProcessTemplate objects.

## 2. Standards Research

Before defining our data model, we evaluated standards across four categories: semantic web, legislative/parliamentary, Dutch legal, and workflow/process.

### 2.1 Standards Evaluated

| Standard | Type | Coverage | Maturity | Relevance |
|----------|------|----------|----------|-----------|
| **Schema.org Event/Action/VoteAction** | International | Event, Organization, VoteAction, CreativeWork | Very mature | **HIGH** — primary vocabulary for meetings, voting, documents |
| **Akoma Ntoso / LegalDocML** | OASIS | Debate, motion, amendment, resolution, vote, speech | Mature (OASIS v1.0) | **HIGH** — defines document structure for legislative acts |
| **BPMN 2.0** | ISO | Process modeling, gateways, events, pools/lanes | Very mature (ISO 19510) | **MEDIUM** — inspiration for visualization, but Symfony Workflow replaces full engine |
| **DMN 1.x** | OMG | Decision tables, hit policies, FEEL expressions | Mature | **MEDIUM** — inspiration for configurable voting rules (quorum, majority) |
| **Awb / Gemeentewet / BW Boek 2** | Dutch law | Besluit requirements, quorum, voting, secret ballot | Binding law | **HIGH** — non-negotiable legal requirements for valid decisions |
| **OpenRaadsinformatie (ORI)** | Dutch open data | Council events, agenda items, motions, votes, persons | Active (265/345 municipalities) | **HIGH** — API compatibility for Dutch council systems |
| **ELI** | European | Legislation identification via HTTP URIs, metadata ontology | EU standard | **MEDIUM** — cross-reference European legislation identifiers |
| **MDTO** | Dutch gov | Metadata for durable accessible government information | Forum Standaardisatie | **MEDIUM** — archival metadata for decisions and minutes |
| **Robert's Rules of Order** | International | Parliamentary procedure: motions, amendments, debate, voting order | 12th edition (2020) | **LOW** — UX patterns for procedural flow, not a technical standard |
| **Symfony Workflow Component** | PHP library | State machine, guards, parallel states, event-driven audit | Stable (Symfony 7.x) | **HIGH** — exact implementation technology for decision lifecycle |

### 2.2 Design Principle: International First

> **Data storage uses international standards (Schema.org, Akoma Ntoso concepts). Dutch legal requirements are enforcement rules. Dutch open data standards (ORI) are an API mapping layer.**

This means:
- Objects in OpenRegister are modeled after **Schema.org** vocabulary for meetings, organizations, and actions
- Document structure concepts follow **Akoma Ntoso** conventions for motions, amendments, and resolutions
- Dutch legal requirements (Awb, Gemeentewet, BW Boek 2) are encoded as **configurable rules** in ProcessTemplate, not hardcoded into entities
- When exposing ORI-compatible data, we **map** our international objects to Popolo/ORI field names
- This makes Decidesk usable for any governance domain (corporate boards, associations, international bodies) while remaining interoperable with Dutch government systems

### 2.3 Key Findings

1. **Schema.org** provides the primary vocabulary: `Event` for meetings, `Organization` for bodies, `VoteAction` for voting, `CreativeWork` for minutes and resolutions. Every entity carries a `schema:` type annotation for linked data compatibility.

2. **Akoma Ntoso** (OASIS LegalDocML) defines the canonical structure for legislative and parliamentary documents. We adopt its conceptual model for motions (`motion`), amendments (`amendment`), and resolutions (`resolution`), but store as JSON in OpenRegister rather than XML. The hierarchical document structure (preface, preamble, body, conclusions) informs our text fields.

3. **Dutch legal requirements** are non-negotiable system constraints:
   - **Awb Art. 1:3**: Decisions (besluiten) must be in writing with motivation
   - **Gemeentewet Art. 20/29/30**: Quorum >50%, absolute majority, tie-breaking, mandatory secret ballot for appointments
   - **BW Boek 2 Art. 2:38/230/238**: Configurable majority/quorum per statutes, written procedure requires unanimous consent to the method
   - **Wet digitaal vergaderen**: Permanent law enabling digital meetings for decentrale overheden (excludes secret meetings)
   - These are encoded as configurable rules in ProcessTemplate, not hardcoded — because each organization type has different requirements

4. **OpenRaadsinformatie (ORI)** connects 265 of 345 Dutch municipalities. The Popolo-based data model (Event, AgendaItem, Motion, VoteEvent, Person) maps cleanly to our Schema.org entities. We provide ORI export capability for legislative domain users.

5. **Symfony Workflow Component** is the implementation choice for decision state machines. It provides: YAML-based configuration (stored as ProcessTemplate objects), guard events for enforcing quorum/majority rules before transitions, audit trail via event listeners, and parallel states (a decision can be simultaneously "in committee A" and "in committee B"). This avoids the overhead of full BPMN engines (Camunda, Flowable) which are Java-based.

6. **DMN-inspired decision tables** provide the pattern for configurable voting rules. Rather than implementing a full DMN engine, we use PHP decision tables that evaluate: quorum percentage, majority type (simple/absolute/qualified/unanimous), required roles per transition, time limits, and secret ballot requirements.

7. **MDTO** (Metagegevens voor Duurzaam Toegankelijke Overheidsinformatie) provides the archival metadata standard. Decisions, resolutions, and minutes must carry MDTO-compliant metadata for transfer to permanent archives under the Archiefwet 2021 (10-year transfer period).

8. **ELI** (European Legislation Identifier) provides the URI pattern for cross-referencing legislation. Resolutions that reference or implement EU directives can carry ELI identifiers for linked data interoperability.

9. **Market intelligence** confirms strong demand: the board portal market is growing to $5-7B by 2030 (11-20% CAGR). Dutch RIS market leader Notubiz (50%+ share) is criticized as a "maze" by NOS Nieuwsuur. No competitor covers all 5 governance domains — council tools (GO, Notubiz) lack board governance; board portals (Diligent at $48-155K/year, OnBoard) lack legislative features; meeting tools (Fellow) lack formal voting. No self-hosted privacy-first alternative exists.

## 3. Data Model Decisions

### 3.1 Standards Hierarchy

We adopt a **layered standards approach**:

| Layer | Standard | Purpose |
|-------|----------|---------|
| **Primary (storage)** | Schema.org + Akoma Ntoso concepts | International data model for meetings, decisions, documents |
| **Semantic** | Schema.org JSON-LD | Type annotations for linked data |
| **Legal enforcement** | Awb, Gemeentewet, BW Boek 2 | Configurable rules per organization type |
| **API mapping** | OpenRaadsinformatie (Popolo) | Dutch open data interoperability for legislative domain |
| **Archival** | MDTO + Archiefwet 2021 | Long-term preservation metadata |

### 3.2 Entity Definitions

#### Organization

The top-level governance entity. An organization defines the legal context in which decision-making bodies operate.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Organization` | International standard for organizational entities |
| **Domain typing** | Five governance domains from intelligence research | Covers all market segments identified in competitive analysis |
| **Rules as config** | JSON configuration object, not hardcoded | BW Boek 2 allows custom statutes; Gemeentewet has fixed rules — both via config |

**Core properties**:

| Property | Type | Schema.org | Akoma Ntoso | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `name` | string | `schema:name` | `TLCOrganization/@showAs` | Yes | -- |
| `type` | enum | `schema:additionalType` | -- | Yes | -- |
| `description` | string | `schema:description` | -- | No | -- |
| `legalName` | string | `schema:legalName` | -- | No | -- |
| `identifier` | string | `schema:identifier` | `TLCOrganization/@eId` | No | -- |
| `kvkNumber` | string | `schema:taxID` | -- | No | -- |
| `address` | object | `schema:address` | -- | No | -- |
| `website` | string | `schema:url` | -- | No | -- |
| `logo` | string (file ref) | `schema:logo` | -- | No | -- |
| `defaultProcessTemplate` | reference | -- | -- | No | -- |
| `rules` | object (JSON) | -- | -- | No | `{}` |
| `archivalPolicy` | object | -- | -- | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Organization type values** (from intelligence research — 5 domains, 92 stakeholder roles):

| Type | Dutch | Domain | Legal Framework |
|------|-------|--------|-----------------|
| `legislative` | Volksvertegenwoordiging | Legislative & Democratic Bodies | Gemeentewet, Provinciewet, Waterschapswet |
| `association` | Vereniging | Associations & NGOs | BW Boek 2 Title 2, WBTR |
| `corporate-governance` | Vennootschapsbestuur | Corporate Governance | BW Boek 2 Title 4-6, Corporate Governance Code |
| `corporate-operations` | Bedrijfsorganisatie | Corporate & Organisational Operations | Internal regulations, CAO |
| `citizen-participation` | Burgerparticipatie | Citizen & Public Participation | Gemeentewet Art. 150, Omgevingswet |

#### Body

A specific decision-making body within an organization. A municipality has a council, committees, and college; a company has an AGM, supervisory board, and management team.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Organization` (subOrganization) | Bodies are sub-organizations of the parent |
| **Hierarchy** | Bodies can have parent bodies (committee under council) | Gemeentewet committees; corporate sub-committees |
| **Configurable rules** | Quorum, voting method, majority type per body | BW Boek 2 allows statutes to specify per body; Gemeentewet has fixed rules per body type |

**Core properties**:

| Property | Type | Schema.org | ORI Mapping | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `name` | string | `schema:name` | `Organization.name` | Yes | -- |
| `type` | enum | `schema:additionalType` | `Organization.classification` | Yes | -- |
| `organization` | reference | `schema:parentOrganization` | -- | Yes | -- |
| `parentBody` | reference | `schema:subOrganization` (inverse) | `Organization.parent_id` | No | -- |
| `description` | string | `schema:description` | `Organization.description` | No | -- |
| `quorumRule` | object | -- | -- | No | `{"type": "percentage", "value": 50}` |
| `votingMethod` | enum | -- | -- | No | `show_of_hands` |
| `defaultMajority` | enum | -- | -- | No | `simple` |
| `meetingFrequency` | string (RRULE) | `schema:eventSchedule` | -- | No | -- |
| `members` | array (user UIDs) | `schema:member` | `Membership.person_id` | No | `[]` |
| `chair` | string (user UID) | `schema:employee` | -- | No | -- |
| `secretary` | string (user UID) | -- | -- | No | -- |
| `processTemplate` | reference | -- | -- | No | -- |
| `isActive` | boolean | -- | -- | No | `true` |
| `startDate` | date | `schema:foundingDate` | `Organization.start_date` | No | -- |
| `endDate` | date | `schema:dissolutionDate` | `Organization.end_date` | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Body type values**:

| Type | Dutch | Typical Organization | Legal Basis |
|------|-------|---------------------|-------------|
| `council` | Raad | Legislative | Gemeentewet Art. 7 |
| `committee` | Commissie | Legislative, Association | Gemeentewet Art. 82-84 |
| `executive` | College / Dagelijks Bestuur | Legislative | Gemeentewet Art. 34 |
| `board` | Bestuur | Association, Corporate | BW 2:37, 2:291 |
| `general_assembly` | ALV / AVA | Association, Corporate | BW 2:38, 2:217 |
| `supervisory_board` | Raad van Commissarissen | Corporate | BW 2:250, Corporate Governance Code |
| `management_team` | Managementteam | Corporate Operations | Internal regulations |
| `steering_committee` | Stuurgroep | Corporate Operations | Internal regulations |
| `citizens_assembly` | Burgerberaad | Citizen Participation | Gemeentewet Art. 150 |
| `advisory_board` | Adviesraad | All domains | Various |

**Voting method values**:

| Method | Dutch | Description | Legal Reference |
|--------|-------|-------------|-----------------|
| `show_of_hands` | Handopsteking | Visual count, default for most bodies | Gemeentewet Art. 32 |
| `roll_call` | Hoofdelijke stemming | Named vote, recorded per member | Gemeentewet Art. 32 lid 2 |
| `secret_ballot` | Geheime stemming | Mandatory for appointments | Gemeentewet Art. 31 |
| `electronic` | Elektronisch | Digital voting with audit trail | Wet digitaal vergaderen |
| `written_procedure` | Schriftelijke ronde | Decision outside meeting | BW 2:238 |
| `acclamation` | Acclamatie | Unanimous consent without formal vote | Parliamentary convention |
| `weighted` | Gewogen stemming | Votes weighted by shares/seats | BW 2:228 |

**Majority type values**:

| Type | Dutch | Threshold | Use Case |
|------|-------|-----------|----------|
| `simple` | Gewone meerderheid | >50% of votes cast | Default for most decisions |
| `absolute` | Volstrekte meerderheid | >50% of all members | Gemeentewet Art. 30 |
| `qualified` | Gekwalificeerde meerderheid | 2/3 of votes cast | Statute amendments (BW 2:42) |
| `unanimous` | Unanimiteit | 100% of votes cast | Written procedure consent (BW 2:238) |
| `relative` | Relatieve meerderheid | Most votes wins | Elections with multiple candidates |

#### Meeting

A scheduled gathering of a body for deliberation and decision-making.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Event` | International standard for scheduled events |
| **ORI mapping** | `Event` (Popolo) | Dutch open data compatibility |
| **Calendar sync** | CalDAV event via `OCP\Calendar\IManager` | Meetings appear in Nextcloud Calendar |
| **Status machine** | Symfony Workflow: draft -> convened -> in_progress -> adjourned -> completed -> minutes_approved | Formal lifecycle required by law |

**Core properties**:

| Property | Type | Schema.org | ORI Mapping | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `name` | string | `schema:name` | `Event.name` | Yes | -- |
| `body` | reference | `schema:organizer` | `Event.organization_id` | Yes | -- |
| `status` | enum | `schema:eventStatus` | `Event.status` | Yes | `draft` |
| `scheduledStart` | datetime | `schema:startDate` | `Event.start_date` | No | -- |
| `scheduledEnd` | datetime | `schema:endDate` | `Event.end_date` | No | -- |
| `actualStart` | datetime | -- | -- | No | -- |
| `actualEnd` | datetime | -- | -- | No | -- |
| `location` | string | `schema:location` | `Event.location` | No | -- |
| `isDigital` | boolean | -- | -- | No | `false` |
| `digitalMeetingUrl` | string | `schema:url` | -- | No | -- |
| `chair` | string (user UID) | -- | `Event.chair_id` | No | -- |
| `secretary` | string (user UID) | -- | -- | No | -- |
| `attendees` | array (user UIDs) | `schema:attendee` | `EventAttendee` | No | `[]` |
| `absentees` | array (user UIDs) | -- | -- | No | `[]` |
| `quorumPresent` | integer | -- | -- | No | -- |
| `quorumMet` | boolean | -- | -- | No | -- |
| `convocationDate` | datetime | -- | -- | No | -- |
| `convocationDocuments` | array (file refs) | -- | -- | No | `[]` |
| `calendarEventUid` | string | -- | -- | No | -- |
| `talkToken` | string | -- | -- | No | -- |
| `number` | integer | -- | -- | No | -- |
| `series` | string | -- | -- | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | `Event.created_at` | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | `Event.updated_at` | Auto | -- |

**Meeting status values**:

| Status | Dutch | Description | Legal Significance |
|--------|-------|-------------|--------------------|
| `draft` | Concept | Meeting being planned, not yet announced | -- |
| `convened` | Bijeengeroepen | Convocation sent, agenda published | Gemeentewet Art. 19: min 2 days notice |
| `in_progress` | Lopend | Meeting is active | Quorum must be verified (Gemeentewet Art. 20) |
| `adjourned` | Geschorst | Temporarily suspended | Chair may adjourn (Gemeentewet Art. 26) |
| `completed` | Afgelopen | Meeting ended, minutes pending | -- |
| `minutes_approved` | Notulen vastgesteld | Minutes approved by body | Official record complete |
| `cancelled` | Geannuleerd | Meeting cancelled before occurrence | -- |

#### AgendaItem

An item on a meeting's agenda. Agenda items structure the meeting flow and can reference decisions, motions, or informational topics.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Event` (part of meeting) | Sub-event within parent meeting |
| **ORI mapping** | `AgendaItem` (Popolo) | Dutch open data compatibility |
| **Drag-and-drop ordering** | `order` integer field | Industry standard for agenda management |

**Core properties**:

| Property | Type | Schema.org | ORI Mapping | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `meeting` | reference | `schema:superEvent` | `AgendaItem.event_id` | Yes | -- |
| `title` | string | `schema:name` | `AgendaItem.name` | Yes | -- |
| `description` | string | `schema:description` | `AgendaItem.description` | No | -- |
| `order` | integer | `schema:position` | `AgendaItem.position` | Yes | 0 |
| `duration` | integer (minutes) | `schema:duration` | -- | No | -- |
| `type` | enum | `schema:additionalType` | -- | Yes | `discussion` |
| `status` | enum | -- | -- | Yes | `pending` |
| `presenter` | string (user UID) | `schema:performer` | -- | No | -- |
| `decision` | reference | -- | -- | No | -- |
| `documents` | array (file refs) | `schema:associatedMedia` | `AgendaItem.media_urls` | No | `[]` |
| `parentItem` | reference | -- | `AgendaItem.parent_id` | No | -- |
| `speakingTime` | integer (minutes) | -- | -- | No | -- |
| `notes` | string | -- | -- | No | -- |
| `outcome` | string | -- | -- | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Agenda item type values**:

| Type | Dutch | Description |
|------|-------|-------------|
| `opening` | Opening | Meeting opening, attendance, quorum check |
| `approval_minutes` | Vaststelling notulen | Approval of previous meeting's minutes |
| `approval_agenda` | Vaststelling agenda | Approval of current agenda |
| `information` | Informatie / Mededelingen | Informational item, no decision required |
| `discussion` | Bespreking | Discussion item, may lead to decision |
| `decision` | Besluit | Decision item with linked Decision entity |
| `question_hour` | Vragenuur | Question time (legislative bodies) |
| `interpellation` | Interpellatie | Formal questioning of executive (legislative) |
| `round_table` | Rondvraag | Closing round of questions |
| `closing` | Sluiting | Meeting closing |

**Agenda item status values**:

| Status | Dutch | Description |
|--------|-------|-------------|
| `pending` | Gepland | Not yet discussed |
| `active` | In behandeling | Currently being discussed |
| `completed` | Afgehandeld | Discussion completed |
| `deferred` | Uitgesteld | Deferred to future meeting |
| `withdrawn` | Ingetrokken | Withdrawn from agenda |

#### Decision

The core entity — a formal decision moving through a configurable state machine. Decisions can originate from any governance domain and follow domain-specific workflows.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Action` + `schema:VoteAction` | Action with voting outcome |
| **Akoma Ntoso** | `bill` / `decision` / `act` | Legislative document lifecycle |
| **State machine** | Symfony Workflow (configurable per ProcessTemplate) | Different domains have different approval chains |
| **Awb compliance** | Written form + motivation fields | Awb Art. 1:3 / 3:46 requirements |

**Core properties**:

| Property | Type | Schema.org | Akoma Ntoso | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `title` | string | `schema:name` | `preface/docTitle` | Yes | -- |
| `description` | string | `schema:description` | `preamble` | No | -- |
| `body` | reference | `schema:agent` | -- | Yes | -- |
| `status` | enum | `schema:actionStatus` | `lifecycle/@source` | Yes | `draft` |
| `category` | string | `schema:category` | -- | No | -- |
| `proposer` | string (user UID) | `schema:creator` | `preface/docProponent` | No | -- |
| `coProposers` | array (user UIDs) | -- | -- | No | `[]` |
| `motivation` | string (rich text) | -- | `preamble/recitals` | No | -- |
| `legalBasis` | string | -- | `citations` | No | -- |
| `requiredMajority` | enum | -- | -- | No | `simple` |
| `result` | enum | -- | -- | No | -- |
| `dossierFolder` | string (file ref) | -- | -- | No | -- |
| `processTemplate` | reference | -- | -- | No | -- |
| `currentWorkflowPlace` | string | -- | -- | No | `draft` |
| `workflowHistory` | array (objects) | -- | -- | No | `[]` |
| `relatedDecisions` | array (references) | `schema:isRelatedTo` | `references` | No | `[]` |
| `dueDate` | date | `schema:deadline` | -- | No | -- |
| `priority` | enum | -- | -- | No | `normal` |
| `tags` | array (strings) | `schema:keywords` | -- | No | `[]` |
| `mdtoClassification` | string | -- | -- | No | -- |
| `archivalDate` | date | -- | -- | No | -- |
| `number` | string | -- | `docNumber` | No | -- |
| `publicationDate` | date | -- | -- | No | -- |
| `effectiveDate` | date | -- | -- | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Decision status values** (default workflow — configurable per ProcessTemplate):

| Status | Dutch | Description | Awb Relevance |
|--------|-------|-------------|---------------|
| `draft` | Concept | Being drafted by proposer | -- |
| `submitted` | Ingediend | Submitted for consideration | -- |
| `agenda` | Geagendeerd | Placed on meeting agenda | -- |
| `committee` | In commissie | Being considered by committee(s) | Awb Art. 3:9 (advisory) |
| `debated` | Besproken | Debated in plenary/full body | -- |
| `voted` | Gestemd | Vote completed, result pending formal declaration | -- |
| `approved` | Aangenomen | Approved by required majority | Awb Art. 1:3 (besluit) |
| `rejected` | Verworpen | Rejected by vote | -- |
| `deferred` | Aangehouden | Postponed to future meeting | -- |
| `implemented` | Uitgevoerd | Decision has been executed | -- |
| `archived` | Gearchiveerd | Transferred to archive | Archiefwet 2021 |

**Decision result values**:

| Result | Dutch | Description |
|--------|-------|-------------|
| `approved` | Aangenomen | Passed by required majority |
| `rejected` | Verworpen | Failed to achieve required majority |
| `withdrawn` | Ingetrokken | Withdrawn by proposer before vote |
| `deferred` | Aangehouden | Postponed, no vote taken |
| `amended_approved` | Geamendeerd aangenomen | Approved with amendments |

#### Motion

A formal proposal for a decision, following Akoma Ntoso's motion concept. Motions can be standalone (requesting action) or attached to a decision (proposing specific text).

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Action` | Proposed action |
| **Akoma Ntoso** | `motion` | OASIS standard for parliamentary motions |
| **Robert's Rules** | Main motion, subsidiary motion, privileged motion | Procedural classification |
| **Gemeentewet** | Art. 33: moties, Art. 147a: amendement | Legal basis for council motions |

**Core properties**:

| Property | Type | Schema.org | Akoma Ntoso | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `title` | string | `schema:name` | `preface/docTitle` | Yes | -- |
| `text` | string (rich text) | `schema:text` | `body/mainBody` | Yes | -- |
| `decision` | reference | `schema:object` | -- | No | -- |
| `body` | reference | `schema:agent` | -- | Yes | -- |
| `type` | enum | `schema:additionalType` | -- | No | `substantive` |
| `proposer` | string (user UID) | `schema:creator` | `preface/docProponent` | Yes | -- |
| `coSigners` | array (user UIDs) | -- | -- | No | `[]` |
| `secondedBy` | string (user UID) | -- | -- | No | -- |
| `status` | enum | -- | -- | Yes | `draft` |
| `votingResult` | enum | -- | -- | No | -- |
| `requestsAction` | string | -- | `conclusions` | No | -- |
| `addressedTo` | string | -- | -- | No | -- |
| `deadline` | date | -- | -- | No | -- |
| `meeting` | reference | -- | -- | No | -- |
| `agendaItem` | reference | -- | -- | No | -- |
| `documents` | array (file refs) | `schema:associatedMedia` | -- | No | `[]` |
| `number` | string | -- | `docNumber` | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Motion type values**:

| Type | Dutch | Description | Robert's Rules Equivalent |
|------|-------|-------------|--------------------------|
| `substantive` | Motie | Request for action from executive | Main motion |
| `order` | Motie van orde | Procedural motion about meeting conduct | Privileged motion |
| `censure` | Motie van wantrouwen | Vote of no confidence | -- |
| `amendment` | Amendement | Textual change to a decision (see Amendment entity) | Subsidiary motion (amend) |
| `resolution` | Resolutie | Statement of position without binding action | Resolution |

**Motion status values**:

| Status | Dutch | Description |
|--------|-------|-------------|
| `draft` | Concept | Being drafted |
| `submitted` | Ingediend | Formally submitted |
| `seconded` | Ondersteund | Seconded by another member |
| `debated` | Besproken | Under debate |
| `voted` | Gestemd | Vote completed |
| `adopted` | Aangenomen | Passed |
| `rejected` | Verworpen | Failed |
| `withdrawn` | Ingetrokken | Withdrawn by proposer |

#### Amendment

A proposed textual change to a motion or decision. Follows Akoma Ntoso's amendment pattern with explicit change tracking.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Akoma Ntoso** | `amendment` / `amendmentBody` | OASIS standard for textual modifications |
| **Gemeentewet** | Art. 147a | Legal basis for council amendments |
| **Voting order** | Amendments voted before main motion | Robert's Rules: most distant amendment first |

**Core properties**:

| Property | Type | Schema.org | Akoma Ntoso | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `title` | string | `schema:name` | `preface/docTitle` | Yes | -- |
| `text` | string (rich text) | `schema:text` | `amendmentBody` | Yes | -- |
| `motion` | reference | -- | `references/activeRef` | No | -- |
| `decision` | reference | -- | -- | No | -- |
| `body` | reference | `schema:agent` | -- | Yes | -- |
| `type` | enum | -- | -- | No | `textual` |
| `proposer` | string (user UID) | `schema:creator` | `preface/docProponent` | Yes | -- |
| `coSigners` | array (user UIDs) | -- | -- | No | `[]` |
| `status` | enum | -- | -- | Yes | `draft` |
| `votingResult` | enum | -- | -- | No | -- |
| `originalText` | string | -- | `mod/quotedText` (old) | No | -- |
| `proposedText` | string | -- | `mod/quotedText` (new) | No | -- |
| `changeType` | enum | -- | `mod/@type` | No | `substitution` |
| `meeting` | reference | -- | -- | No | -- |
| `agendaItem` | reference | -- | -- | No | -- |
| `order` | integer | -- | -- | No | 0 |
| `documents` | array (file refs) | -- | -- | No | `[]` |
| `number` | string | -- | `docNumber` | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Amendment change type values** (Akoma Ntoso mod types):

| Type | Dutch | Description |
|------|-------|-------------|
| `substitution` | Vervanging | Replace existing text |
| `insertion` | Toevoeging | Insert new text |
| `deletion` | Schrapping | Remove existing text |
| `renumbering` | Hernummering | Change article/section numbering |

**Amendment status values**: Same as Motion status values.

#### Vote

A voting round on a decision, motion, or amendment. Captures the procedural context of a vote: method, quorum, timing, and aggregate result.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:VoteAction` | International standard for voting |
| **ORI mapping** | `VoteEvent` (Popolo) | Dutch open data compatibility |
| **Subject polymorphism** | References decision, motion, or amendment | All three can be voted on |
| **Quorum enforcement** | Checked before vote starts (guard event) | Gemeentewet Art. 20: quorum mandatory |

**Core properties**:

| Property | Type | Schema.org | ORI Mapping | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `subject` | reference | `schema:object` | `VoteEvent.motion_id` | Yes | -- |
| `subjectType` | enum: decision, motion, amendment | -- | -- | Yes | -- |
| `body` | reference | `schema:agent` | `VoteEvent.organization_id` | Yes | -- |
| `meeting` | reference | -- | `VoteEvent.event_id` | No | -- |
| `agendaItem` | reference | -- | -- | No | -- |
| `method` | enum | -- | -- | Yes | `show_of_hands` |
| `majorityRequired` | enum | -- | -- | No | `simple` |
| `quorumRequired` | integer | -- | -- | No | -- |
| `quorumPresent` | integer | -- | -- | No | -- |
| `quorumMet` | boolean | -- | -- | No | -- |
| `result` | enum | -- | `VoteEvent.result` | No | -- |
| `votesFor` | integer | -- | `VoteEvent.counts` | No | 0 |
| `votesAgainst` | integer | -- | `VoteEvent.counts` | No | 0 |
| `votesAbstain` | integer | -- | `VoteEvent.counts` | No | 0 |
| `votesBlank` | integer | -- | -- | No | 0 |
| `totalVotes` | integer | -- | -- | No | 0 |
| `startTime` | datetime | `schema:startTime` | `VoteEvent.start_date` | No | -- |
| `endTime` | datetime | `schema:endTime` | `VoteEvent.end_date` | No | -- |
| `isSecret` | boolean | -- | -- | No | `false` |
| `tieBreaker` | string | -- | -- | No | -- |
| `remarks` | string | -- | -- | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Vote result values**:

| Result | Dutch | Description |
|--------|-------|-------------|
| `passed` | Aangenomen | Required majority achieved |
| `failed` | Verworpen | Required majority not achieved |
| `tied` | Staken der stemmen | Tied vote (Gemeentewet Art. 30 applies) |
| `invalidated` | Ongeldig | Vote invalidated (quorum lost, procedural error) |
| `deferred` | Uitgesteld | Vote deferred (Gemeentewet Art. 30: tied vote on next meeting) |

#### Ballot

An individual vote cast by a member. For secret ballots, the `voter` field is null and ballots are anonymized.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:VoteAction` (individual) | Individual voting action |
| **ORI mapping** | `Vote` (Popolo) | Individual vote record |
| **Secret ballot** | Voter field null when `isSecret=true` on parent Vote | Gemeentewet Art. 31: secret ballot for appointments |
| **Weighted voting** | `weight` field for share-weighted votes | BW 2:228: votes proportional to share ownership |

**Core properties**:

| Property | Type | Schema.org | ORI Mapping | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `vote` | reference | `schema:object` | `Vote.vote_event_id` | Yes | -- |
| `voter` | string (user UID) | `schema:agent` | `Vote.voter_id` | No | -- |
| `choice` | enum | `schema:actionOption` | `Vote.option` | Yes | -- |
| `weight` | number | -- | `Vote.weight` | No | 1 |
| `group` | string | -- | `Vote.group_id` | No | -- |
| `delegatedBy` | string (user UID) | -- | -- | No | -- |
| `timestamp` | datetime | `schema:startTime` | -- | Auto | -- |
| `isProxy` | boolean | -- | -- | No | `false` |
| `remarks` | string | -- | -- | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |

**Ballot choice values**:

| Choice | Dutch | Description |
|--------|-------|-------------|
| `for` | Voor | In favor |
| `against` | Tegen | Opposed |
| `abstain` | Onthouding | Abstaining from vote |
| `blank` | Blanco | Blank vote (secret ballot only) |
| `invalid` | Ongeldig | Invalid ballot (secret ballot only) |

#### Resolution

The formal output of an approved decision. Resolutions are the official, published acts that result from the decision-making process.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Legislation` | Legislation/act type |
| **Akoma Ntoso** | `act` / `decision` | OASIS standard for enacted legislation |
| **ELI** | URI-based identification | European cross-reference for legislation |
| **Awb** | Art. 1:3 (besluit), Art. 3:40 (bekendmaking) | Must be written, motivated, published |

**Core properties**:

| Property | Type | Schema.org | Akoma Ntoso | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `title` | string | `schema:name` | `preface/docTitle` | Yes | -- |
| `text` | string (rich text) | `schema:text` | `body/mainBody` | Yes | -- |
| `decision` | reference | `schema:isBasedOn` | -- | Yes | -- |
| `body` | reference | `schema:creator` | -- | Yes | -- |
| `number` | string | `schema:legislationIdentifier` | `docNumber` | No | -- |
| `dateAdopted` | date | `schema:dateCreated` | `FRBRdate/@date` | Yes | -- |
| `effectiveDate` | date | `schema:legislationDate` | -- | No | -- |
| `expirationDate` | date | `schema:expires` | -- | No | -- |
| `signatories` | array (user UIDs) | `schema:accountablePerson` | `conclusions/signature` | No | `[]` |
| `motivation` | string (rich text) | -- | `preamble/recitals` | No | -- |
| `legalBasis` | string | -- | `preamble/citations` | No | -- |
| `publicationDate` | date | -- | -- | No | -- |
| `publicationMedium` | string | -- | -- | No | -- |
| `eliUri` | string | -- | -- | No | -- |
| `mdtoMetadata` | object | -- | -- | No | -- |
| `supersedes` | reference | -- | `references/passiveRef` | No | -- |
| `supersededBy` | reference | -- | -- | No | -- |
| `documents` | array (file refs) | `schema:associatedMedia` | -- | No | `[]` |
| `status` | enum | -- | -- | Yes | `active` |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Resolution status values**:

| Status | Dutch | Description |
|--------|-------|-------------|
| `active` | Actief | Currently in force |
| `superseded` | Vervangen | Replaced by newer resolution |
| `repealed` | Ingetrokken | Formally repealed |
| `expired` | Verlopen | Expired by its own terms |
| `archived` | Gearchiveerd | Transferred to permanent archive |

#### Minutes

The official record of a meeting. Minutes capture attendance, agenda items discussed, decisions taken, and action items assigned.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:CreativeWork` | Written document |
| **Akoma Ntoso** | `debate` / `debateReport` | Parliamentary debate record structure |
| **Gemeentewet** | Art. 23: minutes must be kept | Legal requirement for council meetings |
| **Approval flow** | draft -> review -> approved | Minutes must be formally approved |

**Core properties**:

| Property | Type | Schema.org | Akoma Ntoso | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `meeting` | reference | `schema:about` | -- | Yes | -- |
| `title` | string | `schema:name` | `preface/docTitle` | No | Auto-generated |
| `text` | string (rich text) | `schema:text` | `debateBody` | No | -- |
| `status` | enum | -- | -- | Yes | `draft` |
| `author` | string (user UID) | `schema:author` | -- | No | -- |
| `attendees` | array (user UIDs) | `schema:attendee` | -- | No | `[]` |
| `absentees` | array (user UIDs) | -- | -- | No | `[]` |
| `approvedBy` | reference (body) | -- | -- | No | -- |
| `approvedDate` | date | -- | -- | No | -- |
| `approvalMeeting` | reference | -- | -- | No | -- |
| `actionItems` | array (objects) | -- | -- | No | `[]` |
| `corrections` | array (objects) | -- | -- | No | `[]` |
| `documents` | array (file refs) | `schema:associatedMedia` | -- | No | `[]` |
| `mdtoMetadata` | object | -- | -- | No | -- |
| `transcriptSource` | enum | -- | -- | No | -- |
| `createdAt` | datetime | `schema:dateCreated` | -- | Auto | -- |
| `updatedAt` | datetime | `schema:dateModified` | -- | Auto | -- |

**Minutes status values**:

| Status | Dutch | Description |
|--------|-------|-------------|
| `draft` | Concept | Being written/edited |
| `review` | Ter inzage | Published for review by members |
| `approved` | Vastgesteld | Formally approved by the body |
| `corrected` | Gecorrigeerd | Approved with corrections |

**Action item structure** (embedded object, not separate entity):

| Property | Type | Required |
|----------|------|----------|
| `description` | string | Yes |
| `assignedTo` | string (user UID) | No |
| `dueDate` | date | No |
| `status` | enum: open, in_progress, completed | Yes |
| `taskUid` | string (Nextcloud Tasks ref) | No |

#### ProcessTemplate

A configurable decision process definition using Symfony Workflow YAML structure. Templates encode the complete state machine for a decision lifecycle, including states, transitions, guards (quorum/majority rules), and role requirements.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Technology** | Symfony Workflow Component | PHP-native state machine with guards, parallel states, audit trail |
| **Storage** | JSON in OpenRegister (YAML-equivalent structure) | Configurable per organization/body without code changes |
| **DMN-inspired** | Decision tables for voting rules | Configurable quorum/majority without full DMN engine overhead |

**Core properties**:

| Property | Type | Required | Default |
|----------|------|----------|---------|
| `name` | string | Yes | -- |
| `description` | string | No | -- |
| `organization` | reference | No | -- |
| `body` | reference | No | -- |
| `type` | enum | Yes | `state_machine` |
| `initialPlace` | string | Yes | `draft` |
| `places` | array (objects) | Yes | -- |
| `transitions` | array (objects) | Yes | -- |
| `rules` | object | No | `{}` |
| `isDefault` | boolean | No | `false` |
| `isActive` | boolean | No | `true` |
| `version` | integer | No | 1 |
| `createdAt` | datetime | Auto | -- |
| `updatedAt` | datetime | Auto | -- |

**Place (state) structure** (embedded object):

| Property | Type | Description |
|----------|------|-------------|
| `name` | string | Machine name (e.g., `draft`, `voted`) |
| `label` | string | Display name |
| `labelNl` | string | Dutch display name |
| `color` | string (hex) | Visual indicator |
| `isFinal` | boolean | Terminal state |
| `metadata` | object | Custom metadata |

**Transition structure** (embedded object):

| Property | Type | Description |
|----------|------|-------------|
| `name` | string | Transition name (e.g., `submit`, `approve`) |
| `label` | string | Display name |
| `labelNl` | string | Dutch display name |
| `from` | string or array | Source place(s) |
| `to` | string | Target place |
| `guard` | object | Guard rules (evaluated before transition) |
| `requiredRoles` | array | Roles that may trigger this transition |
| `notificationTemplate` | string | Notification on transition |

**Guard rules structure** (DMN-inspired decision table):

| Property | Type | Description |
|----------|------|-------------|
| `quorumRequired` | boolean | Must quorum be met? |
| `quorumPercentage` | integer | Minimum quorum % |
| `majorityType` | enum | Required majority type |
| `secretBallot` | boolean | Force secret ballot? |
| `requiresSeconder` | boolean | Motion must be seconded? |
| `minDebateTime` | integer (minutes) | Minimum debate duration |
| `requiresMotivation` | boolean | Decision must include motivation? |
| `customConditions` | array | Additional PHP-evaluable conditions |

**Default legislative template** (Gemeentewet-compliant):

| Place | Transitions Out | Guard |
|-------|----------------|-------|
| `draft` | submit | Requires proposer role |
| `submitted` | agenda, reject, withdraw | Requires clerk role for agenda |
| `agenda` | committee, debate, withdraw | -- |
| `committee` | debate, defer | -- |
| `debated` | vote, defer, withdraw | -- |
| `voted` | approve, reject | Quorum: 50%, Majority: absolute |
| `approved` | implement | -- |
| `rejected` | -- (final) | -- |
| `deferred` | agenda | -- |
| `implemented` | archive | -- |
| `archived` | -- (final) | -- |

### 3.3 Status/Enum Values

All status enums are defined in entity sections above. Cross-entity summary:

| Entity | Statuses | Default |
|--------|----------|---------|
| **Meeting** | draft, convened, in_progress, adjourned, completed, minutes_approved, cancelled | `draft` |
| **AgendaItem** | pending, active, completed, deferred, withdrawn | `pending` |
| **Decision** | draft, submitted, agenda, committee, debated, voted, approved, rejected, deferred, implemented, archived | `draft` |
| **Motion** | draft, submitted, seconded, debated, voted, adopted, rejected, withdrawn | `draft` |
| **Amendment** | draft, submitted, seconded, debated, voted, adopted, rejected, withdrawn | `draft` |
| **Vote** | (result enum) passed, failed, tied, invalidated, deferred | -- |
| **Ballot** | (choice enum) for, against, abstain, blank, invalid | -- |
| **Resolution** | active, superseded, repealed, expired, archived | `active` |
| **Minutes** | draft, review, approved, corrected | `draft` |

### 3.4 Cross-App Relationships

Decidesk integrates with Procest (case management) and Docudesk (document generation) within the Conduction app ecosystem:

```
Decidesk (Decision-making)          Procest (Case Management)
+--------------------+              +--------------------+
|   Organization     |              |                    |
|   Body             |              |                    |
|   Meeting          |              |                    |
|   AgendaItem       |              |                    |
|   Decision  -------+-- implements +-->  Case           |
|   Motion           |              |     Task           |
|   Amendment        |              |                    |
|   Vote / Ballot    |              |                    |
|   Resolution ------+-- triggers   +-->  Case           |
|   Minutes          |              |                    |
|   ProcessTemplate  |              |                    |
+--------------------+              +--------------------+
         |                                    |
         | generates                          | generates
         v                                    v
+------------------------------------------------------------+
|  Docudesk (Document Generation)                            |
|  - Resolution PDFs (Akoma Ntoso-structured)                |
|  - Meeting convocations                                    |
|  - Minutes documents                                       |
|  - Decision notifications                                  |
|  - Voting certificates                                     |
+------------------------------------------------------------+
```

**Decision -> Case flow**: An approved decision that requires execution can create a Procest case. The resolution, motivation, and dossier documents are linked to the case.

**Resolution -> Document flow**: Docudesk generates formal resolution documents following Akoma Ntoso structural conventions (preface, preamble, body, conclusions, signatures).

**Minutes -> Document flow**: Docudesk generates minutes documents from the structured minutes data, including attendance records, agenda items, and decision summaries.

### 3.5 Integration Strategy

#### REUSE from Nextcloud

| Feature | OCP Interface | What to Reuse | How |
|---------|--------------|---------------|-----|
| **Calendar** | `OCP\Calendar\IManager` | Meeting schedule as CalDAV events | Create events for meetings. Expose meeting calendar via `ICalendarProvider`. Reference by event UID in `calendarEventUid`. |
| **Tasks** | `OCP\Calendar\IManager` | Action items from minutes as VTODO | Create tasks from minutes action items. Reference by task UID. Track completion state. |
| **Files** | `OCP\Files\IRootFolder` | Dossier documents per decision, agenda attachments | Auto-create folder structure: `Decidesk/{Body}/{Year}/{Decision-NR}/`. Reference by file ID. |
| **Mail** | `OCP\Mail\IMailer` | Meeting convocations, vote reminders, digest notifications | Send convocation emails with agenda. Link incoming emails to decisions via message-ID. |
| **Talk** | `OCP\Talk\IBroker` | Deliberation channels per decision/body | Create conversation per decision for informal deliberation between meetings. Store token. |
| **Contacts** | `OCP\Contacts\IManager` | Member/participant identity data | Reference members by vCard UID. Lookup display names, roles, organizational membership. |
| **Activity** | `OCP\Activity\IManager` | Audit trail of decision lifecycle | Publish events: "Decision submitted", "Vote completed", "Resolution adopted". Implement `IProvider`. |
| **Notifications** | `OCP\Notification\IManager` | State change alerts, vote reminders | Notify on: meeting convened, vote starting, decision approved, action item assigned. |

#### BUILD in OpenRegister (Decidesk-specific)

| What | Why Not Reuse |
|------|---------------|
| **Organizations & bodies** | Governance hierarchy with domain-specific rules (quorum, voting method, majority type) |
| **Meetings** | Formal meeting lifecycle with quorum enforcement, agenda management, attendance tracking |
| **Decisions** | Configurable state machine with legal compliance requirements per domain |
| **Motions & amendments** | Legislative document lifecycle with Akoma Ntoso-inspired structure |
| **Votes & ballots** | Formal voting with quorum checks, majority calculation, secret ballot support, weighted votes |
| **Resolutions** | Formal acts with legal publication requirements, ELI identifiers, archival metadata |
| **Minutes** | Structured meeting records with approval workflow and action item tracking |
| **Process templates** | Symfony Workflow definitions configurable per organization/body |

### 3.6 @conduction/nextcloud-vue Library

Decidesk uses shared components from `@conduction/nextcloud-vue`:

| Component | Usage in Decidesk |
|-----------|-------------------|
| `ObjectList` / `MagicTable` | List views for decisions, meetings, motions, resolutions |
| `ObjectDetail` | Detail views for all entities with tab navigation |
| `KanbanBoard` | Decision pipeline visualization (by workflow place) |
| `TimelineView` | Decision audit trail / workflow history |
| `FormBuilder` | Dynamic forms for motion text, amendment proposals |
| `FileAttachments` | Dossier document management on decisions |
| `CommentThread` | Discussion threads on decisions and motions |
| `UserPicker` | Member selection for bodies, proposer assignment |
| `StatusBadge` | Decision/meeting/motion status indicators |
| `SearchBar` | Full-text search across all decision entities |

### 3.7 Vue Router

| Route | View | Description |
|-------|------|-------------|
| `/` | Dashboard | Overview: upcoming meetings, pending decisions, my votes, action items |
| `/organizations` | OrganizationList | All organizations |
| `/organizations/:id` | OrganizationDetail | Organization with bodies, settings |
| `/bodies` | BodyList | All decision-making bodies |
| `/bodies/:id` | BodyDetail | Body with members, meetings, decisions |
| `/meetings` | MeetingList | All meetings (calendar + list toggle) |
| `/meetings/:id` | MeetingDetail | Meeting with agenda, attendance, documents |
| `/meetings/:id/agenda` | AgendaEditor | Drag-and-drop agenda builder |
| `/meetings/:id/live` | LiveMeeting | Active meeting view: current item, voting, timer |
| `/decisions` | DecisionList | All decisions (filterable by status, body, category) |
| `/decisions/:id` | DecisionDetail | Decision with full lifecycle, dossier, votes |
| `/decisions/:id/vote` | VotingView | Active voting interface |
| `/motions` | MotionList | All motions |
| `/motions/:id` | MotionDetail | Motion with amendments, voting results |
| `/resolutions` | ResolutionList | Resolution register (searchable, filterable) |
| `/resolutions/:id` | ResolutionDetail | Published resolution with metadata |
| `/minutes` | MinutesList | All minutes |
| `/minutes/:id` | MinutesEditor | Rich text editor with structured sections |
| `/templates` | TemplateList | Process template management |
| `/templates/:id` | TemplateEditor | Visual workflow editor |
| `/settings` | AdminSettings | App configuration |

### 3.8 Nextcloud Integration Strategy

**Principle: reuse Nextcloud native objects where possible, reference by ID, don't duplicate.**

OpenRegister objects store governance-specific fields plus **foreign keys** (calendar event UID, task UID, file ID, user UID, Talk token) pointing to Nextcloud native entities. The PHP service layer uses OCP interfaces to read/write native data.

#### Key OCP Interfaces

```php
// Calendar - create meeting events as CalDAV
$calendarManager = \OCP\Server::get(\OCP\Calendar\IManager::class);
$builder = $calendarManager->createEventBuilder(); // NC 31+
$builder->setSummary('Council Meeting #42')
    ->setDescription('Regular council meeting')
    ->setStartDate($meetingStart)
    ->setEndDate($meetingEnd)
    ->setLocation('Council Chamber');
$eventUid = $builder->createInCalendar($calendarId);
// Store $eventUid on Meeting object for sync

// Calendar Provider - expose meetings as virtual calendar
use OCP\Calendar\ICalendarProvider;

class MeetingCalendarProvider implements ICalendarProvider {
    public function getCalendars(
        string $principalUri,
        array $calendarUris = []
    ): array {
        // Return virtual calendar with all meetings
        // the user is invited to or is a member of the body
        return [new MeetingCalendar($this->meetingService, $principalUri)];
    }
}

// Tasks - create action items from minutes as VTODO
$calendarManager = \OCP\Server::get(\OCP\Calendar\IManager::class);
$taskBuilder = $calendarManager->createEventBuilder();
$taskBuilder->setSummary('Draft implementation plan for resolution 2024-42')
    ->setDueDate($dueDate)
    ->setStatus('NEEDS-ACTION');
$taskUid = $taskBuilder->createInCalendar($taskCalendarId);
// Store $taskUid in Minutes.actionItems[].taskUid

// Files - dossier folder structure per decision
$rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
$userFolder = $rootFolder->getUserFolder($userId);
$dossierPath = "Decidesk/{$bodyName}/{$year}/{$decisionNumber}";
if (!$userFolder->nodeExists($dossierPath)) {
    $userFolder->newFolder($dossierPath);
}
// Store folder path on Decision.dossierFolder

// Mail - send meeting convocation
$mailer = \OCP\Server::get(\OCP\Mail\IMailer::class);
$message = $mailer->createMessage();
$message->setTo($memberEmails);
$message->setSubject("Convocation: {$meeting->getName()} - {$meeting->getScheduledStart()->format('d-m-Y')}");
$template = $mailer->createEMailTemplate('decidesk.convocation');
$template->addHeader();
$template->addHeading($meeting->getName());
$template->addBodyText('You are invited to the following meeting:');
// Attach agenda items, documents
$message->useTemplate($template);
$mailer->send($message);

// Talk - create deliberation channel per decision
$broker = \OCP\Server::get(\OCP\Talk\IBroker::class);
$conversation = $broker->createConversation(
    "Decision: {$decision->getTitle()}",
    $bodyMemberUids
);
// Store $conversation->getToken() on Decision.talkToken

// Contacts - resolve member identity
$contactsManager = \OCP\Server::get(\OCP\Contacts\IManager::class);
$results = $contactsManager->search($userId, ['UID'], ['limit' => 1]);
// Use display name, organization, role from contact card

// Activity - audit trail for decision lifecycle
$activityManager = \OCP\Server::get(\OCP\Activity\IManager::class);
$event = $activityManager->generateEvent();
$event->setApp('decidesk')
    ->setType('decision_lifecycle')
    ->setAffectedUser($proposerUid)
    ->setSubject('decision_approved', [
        'decision' => $decision->getTitle(),
        'body' => $body->getName(),
        'votes_for' => $vote->getVotesFor(),
        'votes_against' => $vote->getVotesAgainst(),
    ])
    ->setObject('decision', $decision->getId(), $decision->getTitle());
$activityManager->publish($event);

// Notifications - state change alerts
$notificationManager = \OCP\Server::get(\OCP\Notification\IManager::class);
$notification = $notificationManager->createNotification();
$notification->setApp('decidesk')
    ->setUser($memberUid)
    ->setDateTime(new \DateTime())
    ->setObject('vote', (string)$vote->getId())
    ->setSubject('vote_starting', [
        'decision' => $decision->getTitle(),
        'body' => $body->getName(),
        'method' => $vote->getMethod(),
    ]);
$notificationManager->notify($notification);
```

## 4. OpenRegister Configuration

### Register

| Field | Value |
|-------|-------|
| Name | `decidesk` |
| Slug | `decidesk` |
| Description | Universal decision-making and governance register |

### Schema Definitions

Schemas MUST be defined in `lib/Settings/decidesk_register.json` using OpenAPI 3.0.0 format (not inline PHP), following the pattern used by opencatalogi and softwarecatalog.

**Schemas**:
- `organization` — Governance body (schema:Organization)
- `body` — Decision-making body within organization (schema:Organization subOrganization)
- `meeting` — Scheduled gathering (schema:Event)
- `agendaItem` — Meeting agenda item (schema:Event part)
- `decision` — Formal decision with state machine (schema:Action)
- `motion` — Formal proposal (Akoma Ntoso motion)
- `amendment` — Proposed change to motion (Akoma Ntoso amendment)
- `vote` — Voting round (schema:VoteAction)
- `ballot` — Individual vote cast (schema:VoteAction individual)
- `resolution` — Formal output of approved decision (Akoma Ntoso act)
- `minutes` — Meeting record (schema:CreativeWork)
- `processTemplate` — Configurable workflow definition (Symfony Workflow)

The configuration is imported via `ConfigurationService::importFromApp()` in the repair step.

## 5. Open Research Questions

The following questions need further investigation as the app matures:

1. **ORI API compliance depth** — OpenRaadsinformatie connects 265/345 Dutch municipalities. Should Decidesk expose a full ORI-compatible REST API, or just export data in ORI format? Current decision: start with ORI export, add API later based on demand.

2. **AI meeting transcription** — Market intelligence shows AI meeting assistant adoption grew 17x in 2024, but Otter.ai and Fireflies.ai face privacy lawsuits. Should Decidesk integrate with Nextcloud's ExApp AI infrastructure (Whisper, LLM summarization) for self-hosted transcription? Current decision: design Minutes entity to accept AI transcription (`transcriptSource` field), implement integration as a separate phase.

3. **E-voting security level** — Council of Europe CM/Rec(2017)5 defines 49 standards for e-voting. POLYAS is the only BSI Common Criteria certified voting software. For legislative domain users, what security certification is needed? Current decision: start with non-binding votes and internal governance; formal e-voting certification is a future phase.

4. **Speaking time tracking** — Equal Time case study shows 65% increase in women's speaking time when tracked. Should Decidesk include real-time speaking time tracking in the LiveMeeting view? Current decision: include as optional feature in live meeting view.

5. **Multi-language resolutions** — ELI supports multilingual legislation. Should resolutions support multiple language versions? Current decision: single language per resolution, with `schema:inLanguage` field. Multilingual support via separate resolution objects with `schema:translationOfWork` references.

6. **Archival integration** — Archiefwet 2021 mandates 10-year transfer to permanent archive. Should Decidesk integrate with specific archival systems (e-Depot, DMS)? Current decision: store MDTO metadata on decisions and resolutions; export capability for archival transfer as a future phase.

7. **Citizen access portal** — Woo (Wet open overheid) requires active publication of decision documents. Should Decidesk include a public-facing portal? Current decision: expose public read-only API for ZaakAfhandelApp or external portals to consume. Woo publication via Docudesk export.

8. **Real-time collaborative editing** — Should motion/amendment text editing support real-time collaboration (like Nextcloud Text)? Current decision: use Nextcloud Text integration for collaborative drafting, store final text in OpenRegister.

## 6. References

### Primary Standards (International)
- [Schema.org](https://schema.org/) — Linked data vocabulary (primary data model)
- [Akoma Ntoso (LegalDocML) v1.0](http://docs.oasis-open.org/legaldocml/akn-core/v1.0/akn-core-v1.0.html) — OASIS standard for legislative documents
- [BPMN 2.0 (ISO 19510)](https://www.omg.org/spec/BPMN/2.0/) — Business process modeling (inspiration for visualization)
- [DMN 1.x](https://www.omg.org/spec/DMN/) — Decision model and notation (inspiration for voting rules)
- [ELI — European Legislation Identifier](https://eur-lex.europa.eu/eli-register/about.html) — EU standard for legislation identification
- [Popolo Standard](https://www.popoloproject.com/) — International open government data specification

### Schema.org Types Used
- [schema:Organization](https://schema.org/Organization) — Organization and Body entities
- [schema:Event](https://schema.org/Event) — Meeting and AgendaItem entities
- [schema:Action](https://schema.org/Action) — Decision and Motion entities
- [schema:VoteAction](https://schema.org/VoteAction) — Vote and Ballot entities
- [schema:Legislation](https://schema.org/Legislation) — Resolution entity
- [schema:CreativeWork](https://schema.org/CreativeWork) — Minutes entity

### Dutch Legal Framework
- [Algemene wet bestuursrecht (Awb)](https://wetten.overheid.nl/BWBR0005537/) — Administrative law: decision requirements, motivation, publication
- [Gemeentewet](https://wetten.overheid.nl/BWBR0005416/) — Municipal law: council meetings, quorum, voting
- [Burgerlijk Wetboek Boek 2](https://wetten.overheid.nl/BWBR0003045/) — Civil code: associations, BVs, corporate governance
- [Wet digitaal vergaderen decentrale overheden](https://www.eerstekamer.nl/wetsvoorstel/36217_wet_digitaal_vergaderen) — Digital meetings for local government
- [Wet open overheid (Woo)](https://wetten.overheid.nl/BWBR0045754/) — Open government information publication
- [Archiefwet 2021](https://wetten.overheid.nl/BWBR0048363/) — Archival law: 10-year transfer

### Dutch Standards
- [MDTO](https://www.nationaalarchief.nl/archiveren/mdto) — Metadata for durable accessible government information
- [OpenRaadsinformatie (ORI)](https://openraadsinformatie.nl/) — Open council information standard (265/345 municipalities)
- [Forum Standaardisatie](https://www.forumstandaardisatie.nl/) — Dutch government standards register

### Technology
- [Symfony Workflow Component](https://symfony.com/doc/current/components/workflow.html) — PHP state machine for decision lifecycle
- [Robert's Rules of Order (12th ed.)](https://www.robertsrules.com/) — Parliamentary procedure patterns

### Competitive Landscape
- [GO Raadsinformatie](https://www.govocal.com/) — Dutch RIS market (proprietary SaaS)
- [Notubiz](https://www.notubiz.nl/) — Dutch RIS market leader, 50%+ share (proprietary SaaS)
- [iBabs](https://www.ibabs.eu/) — Board portal and meeting management (proprietary SaaS)
- [Diligent Boards](https://www.diligent.com/) — Enterprise board portal, $48-155K/year (proprietary)
- [OnBoard](https://www.onboardmeetings.com/) — Growing board portal challenger (proprietary SaaS)
- [OpenSlides](https://openslides.com/) — Open source assembly management (MIT, Python/Angular)
- [Loomio](https://www.loomio.com/) — Open source collaborative decision-making (AGPL-3.0, Ruby/Vue)
- [Decidim](https://decidim.org/) — Open source citizen participation (AGPL-3.0, Ruby on Rails)
- [Fellow.app](https://fellow.app/) — AI meeting management (proprietary SaaS)
