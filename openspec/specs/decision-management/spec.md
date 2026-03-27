---
status: idea
---

# Decision Management Specification

**Status**: idea
**Standards**: Schema.org (`Action`, `VoteAction`, `ChooseAction`), Akoma Ntoso (`decision`, `judgment`), OpenRaadsinformatie (`Besluit`), Awb, BW Boek 2, Gemeentewet, DMN, BPMN 2.0
**Feature tier**: MVP

## Purpose

Decision management is the core capability of Decidesk. A decision represents a formal choice made by a governance body, association, corporate board, or operational team. Each decision follows a configurable state machine lifecycle from proposal through deliberation, voting, and resolution. This specification covers the decision entity, status transitions, the Symfony Workflow-backed state machine, and audit trail recording. The system uniquely spans five decision-making domains (legislative, association, corporate governance, corporate operations, and citizen participation) -- no competitor currently covers all five (insight #7).

## Data Model

See [ARCHITECTURE.md](../../architecture/README.md) for the full Decision entity definition including property tables, Schema.org mappings, Akoma Ntoso alignment, and OpenRaadsinformatie mapping.

| Entity | Schema.org Type | Key Properties |
|--------|----------------|----------------|
| Decision | `schema:ChooseAction` | title, body, status, decisionType, votingResult, enactedDate |
| Vote | `schema:VoteAction` | candidate, option, result, voter, actionStatus |
| Resolution | `schema:Action` | identifier, title, body, datePublished, legislationIdentifier |
| AuditEntry | `schema:Action` | timestamp, actor, fromState, toState, comment |

## Requirements

---

### Requirement: Decision Creation [MVP]

The system MUST support creating decision records linked to a meeting, agenda item, or body. Each decision MUST have a `title`, a `body` (governing body reference), and an initial status of `draft`. Decisions MUST be stored as OpenRegister objects in the `decidesk` register using the `decision` schema.

**Legal reference**: Awb Art. 1:3 -- all administrative decisions (besluiten) must be in writing, contain motivation, and follow proper procedure.

#### Scenario: Create a decision from a meeting agenda item

- GIVEN a user with decision-making access and an active meeting with agenda items
- WHEN they create a new decision linked to agenda item "Budget Approval 2026"
- THEN the system MUST create an OpenRegister object in the `decidesk` register with the `decision` schema
- AND the object MUST have `@type` set to `schema:ChooseAction`
- AND the `status` MUST be set to `draft`
- AND the decision MUST reference the agenda item and meeting

#### Scenario: Create a standalone decision outside a meeting

- GIVEN a user with decision-making access
- WHEN they create a decision with title "Appoint new treasurer" and body "Board of Directors"
- THEN the system MUST create the decision with status `draft`
- AND the decision MUST NOT require a meeting or agenda item reference
- AND the decision MUST reference the body "Board of Directors"

#### Scenario: Fail to create a decision without a title

- GIVEN a user with decision-making access
- WHEN they submit a new decision form without a title
- THEN the system MUST reject the request with a validation error
- AND no OpenRegister object MUST be created

---

### Requirement: Decision State Machine [MVP]

The system MUST enforce a configurable state machine for decision lifecycle management using the Symfony Workflow Component (insight #11). The default lifecycle MUST include states: `draft`, `proposed`, `deliberating`, `voting`, `decided`, `enacted`, `archived`. Only valid transitions MUST be allowed. The state machine MUST be defined in YAML configuration, support guards via expression language and event listeners, and fire transition events for audit trail recording.

**Legal reference**: Awb 3:40-3:45 (formal decision requirements), Gemeentewet 56 (council decision procedures)
**Technical reference**: Symfony Workflow Component -- PHP-native state machine, lightweight, supports both `workflow` and `state_machine` types (insight #14: avoid full BPMN engines -- overkill for this use case)

#### Scenario: Transition a decision from draft to proposed

- GIVEN a decision in `draft` status with all required fields completed
- WHEN the decision owner triggers the "propose" transition
- THEN the status MUST change to `proposed`
- AND the transition MUST be recorded in the audit trail with timestamp and actor
- AND notifications MUST be sent to all members of the governing body

#### Scenario: Reject an invalid state transition

- GIVEN a decision in `draft` status
- WHEN a user attempts to transition directly to `decided`
- THEN the system MUST reject the transition with an error indicating the allowed transitions from `draft`
- AND the decision status MUST remain `draft`

#### Scenario: Transition a decision to enacted after approval

- GIVEN a decision in `decided` status with a positive voting outcome
- WHEN the decision owner triggers the "enact" transition
- THEN the status MUST change to `enacted`
- AND the system MUST generate a resolution record (see resolution-minutes spec)
- AND the enacted date MUST be recorded

#### Scenario: Custom state machine per body type

- GIVEN a governance body of type "management_team" with a simplified workflow (draft -> proposed -> decided -> enacted)
- WHEN an administrator configures the body's decision workflow
- THEN the system MUST allow skipping the `deliberating` and `voting` states
- AND only the configured transitions MUST be available for that body's decisions

---

### Requirement: Decision Audit Trail [MVP]

The system MUST maintain a complete audit trail for every decision, recording all state transitions, modifications, votes, and comments with timestamps and actor identities. The audit trail MUST be immutable.

**Legal reference**: WBTR (Wet bestuur en toezicht rechtspersonen) documentation requirements

#### Scenario: View the complete history of a decision

- GIVEN a decision that has moved through draft, proposed, deliberating, voting, and decided
- WHEN a user views the decision's audit trail
- THEN the system MUST display all transitions in chronological order
- AND each entry MUST show the timestamp, actor name, previous state, new state, and optional comment

#### Scenario: Audit trail entries are immutable

- GIVEN a decision with audit trail entries
- WHEN any user (including admin) attempts to modify or delete an audit trail entry
- THEN the system MUST reject the modification
- AND the original entry MUST remain unchanged

#### Scenario: Export audit trail for compliance

- GIVEN a decision with a complete audit trail
- WHEN a user exports the audit trail
- THEN the system MUST produce a timestamped document containing all entries
- AND the export MUST be suitable for compliance reporting (WBTR, Archiefwet)

---

### Requirement: Decision List and Search [MVP]

The system MUST provide a list view of all decisions with search, sort, and filter capabilities. Users MUST be able to filter by status, body, date range, and decision type.

#### Scenario: Filter decisions by status

- GIVEN the decision list contains decisions in various statuses
- WHEN the user filters by status "voting"
- THEN only decisions currently in the `voting` state MUST be displayed
- AND the result count MUST be shown

#### Scenario: Search decisions by title

- GIVEN decisions exist with titles "Budget 2026", "New parking policy", "Staff expansion"
- WHEN the user searches for "budget"
- THEN the decision "Budget 2026" MUST appear in the results
- AND the search MUST be case-insensitive

#### Scenario: Search across decisions, motions, and minutes

- GIVEN the Nextcloud unified search is available
- WHEN the user searches from the Nextcloud search bar
- THEN decisions, motions, and meeting minutes MUST appear in results
- AND results MUST link directly to the decision detail view

---

### Requirement: Decision Detail View [MVP]

The system MUST provide a detail view for each decision using the `CnDetailPage` and `CnObjectSidebar` components. The detail view MUST show decision metadata, current status with state machine visualization, linked agenda item/meeting, voting results, and the audit trail.

#### Scenario: View decision detail with voting results

- GIVEN a decision in `decided` status with completed voting
- WHEN the user navigates to the decision detail view
- THEN the page MUST display the decision title, body, status badge, and description
- AND the voting results MUST show for/against/abstain counts
- AND the state machine visualization MUST highlight the current state
- AND the sidebar MUST show metadata, linked meeting, and action buttons

#### Scenario: Visualize state machine with XState

- GIVEN a decision with a configured workflow
- WHEN the user views the decision detail
- THEN the frontend MUST render the state machine diagram using XState (insight #12)
- AND the current state MUST be visually highlighted
- AND past states MUST show transition timestamps

---

### Requirement: Voting Execution [MVP]

The system MUST support multiple voting methods configurable per body and decision type. The default MUST be simple majority (for/against/abstain). The system MUST enforce quorum before allowing votes.

**Legal reference**: Gemeentewet Art. 30 (absolute majority, tie-breaking), BW 2:38 (one member one vote), BW 2:230 (configurable majority)

#### Scenario: Conduct open vote with simple majority

- GIVEN a decision in `voting` status with quorum met
- WHEN each member casts their vote (for/against/abstain)
- THEN the system MUST tally votes in real-time
- AND the result MUST be determined by simple majority (>50% of valid votes)
- AND the voting result MUST be recorded with individual vote records

#### Scenario: Conduct secret ballot for personnel appointment

- GIVEN a decision of type "appointment" requiring secret ballot
- WHEN members cast their votes
- THEN individual votes MUST NOT be attributable to specific members
- AND only the aggregate result (for/against/abstain counts) MUST be recorded
- AND the system MUST comply with Gemeentewet Art. 31 (mandatory secret ballot for appointments)

#### Scenario: Enforce qualified majority for statute amendment

- GIVEN a decision of type "statute_amendment" requiring 2/3 majority
- WHEN 60% vote in favor
- THEN the decision MUST be marked as "rejected" because 2/3 threshold was not met
- AND the required threshold MUST be clearly displayed

#### Scenario: Handle tie-breaking

- GIVEN a decision with equal for/against votes in a full meeting
- WHEN the votes are tallied
- THEN the decision MUST be rejected per Gemeentewet Art. 30
- AND for tied votes on persons, the system MUST support resolution by lot

---

### Requirement: Written Decision Procedure (Besluitvorming buiten vergadering) [MVP]

The system MUST support decision-making outside of meetings via written/electronic procedure per BW 2:238 (BV) and BW 2:40 (association).

**Legal reference**: BW 2:238 requires unanimous consent to the METHOD (not the decision itself), BW 2:40 for associations

#### Scenario: Circulate written resolution to board members

- GIVEN a chair who wants to make an urgent decision between meetings
- WHEN they create a written resolution and circulate it to all board members
- THEN each member MUST be able to consent to the method and vote on the resolution electronically
- AND the system MUST track that ALL eligible members have consented to the written procedure
- AND the resolution MUST only be valid if unanimity on the method is achieved

#### Scenario: Written resolution with deadline

- GIVEN a written resolution circulated with a 5-day response deadline
- WHEN the deadline expires with 8 of 10 members having responded
- THEN the system MUST notify the chair that 2 members have not responded
- AND the resolution MUST NOT be considered valid until all members respond or explicitly waive

---

### Requirement: Decision by Silence (Stilzwijgende goedkeuring) [V1]

The system SHOULD support "decision by silence" where a proposal is adopted if no member objects within a configured period.

#### Scenario: Adopt proposal by silence

- GIVEN a proposal circulated with a 7-day objection period
- WHEN no member raises an objection within 7 days
- THEN the proposal MUST be automatically adopted
- AND the system MUST record the adoption with "adopted by silence" as the method

---

### Requirement: Decision Categorization and Types [MVP]

The system MUST support configurable decision types that map to different workflows, required majorities, and document templates.

#### Scenario: Configure decision types per body

- GIVEN an administrator configuring a municipal council body
- WHEN they define decision types: "raadsbesluit", "motie", "amendement", "hamerstuk"
- THEN each type MUST have its own workflow, required majority, and document template
- AND "hamerstuk" MUST default to adoption without debate

#### Scenario: Track decision type distribution

- GIVEN a body with decisions of various types
- WHEN a user views the decision analytics
- THEN the system MUST show the distribution of decision types (pie chart)
- AND the decision rate (decisions per meeting) MUST be trackable

---

### Requirement: Decision Implementation Tracking [V1]

The system SHOULD track the implementation status of decisions with responsible persons and deadlines.

#### Scenario: Assign implementation tasks after decision

- GIVEN a decision in `enacted` status
- WHEN the chair assigns implementation tasks with owners and deadlines
- THEN each task MUST be tracked with status (pending/in-progress/completed)
- AND tasks MUST sync to Nextcloud Tasks via CalDAV VTODO (insight #26)
- AND overdue tasks MUST trigger notifications

#### Scenario: Report implementation progress at next meeting

- GIVEN a meeting with pending implementation items from previous decisions
- WHEN the chair reviews the implementation status
- THEN the system MUST show all open implementation tasks grouped by decision
- AND completion percentage MUST be visible per decision

---

### Requirement: Decision Notification and State Change Alerts [MVP]

The system MUST send notifications on every decision state change to relevant stakeholders (insight #31).

#### Scenario: Notify body members of new proposal

- GIVEN a decision transitioning from `draft` to `proposed`
- WHEN the transition occurs
- THEN all members of the governing body MUST receive a Nextcloud notification
- AND the notification MUST link directly to the decision detail view
- AND email notifications MUST be sent to external stakeholders without Nextcloud accounts (insight #28)

---

### Requirement: Decision ID and Numbering [MVP]

The system MUST assign a unique, sequential decision identifier per body and year (e.g., "RB-2026-042" for Raadsbesluit 42 of 2026).

#### Scenario: Auto-generate decision number

- GIVEN a decision being enacted for the municipal council in 2026
- WHEN the decision transitions to `enacted`
- THEN the system MUST assign the next sequential number in the format `{body_prefix}-{year}-{sequence}`
- AND the number MUST be permanently immutable once assigned

---

### Requirement: BOB Model Support (Beeldvorming-Oordeelsvorming-Besluitvorming) [V1]

The system SHOULD support the Dutch 3-phase decision model used by municipalities: Beeldvorming (information gathering), Oordeelsvorming (opinion formation), Besluitvorming (decision-making).

**Evidence**: BOB model used by Dutch municipalities including Hollands Kroon with separate phase meetings (source #337)

#### Scenario: Track decision through BOB phases

- GIVEN a council proposal entering the BOB process
- WHEN the proposal moves through committee (Beeldvorming), faction meetings (Oordeelsvorming), and plenary (Besluitvorming)
- THEN the system MUST track the current BOB phase
- AND the phase history MUST be visible on the decision detail view

---

### Requirement: Decision Archiving and Publication [V1]

The system SHOULD support archiving decisions per Archiefwet and publishing decision lists per Woo (Wet open overheid).

**Legal reference**: Woo Art. 3.3 requires active publication of decision lists, agendas, and meeting documents

#### Scenario: Publish decision list after council meeting

- GIVEN a council meeting with 12 decisions taken
- WHEN the griffier publishes the decision list
- THEN the system MUST generate a structured list with decision number, title, vote outcome, and date
- AND the list MUST be publishable to the public portal per Woo requirements

#### Scenario: Archive decision with retention schedule

- GIVEN an enacted decision with associated documents
- WHEN the archivaris initiates archiving
- THEN the system MUST apply the VNG selectielijst retention period
- AND all associated documents, audit trail, and voting records MUST be included

---

### Requirement: Collegial Decision-Making [Enterprise]

The system MAY support collegial decision models where a body acts as a collective (e.g., College van B&W).

#### Scenario: Record collegial B&W decision

- GIVEN a College van B&W meeting with 5 wethouders
- WHEN the college takes a decision collectively
- THEN the decision MUST be recorded as a collegial decision
- AND individual positions MUST NOT be publicly recorded (per collegialiteitsbeginsel)

---

### Requirement: Decision Support with AI [Enterprise]

The system MAY provide AI-augmented decision support using self-hosted LLM capabilities.

**Market context**: AI meeting tool usage grew 17x in 2024 (insight #5), no self-hosted AI meeting solution exists (insight #9)

#### Scenario: AI-generated decision summary

- GIVEN a decision that has gone through deliberation with extensive discussion
- WHEN the user requests an AI summary
- THEN the system MUST generate a concise summary of the discussion points, arguments for/against, and key considerations
- AND the AI MUST run on self-hosted infrastructure (privacy-first, GDPR-native)

---

### Requirement: Consent-Based Decision-Making [V1]

The system SHOULD support sociocratic consent-based decision-making with structured rounds.

**Evidence**: Consent process -- proposal, question round, reaction round, objection round, integration. Faster than consensus, more inclusive (source #256)

#### Scenario: Run consent decision process

- GIVEN a decision using consent-based method
- WHEN the facilitator initiates the consent rounds
- THEN the system MUST guide through: proposal presentation, question round, reaction round, objection round, integration
- AND "no objection" MUST count as consent (different from agreement)
- AND valid objections MUST be integrated into amended proposals

---

### Requirement: Decision Data Export and Interoperability [MVP]

The system MUST export decisions in standardized formats for interoperability.

#### Scenario: Export decision as OpenRaadsinformatie Besluit

- GIVEN an enacted council decision
- WHEN the system exports the decision
- THEN the export MUST conform to the OpenRaadsinformatie `Besluit` schema
- AND the export MUST include Akoma Ntoso-compatible document structure

#### Scenario: Export decision register as CSV/JSON

- GIVEN a set of decisions filtered by body and date range
- WHEN the user triggers export
- THEN the system MUST produce CSV or JSON with all decision metadata
- AND the export MUST include decision numbers, dates, outcomes, and links

---

## User Stories (from intelligence database)

### Legislative Domain

1. As a griffier, I want to publish the complete decision list after each council meeting so that citizens and media can immediately see what was decided. (DB #86, journey: Decision List Publication)
2. As a raadslid, I want to track how my faction voted on all decisions this term so that I can report to my constituents. (DB #96, journey: Voting Record Analysis)
3. As a voorzitter, I want to process all hamerstukken in one batch without debate so that meeting time is reserved for items requiring discussion. (DB #164, journey: Plenary Debate & Decision-Making)
4. As a burger, I want to set up keyword-based alerts for council decisions so that I am notified when topics that affect me are being discussed. (DB #208, journey: Meeting & Topic Notifications)
5. As an archivaris, I want to verify that all council meeting records (agenda, minutes, decisions, recordings, documents) are complete in the archive so that no gaps exist. (DB #183, journey: Records Archiving & Compliance)
6. As a commissievoorzitter, I want to formally record the committee advice on whether an item is ready for plenary decision so that the presidium can plan the plenary agenda. (DB #162, journey: Committee Information Gathering)
7. As a fractievoorzitter, I want to record the agreed faction position on each agenda item so that all members know how to vote. (DB #153, journey: Faction Position Coordination)
8. As a dijkgraaf, I want to chair the water board general assembly with proper quorum and voting rules so that decisions are legally valid. (DB #103, journey: Water Board General Assembly Meeting)

### Association Domain

9. As a secretary, I want to record decisions in real-time during the meeting with a structured format (decision text, type, vote, conditions) so that there is immediate clarity on what was decided. (DB #89)
10. As a chair, I want to circulate a proposal for written decision to all board members and collect their votes electronically so that urgent decisions can be made between meetings per BW 2:40. (DB #68)
11. As a chair, I want to track the implementation status of ALV decisions with responsible persons and deadlines so that I can report progress at the next ALV. (DB #77)
12. As a secretary, I want to verify that a statute amendment vote meets the required quorum and qualified majority so that the notary can confirm validity. (DB #59)
13. As a member, I want to cast my vote securely during the ALV so that my participation is equal to physical attendees. (DB #58)
14. As a kascommissie member, I want to present our audit findings and decharge recommendation to the ALV so that members can make an informed decision about board discharge. (DB #62)
15. As a board member, I want to formally declare a conflict of interest for a specific decision so that I am properly excluded per WBTR. (DB #69)
16. As a ledenraad member, I want to prepare my voting position with constituency input so that I can effectively represent my members. (DB #82)

### Corporate Governance Domain

17. As a board secretary, I want to create a structured decision proposal with options analysis, risk assessment, and financial impact so that the board can make well-informed strategic decisions. (DB #15)
18. As a supervisory board chair, I want to review strategic proposals and approve or reject them digitally so that governance oversight is exercised efficiently. (DB #16)
19. As a supervisory board chair, I want a digital workflow for approving major management decisions so that approvals can be obtained efficiently even outside scheduled meetings. (DB #25)
20. As a CFO, I want to review major budget requests with financial controller assessment so that the MT can make well-informed spending decisions. (DB #99)

### Corporate Operations Domain

21. As a CEO, I want to review the outcomes of decisions made in previous MT meetings so that we can assess decision quality and adjust course when needed. (DB #95)
22. As an employee, I want to search a central decision register by topic, date, meeting, or decision-maker so that I can find relevant past decisions and their rationale. (DB #129)
23. As an MT member, I want to selectively cascade specific decisions from the MT meeting to my department so that my team knows what was decided. (DB #94)
24. As a project manager, I want to prepare a steering committee meeting with decision items and risk overview so that the committee can make informed decisions efficiently. (DB #108)
25. As a meeting facilitator, I want to run consent-based decision processes with structured rounds so that decisions have broad support. (DB #346)

### Citizen Participation Domain

26. As a neighbourhood council member, I want decisions from our meetings to be formally tracked and reported to the municipality so that citizen input leads to action. (DB #247, journey: Run neighbourhood council meeting cycle)
27. As a coordinator, I want to track decisions from citizens assembly deliberation rounds so that the outcomes are structured and actionable. (DB #223)
28. As a citizen, I want to search for decisions, motions, and meeting minutes from the Nextcloud unified search bar so that I can find information without navigating multiple systems. (DB #1873)

### Cross-Domain (Nextcloud Integration)

29. As a secretary, I want action items from decisions to automatically become CalDAV VTODO tasks assigned to the responsible person so that nothing falls through the cracks. (DB #1836)
30. As a member, I want each major decision to have a linked Talk conversation for informal deliberation so that discussion can happen before the formal meeting. (DB #1851)
31. As any user, I want decisions to appear as embeddable references in Nextcloud Mail, Text, and Talk via Smart Picker so that I can reference decisions in context. (insight #30)
32. As a chair, I want to see decision pipelines as Deck boards with cards moving through stages automatically so that progress is visually clear. (insight #32)

## Evidence Sources

### Legal Standards (Mandatory)

| Standard | Scope | Key Requirements |
|----------|-------|-----------------|
| **Awb** (Algemene wet bestuursrecht) | All administrative decisions | Art. 1:3 defines 'besluit'; must be written with motivation |
| **Gemeentewet** | Municipal councils | Art. 20: quorum >50%; Art. 30: absolute majority; Art. 31: secret ballot for appointments |
| **BW Boek 2** (Burgerlijk Wetboek) | Legal entities (BV, NV, associations) | Art. 2:38: voting rights; Art. 2:230: majority/quorum; Art. 2:238: written procedure |
| **Woo** (Wet open overheid) | Government transparency | Art. 3.3: active publication of decision lists and meeting documents |
| **Provinciewet** | Provincial States | Similar to Gemeentewet for provincial level |
| **Waterschapswet** | Water boards | Oldest democratic institutions in NL with unique voting rules |

### Forum Standaardisatie Standards (Recommended)

| Standard | Relevance |
|----------|-----------|
| **BPMN 2.0** | Process modeling for decision workflows |
| **DMN** | Decision tables for configurable voting rules |
| **Akoma Ntoso** | XML representation of legislative documents and decisions |

### External Research & Market Evidence

- **$541B wasted on meetings globally** (Doodle analysis of 19M meetings) -- insight #1
- **AI meeting tool usage grew 17x in 2024** (Fellow State of Meetings 2024) -- insight #5
- **European participation market 300M EUR, e-voting 500M EUR** (expected within 5 years) -- insight #23
- **No competitor covers all 5 decision-making domains** -- market is completely siloed -- insight #7
- **No self-hosted AI meeting solution exists** -- Otter.ai and Fireflies.ai face class-action lawsuits over recording consent -- insight #9
- **Council of Europe CM/Rec(2017)5**: 49 standards for e-voting (secrecy, auditability, verifiability) -- source #258
- **POLYAS**: only BSI Common Criteria certified voting software -- source #270

### Competitor Features

| Competitor | Key Decision Features | Gap |
|-----------|----------------------|-----|
| **Notubiz/GO** | Council decisions, decision lists | No corporate/association governance |
| **iBabs** | Board portal, decision tracking | No formal voting, no legislative workflow |
| **Diligent** | Enterprise board governance | No legislative features, closed source |
| **Loomio** | 7+ voting types, async decisions | No formal governance compliance |
| **Decidim** | Participatory budgeting, e-voting | No corporate governance |
| **OpenSlides** | Assembly voting (4 modes) | No cross-domain governance |

### Tender Requirements

- **W-BESL** (gemeente zaaksysteem): "Beschrijf ondersteuning bestuurlijk besluitvormingsproces inclusief agendabeheer, vergaderingen, publiceren en archiveren" (70 pts)
- **W9** (zaaksysteem): "Beschrijf hoe de Oplossing het proces van bestuurlijke besluitvorming ondersteunt" (68 pts)
- **SGC 1** (RIS/BIS): "Ondersteuning bestuurlijke besluitvormingsketen" (30 pts)
- Multiple tenders require integration with iBabs for decision workflows

## Customer Journeys

### Legislative Domain
- **Plenary Debate & Decision-Making (Besluitvorming)** -- Voting on proposals, motions, amendments in council
- **Decision List Publication** -- Compiling and publishing decisions after meetings
- **Voting Record Analysis** -- Analyzing voting patterns across meetings and topics
- **Committee Information Gathering (Beeldvorming)** -- Committee phase of BOB model
- **Presidium Agenda Setting** -- Classifying items as hamerstuk/bespreekstuk

### Association Domain
- **ALV Regular Voting** -- Simple majority votes on regular agenda items
- **ALV Qualified Majority Voting (Statute Amendments)** -- 2/3 majority with quorum
- **Board Discharge (Decharge) Voting** -- Annual discharge vote based on kascommissie report
- **Proxy Vote Management** -- Digital proxy (volmacht) management
- **Written Board Resolution (Outside Meeting)** -- BW 2:40 written procedure
- **Decision Implementation Tracking** -- Tracking implementation with responsible persons

### Corporate Governance Domain
- **Supervisory Board Oversight & Approval** -- Digital workflows for major decisions
- **Board Conflict of Interest Declaration** -- WBTR-compliant conflict management
- **AGM Minutes & Legal Documentation** -- Notarial minutes and resolution filing

### Corporate Operations Domain
- **MT Decision Making During Meeting** -- Real-time decision recording with structured format
- **MT Recurring Review Cycle** -- Reviewing outcomes of previous decisions
- **Steering Committee Meeting** -- Project decision escalation
- **Decision Register Search and Reference** -- Central searchable decision register
- **Meeting Efficiency & Analytics** -- Decision rate, time-to-decision KPIs

### Citizen Participation Domain
- **Organize a citizens assembly** -- Structured deliberation to decision
- **Run neighbourhood council meeting cycle** -- Community-level decision tracking

## Acceptance Criteria

1. Decisions are stored as OpenRegister objects with `@type` of `schema:ChooseAction`
2. State machine enforces valid transitions only (Symfony Workflow Component)
3. State machine configuration is defined in YAML and customizable per body
4. Frontend state machine visualization uses XState with @xstate/vue integration
5. All transitions are recorded in an immutable audit trail
6. Decision list supports search, sort, and filter by status/body/date/type
7. Detail view uses CnDetailPage + CnObjectSidebar with state machine visualization
8. OpenRaadsinformatie `Besluit` mapping is available for each decision
9. Voting supports simple majority, qualified majority, and secret ballot
10. Quorum is verified before voting can begin
11. Written decision procedure (buiten vergadering) is supported per BW 2:238
12. Decision numbers are auto-generated in format `{body_prefix}-{year}-{sequence}`
13. Notifications fire on every state change to relevant stakeholders
14. Decision lists are publishable per Woo requirements
15. Tie-breaking follows Gemeentewet Art. 30 (rejection) and Art. 31 (lot for persons)
16. Conflict of interest declarations are tracked per WBTR
17. Implementation tasks sync to Nextcloud Tasks via CalDAV VTODO
18. Decisions are searchable from Nextcloud unified search
19. Audit trail is exportable for compliance reporting
20. BOB model phases are trackable for legislative decisions
21. Decision-by-silence is supported with configurable objection periods
22. Consent-based decision rounds are supported (sociocratic model)
23. Decision data is exportable as CSV, JSON, and OpenRaadsinformatie XML
24. Collegial decisions (B&W model) record collective outcome without individual positions
25. AI decision summaries run on self-hosted infrastructure only

## Notes

### Open Questions

- What is the minimum viable set of voting methods for MVP? (Simple majority + qualified majority + secret ballot recommended)
- Should we implement full E2E verifiable voting (homomorphic encryption, zero-knowledge proofs) or defer to V2? Council of Europe standard has 49 requirements.
- How to handle the transition when WDAV (Wet Digitale Algemene Vergadering) passes the Eerste Kamer? Expected impact on digital voting requirements.

### Legal Risks

- E-voting security is complex and regulated (insight #21). POLYAS is the only BSI-certified system. Start with internal governance voting, not public elections.
- Otter.ai and Fireflies.ai face class-action lawsuits over recording without consent -- self-hosted approach mitigates this risk.
- BW 2:238 written procedure requires UNANIMOUS consent to the method -- the system must enforce this strictly.

### Technical Decisions

- Use Symfony Workflow (PHP) for backend state machine, XState (JS) for frontend visualization (insights #11, #12)
- Avoid full BPMN engines (Camunda, Flowable) -- Java-based, significant infrastructure overhead (insight #14)
- Use DMN-inspired decision tables for configurable voting rules (insight #13)
- Smart Picker / References integration for embedding decisions in other Nextcloud apps is low-effort, high-value (insight #30)
