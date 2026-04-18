# Context Brief: Motion and Voting

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p2-motion-and-voting
**Platform:** Nextcloud + OpenRegister

**Depends on:** p1-schemas-and-data-model, p1-dashboard-and-navigation, p1-crud-operations

## Dependency Specs (content)

These specs were already decided/implemented. Use them as context.

### p1-schemas-and-data-model
# Context Brief: Schemas and Data Model

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p1-schemas-and-data-model
**Platform:** Nextcloud + OpenRegister

## Features (6 total, sorted by market demand)

### Resolution Register
**demand: 206** (66 tender mentions) | Category: core

### Identity Governance Management
**demand: 91** (27 tender mentions) | Category: governance

### Register to speak at committee meeting
**demand: 56** (18 tender mentions) | Category: core

### Attendance Register
**demand: 26** (6 tender mentions) | Category: other

### Voter Register
**demand: 26** (6 tender mentions) | Category: other

### Link emails to specific decisions via OpenRegister _mail metadata
**demand: unknown** | Category: other

## User Stories (6 linked)

### Story 1: Configure SAML 2.0 identity provider
**Priority:** should
As an IAM administrator, I want to configure a SAML 2.0 identity provider using a metadata XML file, so that I can integrate with government identity federations that do not support OIDC.

**Acceptance Criteria:**
GIVEN the IdP configuration screen WHEN I upload a SAML metadata XML file THEN the entity ID, SSO URL, and signing certificate are parsed and pre-filled
GIVEN a saved SAML configuration WHEN I download the OpenRegister service provider metadata THEN I receive a valid SAML metadata XML I can import into the identity provider

### Story 2: View provenance and update metadata for a dataset
**Priority:** should
As a researcher, I want to view the provenance metadata of a register dataset including its source organisation, data steward, creation date, and last update timestamp, so that I can assess whether the dataset is authoritative and current enough for my research.

**Acceptance Criteria:**
GIVEN a public register WHEN I open the dataset information page THEN I see the responsible organisation (OIN), data steward contact, creation date, last modified timestamp, and the update frequency commitment
GIVEN the dataset page THEN it links to the processing register entry and any applicable open data licence

### Story 3: Link Nextcloud Mail emails to invoices
**Priority:** must-have
As a Nextcloud user, I want to link emails from Nextcloud Mail to invoice/expense records using OpenRegister _mail metadata, creating a native email-to-invoice connection.

### Story 4: Link to Nextcloud Contacts
**Priority:** must-have
As a Nextcloud user, I want invoicing contacts to sync with Nextcloud Contacts via OpenRegister _contacts metadata, so I have one unified address book.

### Story 5: Link emails to specific decisions via OpenRegister _mail metadata
**Priority:** must
As a decision maker, I want emails related to a decision to be automatically linked via the _mail metadata column so that all correspondence is part of the decision dossier and visible in the Mail app sidebar.

**Acceptance Criteria:**
GIVEN an email mentioning a decision reference number WHEN the email is received THEN it appears linked to the decision object in OpenRegister AND is visible in the Nextcloud Mail sidebar

### Story 6: Unsubscribe directly from email
**Priority:** must
As a subscriber, I want to unsubscribe from a mailing list by clicking a single link in the email footer, so that I stop receiving unwanted emails immediately.

**Acceptance Criteria:**
Unsubscribe link visible in email footer; single click completes unsubscribe (no login required); confirmation page shown; no further emails sent from that list

## Customer Journeys (15 linked)

### AV & Webcast Infrastructure Management
Managing AV infrastructure in raadzaal and commissiekamers. Discussion systems, PTZ cameras, AV control, webcast/streaming, microphone management.
**Trigger:** Meeting scheduled requiring AV support; system maintenance/upgrade
**Desired outcome:** Reliable AV infrastructure supporting meetings with high-quality recording and streaming
**Current pain:** Complex multi-vendor systems; AV-RIS integration for indexing; maintenance windows; high costs; rapid tech evolution
**Frequency:** Every meeting (setup/support) + periodic maintenance

### Handle Complex Multi-Domain Citizen Question
A citizen has a question spanning multiple domains (e.g., housing benefit, parking permit, and social assistance). The front desk officer creates linked zaken or a combined intake and routes each to the correct department.
**Trigger:** Citizen presents with multiple interconnected service needs in a single interaction
**Desired outcome:** All needs are captured, routed, and tracked under a single citizen profile; no request is lost or duplicated
**Current pain:** Systems do not support grouped intake; officer must create each zaak separately and manually inform all receiving departments
**Frequency:** weekly

### Meeting Recording Publication
Video and audio recordings processed, indexed (linked to agenda items), captioned, and published in RIS for on-demand v
... (truncated)

### p1-dashboard-and-navigation
# Context Brief: Dashboard and Navigation

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p1-dashboard-and-navigation
**Platform:** Nextcloud + OpenRegister

## Features (26 total, sorted by market demand)

### Search across all council information
**demand: 814** (271 tender mentions) | Category: other

### Search Call & Meeting Management
**demand: 795** (265 tender mentions) | Category: core

### View Call & Meeting Management Overview
**demand: 741** (247 tender mentions) | Category: core

### Search within Council Document Publication
**demand: 657** (219 tender mentions) | Category: document-management

### View Council Document Publication overview
**demand: 594** (198 tender mentions) | Category: document-management

### View Draft Permit Decision Review overview
**demand: 525** (175 tender mentions) | Category: core

### Search within Legal Advice on Decision
**demand: 331** (110 tender mentions) | Category: core

### Search voting history by topic or member
**demand: 310** (102 tender mentions) | Category: core

### Search within Council Question Response
**demand: 258** (86 tender mentions) | Category: other

### View Legal Advice on Decision overview
**demand: 241** (78 tender mentions) | Category: core

### Search within Aesthetics Committee Meeting
**demand: 231** (77 tender mentions) | Category: core

### Accessibility Optimization with H1 Structure and Global Search
**demand: 224** (74 tender mentions) | Category: core

### View Council Question Response overview
**demand: 144** (48 tender mentions) | Category: other

### View organizational meeting cost dashboard
**demand: 132** (23 tender mentions) | Category: core

### Dashboard: Long meeting titles are abbreviated on the dashboard.
**demand: 130** (23 tender mentions) | Category: core

### View dashboard of all motions
**demand: 111** (13 tender mentions) | Category: analytics

### Search Discount Governance
**demand: 102** (32 tender mentions) | Category: governance

### View Aesthetics Committee Meeting overview
**demand: 93** (31 tender mentions) | Category: core

### Search Multi-Channel Support Resolution
**demand: 90** (30 tender mentions) | Category: core

### Search Complex Technical Issue Resolution
**demand: 87** (29 tender mentions) | Category: core

### Participant Overview Dashboard
**demand: 81** (7 tender mentions) | Category: analytics

### View Discount Governance Overview
**demand: 45** (15 tender mentions) | Category: governance

### Export compliance overview report for VNG governance
**demand: 39** (13 tender mentions) | Category: governance

### App dashboard
**demand: unknown** | Category: dashboard and navigation
Overview of upcoming meetings, pending motions, recent decisions

### NL Design System theming
**demand: unknown** | Category: dashboard and navigation
CSS custom property support for government theming

### Search integration
**demand: unknown** | Category: dashboard and navigation
Full-text search across meetings, motions, and decisions via OpenRegister

## User Stories (340 linked)

### Story 1: Cost Calculation
**Priority:** wont
As a meeting organizer, I want to have cost calculation capabilities, so that the platform meets diverse organizational needs.

### Story 2: ai Powered Governance
**Priority:** wont
As a meeting participant, I want to have ai-powered governance capabilities, so that meeting insights are captured automatically without manual effort.

### Story 3: Legal Compliance Framework
**Priority:** wont
As a compliance officer, I want to have legal compliance framework capabilities, so that organizational data and processes remain secure and compliant.

### Story 4: Cybersecurity Governance
**Priority:** wont
As a compliance officer, I want to have cybersecurity governance capabilities, so that organizational data and processes remain secure and compliant.

### Story 5: Governance Compliance
**Priority:** wont
As a compliance officer, I want to have governance compliance, so that organizational data and processes remain secure and compliant.

### Story 6: Legal Compliance
**Priority:** wont
As a compliance officer, I want to have legal compliance, so that organizational data and processes remain secure and compliant.

### Story 7: Open Source Governance
**Priority:** wont
As a IT administrator, I want to have open source governance capabilities, so that the organization maintains control over its data and infrastructure.

### Story 8: ai Enhanced Governance
**Priority:** wont
As a meeting participant, I want to have ai-enhanced governance capabilities, so that meeting insights are captured automatically without manual effort.

### Story 9: ai Governance Automation
**Priority:** wont
As a meeting participant, I want to have ai governance automation capabilities, so that meeting insights are captured automatically without manual effort.

### Story 10: ai Governance Oversight
**Priority:** wont

... (truncated)

### p1-crud-operations
# Context Brief: CRUD Operations

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p1-crud-operations
**Platform:** Nextcloud + OpenRegister

## Features (49 total, sorted by market demand)

### PO amendment management with version history and re-approval workflow
**demand: 2450** (814 tender mentions) | Category: core

### Chargeback management and dispute resolution
**demand: 1651** (549 tender mentions) | Category: core

### Peppol participant identifier registration and management
**demand: 1613** (537 tender mentions) | Category: other

### Coupon and promotion code management for subscriptions
**demand: 1592** (530 tender mentions) | Category: other

### Agenda management
**demand: 1262** (420 tender mentions) | Category: core

### Access Call & Meeting Management on Mobile
**demand: 815** (261 tender mentions) | Category: core

### Decision Management
**demand: 805** (266 tender mentions) | Category: core

### Generate Call & Meeting Management Report
**demand: 770** (256 tender mentions) | Category: core

### Export Call & Meeting Management Data
**demand: 747** (249 tender mentions) | Category: core

### Filter Call & Meeting Management Items
**demand: 735** (245 tender mentions) | Category: core

### Create a standardized council proposal
**demand: 734** (241 tender mentions) | Category: other

### Create a formal contract amendment
**demand: 659** (219 tender mentions) | Category: core

### Campaign Management and Budgeting Acceleration
**demand: 598** (199 tender mentions) | Category: other

### Create and publish meeting agenda
**demand: 549** (183 tender mentions) | Category: core

### Meetings Management
**demand: 547** (96 tender mentions) | Category: other

### attendance management
**demand: 547** (96 tender mentions) | Category: other

### Participant Management
**demand: 524** (96 tender mentions) | Category: other

### Meeting Management
**demand: 520** (105 tender mentions) | Category: core

### such as: meeting management
**demand: 520** (105 tender mentions) | Category: core

### post-meeting task management
**demand: 491** (140 tender mentions) | Category: core

### Create structured decision proposal
**demand: 486** (128 tender mentions) | Category: core

### Board Assessments and Meeting Management
**demand: 485** (140 tender mentions) | Category: core

### ESG Management and Governance Integration
**demand: 484** (161 tender mentions) | Category: governance

### Board Meeting Management Tools
**demand: 482** (144 tender mentions) | Category: core

### Resolution Management
**demand: 473** (153 tender mentions) | Category: core

### meeting management system
**demand: 469** (105 tender mentions) | Category: core

### intuitive meeting management
**demand: 467** (105 tender mentions) | Category: core

### Board Meeting Coordination and Organization
**demand: 446** (132 tender mentions) | Category: core

### Collaborative Agenda Creation
**demand: 441** (132 tender mentions) | Category: core

### Agenda and Meeting Management
**demand: 440** (132 tender mentions) | Category: core

### Compliance-Focused Meeting Management
**demand: 424** (122 tender mentions) | Category: core

### Create execution tasks when motion/decision is adopted
**demand: 420** (140 tender mentions) | Category: core

### Meeting Materials Management
**demand: 372** (105 tender mentions) | Category: core

### Motion Management
**demand: 338** (107 tender mentions) | Category: core

### Proxy Contest Management
**demand: 298** (96 tender mentions) | Category: other

### Escalate rejection decision to manager
**demand: 221** (73 tender mentions) | Category: core

### Create and manage AGM agenda
**demand: 213** (32 tender mentions) | Category: core

### Centralized Board Member Agenda Management Environment
**demand: 137** (44 tender mentions) | Category: other

### Budget Decision to Strategic Outcome Linking
**demand: 61** (20 tender mentions) | Category: other

### Manage provincial states meeting cycle
**demand: 31** (10 tender mentions) | Category: core

### Persistent Virtual Meeting Rooms with Custom Layouts
**demand: 31** (10 tender mentions) | Category: core

### Manage committee speaking order
**demand: 26** (2 tender mentions) | Category: other

### Manage committee with clear mandate
**demand: 26** (2 tender mentions) | Category: other

### Governance and content management
**demand: 2** | Category: Administration
Content governance with certified data sources, permissions, and usage analytics

### Participant Data Management
**demand: 2** | Category: Case Management
Import and export participant data and documents across EU systems

### Create and manage faction meetings
**demand: 1** | Category: other

### Manage large sets of amendments
**demand: unknown** | Category: other

### Meeting CRUD
**demand: unknown** | Category: crud operations
Create, read, update, delete meetings via OpenRegister API

### Go
... (truncated)

## Features (17 total, sorted by market demand)

### Budget amendment motions
**demand: 2025** | Category: motion and voting
Motions to amend budget proposals with financial impact tracking

### Proxy voting
**demand: 1242** | Category: motion and voting
Delegate voting rights to another participant

### governance quality proxy
**demand: 290** (93 tender mentions) | Category: governance

### Audit contract amendments for mandate compliance
**demand: 179** (59 tender mentions) | Category: governance

### Amendment workflow
**demand: 140** | Category: motion and voting
Submit, debate, and vote on amendments to motions

### Quorum checking
**demand: 140** | Category: motion and voting
Verify sufficient participants before opening a vote

### Voting result publication
**demand: 140** | Category: motion and voting
Display results and publish to ORI API

### Motion status tracking
**demand: 140** | Category: motion and voting
Track motion lifecycle through workflow states

### Voting round management
**demand: 140** | Category: motion and voting
Open/close voting rounds, set voting method (for/against/abstain, ranked choice, weighted)

### Vote casting and tallying
**demand: 140** | Category: motion and voting
Real-time vote collection and automatic result calculation

### Proxy Processing Integration
**demand: 84** (16 tender mentions) | Category: integration

### Poll Participant Verification Audit for Anonymity Protection
**demand: 77** (25 tender mentions) | Category: governance

### Motion Guidelines
**demand: 53** (17 tender mentions) | Category: Design System
Consistent animation and transition patterns

### Proxy Access Mechanisms
**demand: 49** (15 tender mentions) | Category: security

### 48-hour Resolution
**demand: 2** | Category: Performance
80% of reports handled within 48 hours in reference implementations.

### Feedback on Resolution
**demand: 2** | Category: Citizen Engagement
Citizens can provide feedback once their reported issue is resolved.

### Public Ballot Total Verification
**demand: 1** | Category: participation

## User Stories (33 linked)

### Story 1: Voting/polling
**Priority:** wont
As a meeting organizer, I want to have voting/polling capabilities, so that decisions are made fairly and transparently.

### Story 2: Proxy Voting
**Priority:** wont
As a meeting organizer, I want to have proxy-voting capabilities, so that decisions are made fairly and transparently.

### Story 3: Global Proxy Voting
**Priority:** wont
As a corporate secretary, I want to have global proxy voting capabilities, so that shareholders are engaged and properly informed.

### Story 4: Bylaw Amendment Voting
**Priority:** wont
As a council clerk, I want to have bylaw amendment voting capabilities, so that legislative actions follow proper procedure with full traceability.

### Story 5: Motion Voting
**Priority:** wont
As a meeting organizer, I want to have motion voting capabilities, so that decisions are made fairly and transparently.

### Story 6: Comment and vote on open extension proposals
**Priority:** should
As a Standards Committee Member, I want to comment on and cast a formal vote on open proposals, so that the committee can reach consensus asynchronously.

**Acceptance Criteria:**
GIVEN a proposal is in 'open for review' status
WHEN I add a comment and select a vote (Approve / Request changes / Abstain)
THEN the comment and vote are recorded under my authenticated identity and the aggregate vote tally is updated

### Story 7: Bulk proxy voting across multiple AGMs
**Priority:** should
As an institutional investor, I want to manage proxy voting across all portfolio company AGMs from a single dashboard, so that I can efficiently exercise my voting rights at scale.

**Acceptance Criteria:**
["Dashboard showing all upcoming AGMs with deadlines", "Bulk voting based on voting policy", "SRD II compliance reporting"]

### Story 8: Collect motion co-signatories digitally
**Priority:** should
As a raadslid, I want to digitally request and collect co-signatures for my motion from other council members, so that I can quickly gather the required support

### Story 9: Detect conflicting amendments
**Priority:** should
As a griffier, I want to be alerted when multiple amendments target the same text passage, so that I can advise the chair on voting order

### Story 10: Conduct Ranked Preference Poll for Citizen Panel
**Priority:** should
As a participation coordinator, I want citizens to rank their preferred options so that we find solutions with broadest community support.

**Acceptance Criteria:**
["Multi-option ballot with descriptions", "Drag-and-drop ranking on mobile and desktop", "Instant runoff calculation", "Results show elimination rounds visually", "Multi-language ballot support (NL/EN minimum)", "WCAG AA accessible voting interface"]

### Story 11: Cast vote by email reply (For/Against/Abstain)
**Priority:** should
As a member who cannot attend the meeting, I want to cast my vote by replying to the voting notification email with "For", "Against", or "Abstain" so that my vote is counted without needing the platform.

**Acceptance Criteria:**
GIVEN a voting notification email WHEN the member replies with a valid vote keyword THEN the vote is registered in OpenRegister AND a confirmation reply is sent

### Story 12: Automatic calendar entries for voting deadlines and comment periods
**Priority:** should
As a stakeholder, I want voting deadlines, amendment submission deadlines, and public comment periods to automatically appear in my calendar so that I never miss a deadline.

**Acceptance Criteria:**
GIVEN a decision or motion has a deadline date WHEN the deadline is set or changed THEN a calendar event is created/updated via _calendar metadata AND a reminder is set 48 hours before the deadline

### Story 13: Submit a budget amendment motion (motie)
**Priority:** should
As a city council member, I want to propose a budget amendment motion within the system and link it to specific budget lines, so that finance can immediately calculate the impact of my proposal.

**Acceptance Criteria:**
GIVEN I am in the council review screen WHEN I submit a motion THEN I can specify the lines to change, the amounts, and a policy rationale
GIVEN the motion is submitted WHEN the financial controller opens the motion list THEN they see the calculated budget impact immediately

### Story 14: Track resolution voting outcomes
**Priority:** must
As a shareholder, I want to see the voting results for each resolution immediately after the vote, so that I know which decisions have been adopted.

**Acceptance Criteria:**
["Per-resolution vote tally (for/against/abstain/not voted)", "Majority threshold indicator", "Results published to shareholder portal"]

### Story 15: Submit proxy vote digitally
**Priority:** must
As a shareholder, I want to submit my proxy vote digitally for each resolution item, so that my vote is counted even though I cannot attend the AGM in person.

**Acceptance Criteria:**
["Per-resolution voting (for/against/abstain)", "Digital identity verification", "Confirmation receipt for submitted vote"]

### Story 16: Conduct live voting at AGM
**Priority:** must
As a board secretary, I want to conduct live voting during the AGM with real-time results, so that resolutions are passed transparently and efficiently.

**Acceptance Criteria:**
["Real-time vote counting with majority calculation", "Quorum verification before voting opens", "Support for show of hands and poll voting"]

### Story 17: Read full proposal detail before voting
**Priority:** must
As a citizen respondent, I want to open the full detail page of a proposal including the feasibility review summary, so that I can understand what I am voting for before committing my votes.

**Acceptance Criteria:**
GIVEN I am on the voting overview page
WHEN I click on a proposal
THEN I see the full description, attachments, location map, estimated cost, and a plain-language summary of the feasibility verdict
AND I can add the proposal to my ballot from this page

### Story 18: Automatic dossier folder structure per decision with all related documents
**Priority:** must
As a records manager, I want each decision to have an automatically structured folder in Nextcloud Files (via _files metadata) containing all related documents (proposal, amendments, voting results, minutes extract) so that the complete dossier is always available.

**Acceptance Criteria:**
GIVEN a new decision is created WHEN documents are attached at any stage THEN they are organized in a structured folder AND the folder is linked to the decision via _files AND full-text search indexes all content

### Story 19: Vote in Local Referendum
**Priority:** must
As a citizen, I want to vote yes/no on a referendum question so that my voice directly influences municipal policy.

**Acceptance Criteria:**
["Identity verification (DigiD or equivalent)", "Clear referendum question and explanation", "Simple yes/no/abstain ballot", "Voting period with clear open/close times", "Turnout threshold enforcement", "Results published with full transparency"]

### Story 20: Complete audit trail via Activity app for all decision lifecycle events
**Priority:** must
As an auditor, I want every action on a decision (creation, state change, vote cast, document added, amendment submitted) to be logged in the Nextcloud Activity stream so that there is a tamper-evident audit trail.

**Acceptance Criteria:**
GIVEN any action is performed on a decision object WHEN the action is saved THEN an Activity entry is created with actor, action, timestamp, and before/after state AND the activity stream is filterable by decision AND exportable for compliance reporting

## Customer Journeys (15 linked)

### Offboard Supplier
The officer deactivates a supplier record following contract termination, insolvency, or integrity disqualification, ensuring no new orders can be placed and that the supplier is flagged appropriately for future reference.
**Trigger:** The last active contract with a supplier expires or is terminated, or a supplier fails an integrity check.
**Desired outcome:** The supplier is cleanly deactivated with a documented reason, preventing inadvertent use while preserving the historical record for audit purposes.
**Current pain:** Supplier deactivation is often neglected; inactive suppliers remain available in procurement systems for years, creating compliance and fraud risks.
**Frequency:** monthly

### Review Data Retention for Privacy Compliance
A privacy officer reviews whether documents containing personal data are not retained beyond their AVG-permitted retention period, and initiates deletion or anonymisation where required.
**Trigger:** Annual privacy audit, data register review, or a complaint from a data subject
**Desired outcome:** All documents with personal data are verified against their retention basis; overdue documents are anonymised or deleted with a logged justification
**Current pain:** No link between the document retention schedule and the privacy/AVG retention basis; privacy and records management operate in silos with no shared tooling
**Frequency:** yearly

### AV & Webcast Infrastructure Management
Managing AV infrastructure in raadzaal and commissiekamers. Discussion systems, PTZ cameras, AV control, webcast/streaming, microphone management.
**Trigger:** Meeting scheduled requiring AV support; system maintenance/upgrade
**Desired outcome:** Reliable AV infrastructure supporting meetings with high-quality recording and streaming
**Current pain:** Complex multi-vendor systems; AV-RIS integration for indexing; maintenance windows; high costs; rapid tech evolution
**Frequency:** Every meeting (setup/support) + periodic maintenance

### Provincial States Meeting Cycle
Provincial States follow similar BOB model but larger scale. CdK chairs, griffier supports, factions coordinate through presidium. State proposals through statencommissies before statendag.
**Trigger:** Monthly meeting cycle of provincial states
**Desired outcome:** Provincial policy decisions made through structured committee and plenary process
**Current pain:** Similar to municipal pain points but larger scale; more formal procedures
**Frequency:** Monthly (statendag) plus committee meetings

### Manage Signing Mandate
A department manager or authorised signatory registers, updates, or revokes signing mandates (mandaatregisters) within the DMS, controlling which roles may sign which document types.
**Trigger:** Organisational change, new mandate decision, or periodic mandate review
**Desired outcome:** Mandate register is up to date, enforced automatically by the signing workflow, and auditable
**Current pain:** Mandate registers are maintained as static documents or spreadsheets, not enforced technically — anyone can send a document for signature
**Frequency:** quarterly

### Detect and Flag Sensitive Personal Data in Documents
The DMS automatically scans newly registered documents for sensitive personal data (BSN, medical data, financial data) and flags them for review by the privacy officer.
**Trigger:** Document is registered or uploaded to the DMS
**Desired outcome:** Documents containing sensitive data are automatically classified with the correct privacy label, access is restricted to authorised roles, and the privacy officer is notified
**Current pain:** Privacy classification is entirely manual; sensitive documents end up in broadly accessible folders without any data-driven safety net
**Frequency:** daily

### Presidium Agenda Setting
The presidium (faction leaders + chair) meets to set the agenda. Decides which items go to which committee, hamerstuk vs bespreekstuk, and overall meeting planning.
**Trigger:** Scheduled presidium meeting at start of each meeting cycle
**Desired outcome:** Approved agenda with clear assignment of items to committees and plenary
**Current pain:** Manual compilation of agenda proposals; difficulty balancing workload across committees
**Frequency:** Every meeting cycle (approximately every 3-4 weeks)

### Faction Position Coordination
Faction leader coordinates positions on upcoming agenda items through faction meetings. Distributes preparatory work, aligns voting positions, assigns spokespersons.
**Trigger:** Publication of agenda for upcoming council/committee meeting
**Desired outcome:** Aligned faction positions on all agenda items, assigned spokespersons, coordinated motion/amendment strategy
**Current pain:** Fragmented communication tools; no shared workspace; difficulty tracking faction-wide positions across multiple agenda items
**Frequency:** Weekly during active meeting periods

### Water Board General Assembly Meeting
Water board general assembly meets at least 6x/year. Sets policy, approves budgets, oversees executive board. Chaired by dijkgraaf. Secretary-director maintains minutes.
**Trigger:** Scheduled general assembly meeting (minimum 6x/year)
**Desired outcome:** Policy decisions made, budgets approved, oversight exercised, complete minutes maintained
**Current pain:** Similar needs as municipalities but different governance; fewer commercial RIS options; unique stakeholder categories
**Frequency:** Minimum 6 times per year (general assembly); bi-weekly (executive board)

### RIS Platform Migration & Transition
Migrating from one RIS to another including data migration of historical records, user transition, integration reconfiguration, and training.
**Trigger:** Contract expiration, dissatisfaction with current RIS, or need for new features
**Desired outcome:** Successful migration with complete data transfer, minimal disruption, and satisfied users
**Current pain:** Complex data migration from proprietary formats; risk of data loss; user resistance; training needs; vendor lock-in
**Frequency:** Every 4-8 years (contract cycles)

### Decision Execution & Follow-up
After council makes a decision, the executive is responsible for executing it. Includes implementing policy changes, allocating budgets, and reporting back to council.
**Trigger:** Council adopts a proposal, motion, or amendment requiring executive action
**Desired outcome:** Decision is fully implemented and the council is informed of completion
**Current pain:** Difficulty linking decisions to implementation; no automated tracking; reporting often delayed; unclear accountability
**Frequency:** Continuous (each adopted decision requires follow-up)

### System Integration & Data Exchange
Managing integrations between RIS and other systems: zaaksysteem, DMS, AV/webcast, archiving, PLOOI platform.
**Trigger:** New system implementation, upgrade, or integration requirement
**Desired outcome:** Seamless data flow between RIS and connected systems with no manual re-entry
**Current pain:** Legacy APIs; proprietary interfaces; vendor lock-in; complex AV integration; multiple auth systems
**Frequency:** Ongoing (initial setup + continuous maintenance)

### Meeting Recording Publication
Video and audio recordings processed, indexed (linked to agenda items), captioned, and published in RIS for on-demand viewing.
**Trigger:** Completion of a recorded meeting
**Desired outcome:** Indexed, accessible recordings published and linked to relevant agenda items in the RIS
**Current pain:** Long processing times; manual indexing; accessibility requirements (captions); large file storage
**Frequency:** After every recorded meeting

### Open Data Publication (ORI)
Publishing council information as open data following the ORI standard. API access, linked data, integration with PLOOI. Over 100 Dutch municipalities participate.
**Trigger:** New meeting data or documents are published in the RIS
**Desired outcome:** Council data available as structured open data via standardized API, compliant with ORI and Woo
**Current pain:** Vendor-specific connectors required; inconsistent data quality; evolving standard; limited real-time capability
**Frequency:** Continuous (automated sync)

### Run Ad-Hoc Analytical Query
The data science lead executes exploratory queries against the data warehouse to validate a hypothesis or investigate an anomaly spotted in a dashboard.
**Trigger:** An unexpected pattern appears in a scheduled report or a policy question requires deep-dive analysis.
**Desired outcome:** Fast, governed self-service query execution with results visualised and shareable without leaving the platform.
**Current pain:** Direct database access requires IT tickets; analysts write raw SQL against production databases, raising governance and performance concerns.
**Frequency:** weekly

## Stakeholders (110 linked)

### Board Secretary / Company Secretary
Corporate governance professional who manages all governance processes, board meetings, minutes, compliance, and stakeholder communication. Guardian of governance procedures and compliance.
**Responsibilities:** Preparing board packs and agendas, taking and distributing minutes, managing governance calendar, ensuring quorum, advising on governance procedures, maintaining corporate registers, coordinating AGM logistics, filing with KVK/AFM
**Pain points:** Manual board pack assembly, version control of sensitive documents, tracking action items across multiple boards and committees, ensuring timely distribution, managing multiple meeting calendars, paper-based minute signing processes
**Goals:** Digital board portal with secure document distribution, automated meeting scheduling and reminders, electronic minute approval workflows, integrated governance calendar, real-time action item tracking

### Supervisory Board Chair
Chair of the supervisory board (Raad van Commissarissen) in Dutch two-tier governance model. Leads supervisory oversight, sets RvC agenda, and maintains relationship with management board.
**Responsibilities:** Chairing supervisory board meetings, setting RvC agenda, appointing committee chairs, leading board evaluations, maintaining dialogue with CEO and shareholders, ensuring proper governance, managing conflicts of interest within RvC
**Pain points:** Limited visibility into management decisions between meetings, difficulty coordinating dispersed supervisory board members, managing information asymmetry with management board, ensuring all members are adequately informed
**Goals:** Secure digital workspace for supervisory board, real-time access to management information, efficient virtual meeting capabilities, structured oversight and approval workflows

### Supervisory Board Member
Individual member of the supervisory board. Provides oversight, advice, and approval on management decisions. May serve on audit, remuneration, or nomination committees.
**Responsibilities:** Attending supervisory board and committee meetings, reviewing management decisions, approving major transactions and appointments, monitoring risk management, evaluating management board performance
**Pain points:** Information overload from board packs, limited time for preparation, difficulty accessing documents on mobile devices, lack of annotation and collaboration tools, managing multiple board memberships
**Goals:** Mobile-friendly board portal with offline access, smart document summaries, annotation and note-taking tools, secure communication with fellow board members, clear voting and resolution tracking

### Shareholder
Owner of shares in a BV or NV. Has voting rights at the Algemene Vergadering van Aandeelhouders (AVA/AGM). Dutch law provides specific rights for minority shareholders.
**Responsibilities:** Attending and voting at AVA/AGM, approving annual accounts, appointing/dismissing directors, approving statute amendments, exercising inquiry rights (enquêterecht)
**Pain points:** Complex proxy voting processes, lack of transparency in pre-meeting information, difficulty participating in hybrid/digital meetings, limited engagement between AGMs, unclear resolution outcomes
**Goals:** Easy digital proxy voting, transparent access to meeting agendas and documents, real-time participation in hybrid AGMs, clear dividend and resolution information, accessible corporate governance information

### Institutional Investor
Large-scale investor (pension fund, asset manager, insurance company) with significant shareholdings. Subject to Shareholder Rights Directive II (SRD II) stewardship obligations.
**Responsibilities:** Stewardship and engagement with portfolio companies, proxy voting across hundreds of AGMs, ESG assessment, compliance with SRD II disclosure requirements, filing substantial holdings notifications
**Pain points:** Managing proxy voting at scale across many companies, reliance on proxy advisors (ISS/Glass Lewis), cross-border voting complexity, meeting SRD II engagement and disclosure requirements, lack of standardized governance data
**Goals:** Automated proxy voting workflows, integrated ESG governance scoring, efficient engagement tracking, SRD II compliance automation, standardized corporate governance data feeds

### Proxy Advisor
Firm that provides voting recommendations to institutional investors (ISS, Glass Lewis). Analyzes governance proposals and issues benchmark voting policies. Controls 90%+ of proxy advisory market.
**Responsibilities:** Analyzing AGM agenda items and resolutions, issuing voting recommendations, maintaining benchmark voting policies, researching corporate governance practices, reporting on voting outcomes
**Pain points:** Accessing timely and accurate meeting information across jurisdictions, analyzing large volumes of AGM proposals, maintaining consistent governance standards globally, adapting to regulatory changes
**Goals:** Standardized digital access to AGM agendas and resolutions, automated governance data collection, efficient cross-border meeting analysis, real-time resolution tracking

### External Auditor
Independent auditor who audits annual accounts and reports to shareholders. In Dutch governance, appointed by AGM and reports to audit committee. Subject to auditor rotation requirements.
**Responsibilities:** Auditing annual financial statements, reporting to audit committee, attending AGM for shareholder questions, assessing internal controls (SOX 404 if applicable), issuing management letter, evaluating going concern
**Pain points:** Limited digital access to governance documentation, manual evidence collection for audit procedures, difficulty tracking management representations, coordinating with internal audit and audit committee
**Goals:** Digital audit evidence repository, secure access to board minutes and resolutions, automated management representation tracking, integrated communication with audit committee

### Legal Counsel / Compliance Officer
In-house or external legal advisor responsible for corporate law compliance, governance code adherence, and regulatory filings. Ensures decisions meet legal requirements under Book 2 Dutch Civil Code.
**Responsibilities:** Advising on corporate governance compliance, reviewing resolutions for legal validity, managing regulatory filings (KVK, AFM, DNB), monitoring Corporate Governance Code compliance, handling insider trading rules, GDPR governance
**Pain points:** Tracking regulatory changes across jurisdictions, ensuring all decisions are properly documented and filed, managing conflict of interest registers, monitoring compliance deadlines, coordinating with notary for formal acts
**Goals:** Automated compliance monitoring and deadline tracking, digital conflict of interest management, integrated regulatory filing workflows, governance code compliance dashboard

### Works Council Representative
Representative of the works council (Ondernemingsraad), which has advisory and consent rights on major decisions in companies with 50+ employees. Has nomination rights for supervisory board under structure regime.
**Responsibilities:** Exercising advisory rights (adviesrecht) on strategic decisions, consent rights (instemmingsrecht) on HR policies, nominating supervisory board members (structure regime), attending shareholder meetings, reviewing major governance decisions
**Pain points:** Receiving governance information too late for meaningful input, limited access to decision-making timelines, difficulty tracking which decisions require works council involvement, inadequate digital tools for OR governance
**Goals:** Timely access to proposed decisions requiring OR advice/consent, digital workflow for adviesrecht and instemmingsrecht processes, transparent governance timeline visibility, secure communication with supervisory board

### CEO / Director
Top executive responsible for overall organizational strategy, final decision authority on major matters, and accountability to the board of directors.
**Responsibilities:** ["Setting organizational strategy and vision", "Final authority on major investment and policy decisions", "Chairing management team meetings", "Reporting to board of directors / supervisory board", "Approving budgets above delegation thresholds", "Crisis decision-making and escalation endpoint"]
**Pain points:** ["Decisions bottleneck at the top due to unclear delegation", "Lack of visibility into decision status across layers", "Too many items escalated that should be handled lower", "Difficulty tracking whether MT decisions are actually implemented", "Information overload from multiple reporting channels"]
**Goals:** ["Clear delegation of authority matrix", "Real-time dashboard of organizational decision status", "Efficient MT meeting cycle with tracked outcomes", "Audit trail for governance and compliance"]

## Entities for This Spec (4)

Full data model: see `openspec/architecture/adr-000-data-model.md`.
This spec uses:

- **Amendment**: A proposed change to an existing motion → Motion
- **Motion**: A formal proposal submitted for debate and voting → AgendaItem, Amendment, VotingRound
- **Vote**: An individual vote cast in a voting round → VotingRound, Participant
- **VotingRound**: A voting session on a motion or amendment → Motion, Vote

## Other App Entities (do NOT redefine)

ActionItem, AgendaItem, Decision, DigitalDocument, GovernanceBody, Meeting, Minutes, MonetaryAmount, Offer, Order, Participant, Product, Report

## Company-Wide Architecture Rules (13 ADRs)

These rules are MANDATORY for all Conduction apps.

### ADR-001-data-layer
- ALL domain data → OpenRegister objects. NO custom Entity/Mapper for domain data.
- App config → `IAppConfig`. NOT OpenRegister.
- Schemas: PascalCase, schema.org vocabulary where equivalent exists, explicit types.
- Cross-entity references: OpenRegister relations (register+schema+objectId). NO foreign keys.
- Register templates: `lib/Settings/{app}_register.json` (OpenAPI 3.0 + x-openregister).
- Seed data: 3-5 realistic objects per schema using `@self` envelope (`register`, `schema`, `slug`).
  Use general org data (municipality/consultancy), NOT context-specific. Include in design.md.
- Breaking schema changes → new migration in repair step. NEVER modify existing migrations.

### OpenRegister + @conduction/nextcloud-vue — DO NOT REBUILD

The platform provides 258+ backend methods and 69+ frontend components. Apps ONLY build
custom logic for domain-specific business rules. Everything below is provided for FREE.

**CRUD & Data Management** (use ObjectService + CnIndexPage + CnDetailPage):
- Single & bulk create, read, update, delete — `ObjectService.saveObject()`, `deleteObject()`
- List with pagination, sorting, filtering — `ObjectService.findAll()` + `CnDataTable`
- Schema-driven forms — `CnFormDialog` (auto-generates from schema) or `CnAdvancedFormDialog`
- Detail views — `CnDetailPage` with `CnDetailGrid`, `CnDetailCard` sections
- Record merging/deduplication — `ObjectService.mergeObjects()`
- Object locking — `ObjectService.lockObject()` / `unlockObject()`

**Import & Export** (use ImportService/ExportService + CnMassImportDialog/CnMassExportDialog):
- CSV, Excel, JSON import with intelligent field mapping — `ImportService`
- CSV, Excel, JSON export with column selection — `ExportService`
- Bulk import with validation and progress — `CnMassImportDialog`
- Filtered export with format picker — `CnMassExportDialog`
- NO custom import dialogs, parsers, upload handlers, or export controllers

**Search & Discovery** (use IndexService + CnFilterBar + CnFacetSidebar):
- Full-text search with field weighting — `IndexService`
- Faceted navigation with counts — `FacetBuilder` + `CnFacetSidebar`
- Semantic search with embeddings — `VectorizationService`
- Hybrid search (keyword + semantic) — automatic
- Search analytics — `SearchTrailService` (popular terms, activity)
- NO custom search endpoints, query builders, or search pages

**File Management** (use FileService + CnObjectSidebar):
- Upload (single/multipart), download, share links — `FileService`
- File tagging, public/private toggle — `FileService`
- Bulk download as ZIP — `createObjectFilesZip()`
- Text extraction from PDFs/Office docs — `TextExtractionService`
- File tab in object sidebar — `CnObjectSidebar` → `CnFilesTab`
- NO custom file upload components, file controllers, or download handlers

**Audit & Compliance** (use AuditTrailService + CnObjectSidebar):
- Full change tracking with before/after snapshots — automatic
- Audit trail tab — `CnObjectSidebar` → `CnAuditTrailTab`
- GDPR data subject access requests — `inzageverzoek()`, `verwerkingsregister()`
- Audit export and analytics — `AuditTrailController`
- NO custom audit logging, change tracking, or compliance controllers

**Dashboard & Analytics** (use CnDashboardPage + CnChartWidget + CnStatsBlock):
- Drag-drop widget dashboard — `CnDashboardPage` with GridStack
- KPI cards — `CnKpiGrid`, `CnStatsBlock`, `CnStatsPanel`
- Charts (line/bar/pie/donut) — `CnChartWidget` (ApexCharts)
- Data tables as widgets — `CnTableWidget`
- Editable data grids — `CnObjectDataWidget`
- NO custom dashboard layouts, chart components, or KPI cards

**Forms & Dialogs** (use CnFormDialog + schema-driven generation):
- Auto-generated create/edit forms — `CnFormDialog` reads schema → generates fields
- JSON/metadata editing — `CnAdvancedFormDialog` with Properties/Data/Metadata tabs
- Schema editor — `CnSchemaFormDialog`
- Delete/Copy/Mass operations — `CnDeleteDialog`, `CnCopyDialog`, `CnMassDeleteDialog`
- NO custom form components, validation logic, or dialog wrappers

**Navigation & Pagination** (use CnPagination + CnActionsBar + useListView):
- Pagination control with size selector — `CnPagination`
- Action bar (add, search, toggle views) — `CnActionsBar`
- List state management — `useListView` composable (handles search, filter, sort, page)
- Detail state management — `useDetailView` composable
- NO custom pagination logic, debounced search, or list state management

**Authorization & RBAC** (use AuthorizationService + PropertyRbacHandler):
- Role-based access control — `AuthorizationService`
- Field-level permissions — `PropertyRbacHandler`
- Object-level restrictions — `PermissionHandler`
- Authorization audit — `AuthorizationAuditService`
- NO custom permission checks, role systems, or access control middleware

**Webhooks & Events** (use WebhookService):
- Create, test, retry webhooks — `WebhookService`
- CloudEvents format — automatic
- Event subscriptions — selective per schema/action
- NO custom webhook controllers or event dispatchers

**Notifications & Activity** (use NotificationService + ActivityService):
- Nextcloud notifications — `NotificationService`
- Activity feed — `ActivityService`
- CalDAV storage — `CalDavService` (meetings as VEVENT, tasks as VTODO per ADR-002)
- Deck/Kanban cards — `DeckCardService`

**Store & State** (use createObjectStore + plugins):
- Object stores — `createObjectStore(name)` generates Pinia CRUD store
- Store plugins: `auditTrails`, `files`, `lifecycle`, `relations`, `search`, `selection`
- Column/field/filter generation from schema — `columnsFromSchema()`, `fieldsFromSchema()`
- NO custom Pinia stores for CRUD, Vuex, or manual API call management

**Chat & AI** (use ChatService):
- Multi-turn conversation — `ChatService`
- RAG-based knowledge retrieval — `ContextRetrievalHandler`
- LLM response generation — `ResponseGenerationHandler`

**Data Retention & Archival** (use ArchivalService):
- Legal hold — `LegalHoldService`
- Destruction schedules — `DestructionService`
- Retention policies — `RetentionService`

**Semantic & Hybrid Search** (use SolrController + SettingsController):
- Semantic search via vector embeddings — `SettingsController.semanticSearch()`
- Hybrid search (keyword + semantic combined) — `SolrController.hybridSearch()`
- Vector embedding generation — `VectorizationService`
- NO custom search algorithms — configure via OpenRegister settings

**GraphQL API** (use GraphQLController):
- Query objects across schemas via GraphQL — `GraphQLController.execute()`
- Alternative to REST for complex cross-entity queries

**Organization / Multi-Tenancy** (use OrganisationController):
- Organization CRUD — `OrganisationController`
- Tenant-scoped data isolation — automatic via `TenantLifecycleService`
- NO custom multi-tenancy logic

**Task & Workflow Management** (use TasksController + WorkflowEngineController):
- Task creation and tracking — `TasksController`
- Workflow orchestration — `WorkflowEngineRegistry`
- Scheduled workflows — `ScheduledWorkflowController`
- NO custom task/workflow systems

**Text Extraction** (use FileTextController):
- Extract text from PDFs and Office docs — `TextExtractionService`
- Entity recognition (PII detection) — `EntityRecognitionHandler`
- Content anonymization — automatic

**Timeline & Stages** (use CnTimelineStages):
- Workflow progression visualization — `CnTimelineStages` component
- Stage tracking with status colors

### What apps SHOULD build (custom business logic only):
- External API integrations (SAP, Peppol, TenderNed, etc.)
- PDF/document generation with business-specific templates
- Workflow triggers and business rules specific to the domain
- Notification dispatch with app-specific event types
- Custom settings pages with app-specific configuration
- Background jobs for domain-specific processing

### ADR-002-api
- URL pattern: `/index.php/apps/{app}/api/{resource}` — lowercase plural, hyphens.
- Methods: GET=read, POST=create, PUT=update, DELETE=remove. No custom methods.
- Pagination: support `_page` + `_limit`. Response includes `total`, `page`, `pages`.
- Errors: appropriate HTTP status + `message` field. NO stack traces in responses.
- Auth: Nextcloud built-in only. NO custom login/session/token flows.
- Public endpoints: annotate `#[PublicPage]` + `#[NoCSRFRequired]`. Register CORS OPTIONS route.

### ADR-003-backend
- **Controller → Service → Mapper** (strict 3-layer). Controllers NEVER call mappers directly.
- Controllers: thin (<10 lines/method). Routing + validation + response only.
- Services: ALL business logic. Stateless — no instance state between requests.
- Mappers: DB CRUD only. No business logic.
- DI: constructor injection with `private readonly`. NO `\OC::$server` or static locators.
- Entity setters: POSITIONAL args only. `$e->setName('val')` — NEVER `$e->setName(name: 'val')`.
  (`__call` passes `['name' => val]` but `setter()` uses `$args[0]`.)
- Routes: `appinfo/routes.php`. Specific routes BEFORE wildcard `{slug}` routes.
- Config: `IAppConfig` with sensitive flag for secrets. NEVER read DB directly.
- Lifecycle: schema init via repair steps (`IRepairStep`), background via job queue, events via dispatcher.
- **Spec traceability**: every class and public method MUST have `@spec` PHPDoc tag(s) linking to
  the OpenSpec change that caused it: `@spec openspec/changes/{name}/tasks.md#task-N`.
  Multiple `@spec` tags allowed (code touched by multiple changes). File-level `@spec` in header docblock.
  This enables: code → docblock → spec traceability alongside code → git blame → commit → issue → spec.

### ADR-004-frontend
- **Vue 2 + Pinia + @nextcloud/vue + @conduction/nextcloud-vue**. NO Vuex. Options API only.
- State: Pinia stores in `src/store/modules/`. Use `createObjectStore` for OpenRegister CRUD.
- `fetch()` for API calls — NOT axios. Loading state with `try/finally`.
- Translations: ALL user-visible strings via `t(appName, 'text')`. NO hardcoded strings.
- CSS: ONLY Nextcloud CSS variables. NO hardcoded colors. NEVER reference `--nldesign-*`.
- Router: history mode, base `/index.php/apps/{app}/`, catch-all `*` redirects to `/`.
- OpenRegister dependency: settings returns `openRegisters` (bool) + `isAdmin`.
  Show empty state if OR missing. NEVER use `OC.isAdmin` — get from backend.

### @conduction/nextcloud-vue — ALWAYS check before building custom

**Pages & Layout:**
  `CnIndexPage` (schema-driven list+CRUD) | `CnDetailPage` (detail+sidebar) |
  `CnPageHeader` (title+icon) | `CnActionsBar` (add+search+toggle)

**Data Display:**
  `CnDataTable` (sortable+paginated) | `CnCardGrid` + `CnObjectCard` (card views) |
  `CnDetailGrid` (label-value pairs) | `CnFilterBar` (search+filters) |
  `CnFacetSidebar` (faceted filters) | `CnPagination` | `CnCellRenderer` (type-aware)

**Forms & Dialogs:**
  `CnFormDialog` (schema-driven create/edit) | `CnAdvancedFormDialog` (properties+JSON+metadata) |
  `CnSchemaFormDialog` (JSON Schema editor) | `CnTabbedFormDialog` (tabbed form framework) |
  `CnDeleteDialog` | `CnCopyDialog`

**Mass Actions:**
  `CnMassDeleteDialog` | `CnMassCopyDialog` | `CnMassExportDialog` (CSV/JSON/XML) |
  `CnMassImportDialog` (upload+summary) | `CnMassActionBar` (floating selection bar)

**Dashboard & Widgets:**
  `CnDashboardPage` (GridStack drag-drop layout) | `CnDashboardGrid` (layout engine) |
  `CnWidgetWrapper` (widget shell) | `CnWidgetRenderer` (NC Dashboard API v1/v2) |
  `CnChartWidget` (ApexCharts: area/line/bar/pie/donut/radial) |
  `CnTableWidget` (data table widget) | `CnTileWidget` (quick-access tile) |
  `CnInfoWidget` (label-value grid) | `CnKpiGrid` (responsive KPI layout) |
  `CnStatsBlock` (metric card) | `CnStatsPanel` (stats sections) | `CnProgressBar` |
  `CnObjectDataWidget` (schema-driven editable data grid, inline edit + save via objectStore) |
  `CnObjectMetadataWidget` (read-only object metadata display)

**UI Elements:**
  `CnStatusBadge` | `CnEmptyState` | `CnIcon` (MDI) | `CnCard` | `CnDetailCard` |
  `CnRowActions` | `CnTimelineStages` (workflow progression) |
  `CnUserActionMenu` (user context menu) | `CnJsonViewer` (CodeMirror)

**Detail Sidebar:**
  `CnObjectSidebar` (Files/Notes/Tags/Tasks/Audit tabs) | `CnIndexSidebar` |
  `CnNotesCard` (inline notes) | `CnTasksCard` (inline tasks)

**Settings:**
  `CnSettingsSection` + `CnVersionInfoCard` (MUST be first on admin pages) |
  `CnSettingsCard` | `CnConfigurationCard` | `CnRegisterMapping`
  User settings: `NcAppSettingsDialog` (NOT `NcDialog`)

**Composables:**
  `useListView` (search/filter/sort/pagination) | `useDetailView` (load/edit/delete) |
  `useSubResource` (related items) | `useDashboardView` (widgets/layout/edit)

**Store Plugins:**
  `auditTrailsPlugin` | `relationsPlugin` | `filesPlugin` | `lifecyclePlugin` |
  `selectionPlugin` | `searchPlugin` | `registerMappingPlugin`

**Utilities:**
  `columnsFromSchema()` | `filtersFromSchema()` | `fieldsFromSchema()` |
  `formatValue()` | `buildHeaders()` | `buildQueryString()`

### Page Construction Patterns (follow these recipes)

**App.vue:** `NcContent` → 3 states: loading (`NcLoadingIcon`), no-OpenRegister (`NcEmptyContent`),
  ready (`MainMenu` + `NcAppContent` + `router-view` + optional `CnIndexSidebar`).
  Inject `sidebarState` for child components. `created()` calls `initializeStores()`.

**MainMenu:** `NcAppNavigation` with `NcAppNavigationItem` per route (icon + name + `:to`).
  Footer: settings link via `NcAppNavigationSettings`.

**Dashboard:** `CnDashboardPage` with `CnStatsBlock` KPIs (4 cards: open/overdue/value/completed),
  status distribution chart, "My Work" list (grouped: overdue → due this week → rest).
  Fetch all collections in parallel via `Promise.all`. Widget templates via `#widget-{id}` slots.

**Index page:** `CnIndexPage` with `useListView(entityType, { sidebarState, objectStore })`.
  Inject sidebarState. Row click → `$router.push({ name: 'EntityDetail', params: { id } })`.
  Add button → new entity detail with id='new'.

**Detail page:** Two modes — edit (form component) / view (`CnDetailPage` + `CnDetailCard` sections).
  Header actions: Edit + Delete buttons. Related entities in table inside `CnDetailCard`.
  Props: `entityId` from route. `isNew = entityId === 'new'`. Sidebar via `CnObjectSidebar`.

**Settings:** `CnVersionInfoCard` (FIRST, always) → `CnRegisterMapping` → `CnSettingsSection` per feature.
  Load settings from `GET /api/settings`. Save via `POST /api/settings`.
  Re-import button calls `POST /api/settings/load`.

**Router:** Flat routes (no nesting), all named, props via arrow function for params.
  Routes: `/` (Dashboard), `/{entities}` (list), `/{entities}/:id` (detail), `/settings`.

**Store init:** `initializeStores()` in `store/store.js` — fetches settings, then calls
  `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each entity.
  Object store uses `createObjectStore` with plugins (files, auditTrails, relations).
  Settings store: Pinia `defineStore` with `fetchSettings()` and `saveSettings()`.

### ADR-005-security
- Auth: Nextcloud built-in ONLY. NO custom login, sessions, tokens, password storage.
- Admin check: `IGroupManager::isAdmin()` on BACKEND. Frontend-only checks = vulnerability.
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.

### ADR-006-metrics
- Every app: `GET /api/metrics` (Prometheus text, admin auth) + `GET /api/health` (JSON, public).
- Metric names: `{app}_` prefix. MUST include `{app}_health_status` and `{app}_info`.
- Health check MUST verify OpenRegister connectivity (for apps that depend on it).

### ADR-007-i18n
- Minimum: Dutch (nl) + English (en) translations.
- PHP: `$this->l->t('key')`. JS: `t(appName, 'key')`.
- API field names: English. Date/number formatting: respect user locale.
- Each app with OpenRegister: define `register-i18n` spec listing translatable fields.

### ADR-008-testing
- Every new PHP service/controller → PHPUnit tests in `tests/Unit/` (≥3 methods).
- Every new Vue component → test file (if test framework exists).
- Every new API endpoint → Newman/Postman collection in `tests/integration/`.
- Every spec scenario → browser test (GIVEN/WHEN/THEN verified via Playwright).
- All tests MUST pass in `composer check:strict`.

### ADR-009-docs
- Every user-facing feature → docs in `docs/` with screenshots from running app.
- English primary, Dutch recommended. Update docs when behavior changes.

### ADR-010-nl-design
- ALL UI: CSS custom properties from NL Design System tokens. NO hardcoded colors, fonts, spacing.
- Theme switching: support `nldesign` app's token sets (Rijkshuisstijl, Utrecht, municipality-specific).
- Components: `@nextcloud/vue` primary. Custom components styled via NL Design tokens only.
- Scoped styles: ALL `<style>` blocks MUST use `scoped` attribute.
- WCAG AA mandatory: keyboard-navigable, labelled forms, color not sole conveyor, alt text on images.
- Responsive: work from 320px to 1920px. Critical features accessible at 768px.
- Specs: reference token names ("primary action color") NOT hex values. Include a11y verification in ACs.
- Exception: PDF generation (docudesk) may use fixed dimensions. Admin screens MAY simplify but MUST meet WCAG AA.

### ADR-011-schema-standards
- schema.org types/properties as primary vocabulary (`schema:Person`, `schema:Organization`, `schema:Event`).
- Contact schemas: align with vCard properties (`fn`, `email`, `tel`, `adr`).
- Dutch government fields: mapping layer translating between international standards and Dutch APIs (VNG, ZGW).
- NO custom property names when schema.org equivalent exists.
- Relations: OpenRegister relation mechanism (register + schema + objectId). NO foreign keys or embedded objects.
- Versioning: removing/renaming properties = BREAKING → migration via repair step. Adding optional = non-breaking.
- Specs MUST define data models using schema.org vocabulary; design docs MUST include schema definitions with types, required flags, relations.
- Exception: app-specific workflow states (pipeline stages, process statuses) MAY use custom vocabularies.

### ADR-012-deduplication
- Before proposing new capability: search OpenRegister specs + services for overlap. Reference + justify if similar exists.
- Design docs MUST include "Reuse Analysis" listing which OpenRegister services are leveraged.
- If logic could benefit other apps → propose adding to OpenRegister core, not app-specific.
- Tasks MUST include "Deduplication Check" verifying no overlap with:
  ObjectService, RegisterService, SchemaService, ConfigurationService, shared specs, @conduction/nextcloud-vue.
- Document findings even if "no overlap found".
- Exception: OpenRegister checks internal duplication only. nldesign checks token sets. nextcloud-vue checks own components.

### ADR-013-container-pool
# ADR-013: Unified Container Pool

**Status:** accepted
**Date:** 2026-04-12

## Context

Specter (intelligence/research) and Hydra (build/review/merge) both run LLM workloads in Docker containers. Today they operate independently: Hydra spins up builder/reviewer/security containers on demand, Specter has a separate `run_llm_containers.sh` wrapper. Both compete for the same Claude Max rate limits.

We want to unify these into a **single priority-scheduled container pool** so that:
- Critical work (bugfixes, reviews) preempts lower-priority work (discovery, research)
- A fixed number of containers (e.g. 10) run continuously, pulling from a shared queue
- Token rotation and rate limit recovery happen at the pool level, not per-script
- Adding a new workload type (audit, spec generation, test) is just a new queue entry

## Decision

### Container types (priority order)

| Priority | Type | Source | Container image | Model |
|----------|------|--------|-----------------|-------|
| 1 | **bugfix** | Hydra: fix iteration after review failure | `hydra-builder` | opus |
| 2 | **code-review** | Hydra: PR code review | `hydra-reviewer` | sonnet |
| 3 | **security-review** | Hydra: PR security review | `hydra-security` | sonnet |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | opus |
| 5 | **audit** | Hydra: codebase audit | `hydra-builder` | sonnet |
| 6 | **spec-generation** | Specter: push_spec_pipeline | `specter-llm-worker` | sonnet |
| 7 | **schema-synthesis** | Specter: generate/dedup schemas | `specter-llm-worker` | haiku |
| 8 | **classification** | Specter: classify/redistribute features | `specter-llm-worker` | haiku |
| 9 | **translation** | Specter: translate requirements | `specter-llm-worker` | haiku |
| 10 | **discovery** | Specter: research, feature extraction | `specter-llm-worker` | haiku |

### Architecture

```
┌─────────────────────────────────────────────────────┐
│  Scheduler (cron or daemon)                         │
│                                                     │
│  reads: queue table (postgres)                      │
│  writes: container assignments, status updates      │
│                                                     │
│  ┌──────────────────────────────────────────┐       │
│  │ Pool: 10 container slots                 │       │
│  │                                          │       │
│  │  slot-1: [bugfix]     ← highest prio     │       │
│  │  slot-2: [code-review]                   │       │
│  │  slot-3: [build]                         │       │
│  │  slot-4: [build]                         │       │
│  │  slot-5: [classify]                      │       │
│  │  slot-6: [classify]                      │       │
│  │  slot-7: [translate]                     │       │
│  │  slot-8: [discovery]                     │       │
│  │  slot-9: [idle]       ← waiting for work │       │
│  │  slot-10: [idle]                         │       │
│  └──────────────────────────────────────────┘       │
│                                                     │
│  Token rotation: credentials.json (work → private)  │
│  Rate limit: pool-level tracking per account        │
│  Preemption: low-prio containers stopped when       │
│              high-prio work arrives and pool is full │
└─────────────────────────────────────────────────────┘
```

### Queue table (future)

```sql
CREATE TABLE container_queue (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,        -- bugfix, code-review, build, classify, etc.
    priority INTEGER NOT NULL,         -- 1=highest
    payload JSONB NOT NULL,            -- script args, spec slug, issue URL, etc.
    status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
    container_id VARCHAR(100),         -- docker container name when running
    token_account VARCHAR(50),         -- which OAuth account is assigned
    created_at TIMESTAMP DEFAULT NOW(),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    exit_code INTEGER,
    error_message TEXT
);
```

### Phased rollout

**Phase 1 (now):** All LLM calls containerized. Specter scripts run via `run_llm_containers.sh`. Hydra containers use `run_container_with_fallback`. Both read from `credentials.json`. No shared queue yet — each system schedules its own containers.

**Phase 2:** Shared queue table. A single scheduler script replaces both `cron-hydra.sh` dispatch and `run_llm_containers.sh`. Pool size configurable. Priority enforcement by not starting low-prio work when high-prio is queued.

**Phase 3:** Preemption. Running low-priority containers can be stopped (gracefully, with checkpoint) when high-priority work arrives and all slots are occupied. Container images support checkpoint/resume via DB state.

### Current state (Phase 1)

Both systems already containerize LLM calls:
- **Hydra:** `builder`, `reviewer`, `security` images in `hydra/images/`
- **Specter:** `specter-llm-worker` image via `Dockerfile.llm-worker`
- **Shared credentials:** `hydra/secrets/credentials.json` with priority-ordered OAuth tokens
- **Token fallback:** Hydra via `credentials.sh`, Specter via `credentials.py`

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized in `credentials.json`
- Future pool scheduler can enforce rate limits across both systems
- Container images are the unit of deployment — version, test, rollback independently

## App-Specific ADRs (1)

These ADRs are specific to Decidesk.

### 000-data-model: ADR-000: Data Model — decidesk
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


## App Architecture ADRs from Repo (1 files)

These ADR files live in decidesk/openspec/architecture/.

### ADR-000-data-model
# ADR: Data Model — Decidesk

**Status:** accepted
**Entities:** 17

## Context

All data entities are OpenRegister schemas. This ADR is the single source of truth
for the app's data model. Individual specs REFERENCE these entities but do not redefine them.

OpenRegister built-in fields (NOT listed below, always available):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

## Entities

### ActionItem
**Schema.org:** `custom:ActionItem`
_A follow-up task from a meeting decision_
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

### AgendaItem
**Schema.org:** `custom:AgendaItem`
_An item on a meeting agenda with type, time, and ordering_
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

### Amendment
**Schema.org:** `custom:Amendment`
_A proposed change to an existing motion_
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

### Decision
**Schema.org:** `custom:Decision`
_A formal decision resulting from a vote_
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

### DigitalDocument
**Schema.org:** `schema:DigitalDocument`
_Schema.org DigitalDocument — standard vocabulary for digitaldocument data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document name/title |
| documentType | string | Yes | Document type (contract, tender, report, etc.) |
| description | string | No | Document description |
| encodingFormat | string | No | MIME type (application/pdf, etc.) |
| contentSize | string | No | File size |

### GovernanceBody
**Schema.org:** `schema:Organization`
_A governance body (council, board, committee, assembly)_
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

### Meeting
**Schema.org:** `schema:Event`
_A scheduled governance meeting with agenda, participants, and lifecycle_
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

### Minutes
**Schema.org:** `custom:Minutes`
_Official record of a meeting's proceedings_
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

### MonetaryAmount
**Schema.org:** `schema:MonetaryAmount`
_Schema.org MonetaryAmount — standard vocabulary for monetaryamount data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | number | Yes | Numeric value |
| currency | string | Yes | ISO 4217 currency code |

### Motion
**Schema.org:** `custom:Motion`
_A formal proposal submitted for debate and voting_
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

### Offer
**Schema.org:** `schema:Offer`
_Schema.org Offer — standard vocabulary for offer data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Offer/quote name |
| price | number | Yes | Offered price |
| priceCurrency | string | Yes | Currency |
| validFrom | datetime | No | Offer valid from |
| validThrough | datetime | No | Offer valid until |
| availability | string | No | Availability status |

### Order
**Schema.org:** `schema:Order`
_Schema.org Order — standard vocabulary for order data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | datetime | Yes | Date of order |
| orderStatus | string | Yes | Order status |
| totalPrice | number | Yes | Total order amount |
| currency | string | Yes | ISO 4217 currency code |
| deliveryDate | datetime | No | Expected delivery date |
| paymentTerms | string | No | Payment terms (e.g., NET30) |

### Participant
**Schema.org:** `schema:Person`
_A member or attendee of a governance body_
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

### Product
**Schema.org:** `schema:Product`
_Schema.org Product — standard vocabulary for product data_

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

### Report
**Schema.org:** `schema:Report`
_Schema.org Report — standard vocabulary for report data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type (financial, compliance, etc.) |
| period | string | No | Reporting period |
| generatedAt | datetime | No | When the report was generated |

### Vote
**Schema.org:** `custom:Vote`
_An individual vote cast in a voting round_
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

### VotingRound
**Schema.org:** `custom:VotingRound`
_A voting session on a motion or amendment_
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
