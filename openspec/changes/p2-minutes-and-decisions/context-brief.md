# Context Brief: Minutes and Decisions

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p2-minutes-and-decisions
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

## Features (18 total, sorted by market demand)

### Auto-archive council decisions
**demand: 746** (248 tender mentions) | Category: document-management

### View full audit log of access decisions
**demand: 206** (68 tender mentions) | Category: governance

### Minutes approval workflow
**demand: 186** | Category: minutes and decisions
Chair/secretary review and approval of draft minutes

### Decision publication
**demand: 186** | Category: minutes and decisions
Publish decisions via ORI API and Woo-compliant channels

### Minutes versioning
**demand: 186** | Category: minutes and decisions
Track revisions with full audit trail

### Digital signing of minutes
**demand: 186** | Category: minutes and decisions
Chair and secretary sign approved minutes

### Decision search and archive
**demand: 186** | Category: minutes and decisions
Search historical decisions by topic, date, body, outcome

### Automated minutes generation
**demand: 186** | Category: minutes and decisions
Generate minutes from meeting proceedings, motions, and votes

### Decision recording
**demand: 186** | Category: minutes and decisions
Record formal decisions with references to motions and votes

### Tasks & Decisions Tracking
**demand: 119** (12 tender mentions) | Category: analytics

### Action Item Tracking
**demand: 73** (14 tender mentions) | Category: analytics

### Decision Accountability with Responsible Party Association
**demand: 13** (4 tender mentions) | Category: governance

### Quick setup (5 minutes)
**demand: 2** | Category: Architecture
Single JAR/Docker deployment with automatic database discovery and setup wizard

### Decision Documentation
**demand: 2** | Category: Compliance
Document considerations and motivation for subsidy decision-making with audit trail

### AI-augmented decisions
**demand: 1** | Category: ai

### automated decisions
**demand: 1** | Category: ai

### Local Audio Recording With AI-Generated Meeting Summaries
**demand: 1** | Category: ai

### View audit history of harvest source decisions
**demand: unknown** | Category: governance

## User Stories (30 linked)

### Story 1: Election Technology
**Priority:** wont
As a meeting organizer, I want to have election-technology capabilities, so that decisions are made fairly and transparently.

### Story 2: Quick Polls
**Priority:** wont
As a meeting organizer, I want to have quick polls capabilities, so that decisions are made fairly and transparently.

### Story 3: Technology Selection Criteria
**Priority:** wont
As a meeting organizer, I want to have technology selection criteria capabilities, so that decisions are made fairly and transparently.

### Story 4: Crowdsourced Legislation
**Priority:** wont
As a council clerk, I want to have crowdsourced legislation capabilities, so that legislative actions follow proper procedure with full traceability.

### Story 5: Audio/video Indexing
**Priority:** wont
As a council clerk, I want to have audio/video indexing capabilities, so that the platform meets diverse organizational needs.

### Story 6: Emerging Technologies
**Priority:** wont
As a council clerk, I want to have emerging technologies capabilities, so that the platform meets diverse organizational needs.

### Story 7: Party Democracy
**Priority:** wont
As a council clerk, I want to have party democracy capabilities, so that the platform meets diverse organizational needs.

### Story 8: Entry/exit Actions
**Priority:** wont
As a council clerk, I want to have entry/exit actions capabilities, so that the platform meets diverse organizational needs.

### Story 9: Deontic Logic
**Priority:** wont
As a council clerk, I want to have deontic logic capabilities, so that the platform meets diverse organizational needs.

### Story 10: Metadata Ontology
**Priority:** wont
As a council clerk, I want to have metadata ontology capabilities, so that the platform meets diverse organizational needs.

### Story 11: Pre Signing Workflow
**Priority:** wont
As a council clerk, I want to have pre-signing workflow capabilities, so that the platform meets diverse organizational needs.

### Story 12: Actions
**Priority:** wont
As a council clerk, I want to have actions capabilities, so that the platform meets diverse organizational needs.

### Story 13: Action Availability
**Priority:** wont
As a council clerk, I want to have action availability capabilities, so that the platform meets diverse organizational needs.

### Story 14: Faction Proportional Allocation
**Priority:** wont
As a council clerk, I want to have faction-proportional allocation capabilities, so that the platform meets diverse organizational needs.

### Story 15: Archive Consistency
**Priority:** wont
As a council clerk, I want to archive consistency, so that the platform meets diverse organizational needs.

### Story 16: Action Point Assignment
**Priority:** wont
As a council clerk, I want to have action point assignment capabilities, so that the platform meets diverse organizational needs.

### Story 17: Digital Signing
**Priority:** wont
As a council clerk, I want to have digital signing capabilities, so that the platform meets diverse organizational needs.

### Story 18: Conditional Approval Logic
**Priority:** wont
As a meeting organizer, I want to use conditional approval logic, so that the platform meets diverse organizational needs.

### Story 19: Integrate drip sequences with n8n workflows
**Priority:** should
As a marketing automation specialist, I want to connect drip sequences to n8n workflows, so that I can incorporate external data sources and complex logic into my automation.

**Acceptance Criteria:**
Promotiq exposes webhook triggers and actions for n8n; n8n can add/remove subscribers, trigger sequences, and query stats; bidirectional data flow

### Story 20: Generate AI-powered meeting transcription and summaries
**Priority:** should
As a secretary, I want automatic meeting transcription with AI-generated summaries (key decisions, action items, discussion points), so that minutes creation is automated and nothing is missed.

**Acceptance Criteria:**
["Real-time transcription with >90% accuracy", "AI summary generated at meeting end", "Key decisions highlighted", "Action items extracted with owners", "Searchable transcript archive", "Multi-language support (NL/EN minimum)", "Privacy-compliant (data stays in tenant)"]

## Customer Journeys (15 linked)

### AV & Webcast Infrastructure Management
Managing AV infrastructure in raadzaal and commissiekamers. Discussion systems, PTZ cameras, AV control, webcast/streaming, microphone management.
**Trigger:** Meeting scheduled requiring AV support; system maintenance/upgrade
**Desired outcome:** Reliable AV infrastructure supporting meetings with high-quality recording and streaming
**Current pain:** Complex multi-vendor systems; AV-RIS integration for indexing; maintenance windows; high costs; rapid tech evolution
**Frequency:** Every meeting (setup/support) + periodic maintenance

### Handle Internal Document Access Request
A records manager processes a request from a colleague who needs access to a restricted document, verifies authorisation, grants temporary access, and logs the disclosure.
**Trigger:** Staff member requests access to a document outside their normal access level
**Desired outcome:** Access is granted or refused with documented justification, access is time-limited, and the disclosure is logged for audit purposes
**Current pain:** Access requests are handled via email with no structured approval workflow or automatic expiry of granted access
**Frequency:** weekly

### Meeting Recording Publication
Video and audio recordings processed, indexed (linked to agenda items), captioned, and published in RIS for on-demand viewing.
**Trigger:** Completion of a recorded meeting
**Desired outcome:** Indexed, accessible recordings published and linked to relevant agenda items in the RIS
**Current pain:** Long processing times; manual indexing; accessibility requirements (captions); large file storage
**Frequency:** After every recorded meeting

### System Integration & Data Exchange
Managing integrations between RIS and other systems: zaaksysteem, DMS, AV/webcast, archiving, PLOOI platform.
**Trigger:** New system implementation, upgrade, or integration requirement
**Desired outcome:** Seamless data flow between RIS and connected systems with no manual re-entry
**Current pain:** Legacy APIs; proprietary interfaces; vendor lock-in; complex AV integration; multiple auth systems
**Frequency:** Ongoing (initial setup + continuous maintenance)

### Water Board General Assembly Meeting
Water board general assembly meets at least 6x/year. Sets policy, approves budgets, oversees executive board. Chaired by dijkgraaf. Secretary-director maintains minutes.
**Trigger:** Scheduled general assembly meeting (minimum 6x/year)
**Desired outcome:** Policy decisions made, budgets approved, oversight exercised, complete minutes maintained
**Current pain:** Similar needs as municipalities but different governance; fewer commercial RIS options; unique stakeholder categories
**Frequency:** Minimum 6 times per year (general assembly); bi-weekly (executive board)

### Test BPMN Process in Staging Environment
Before publishing, the process designer runs the BPMN model with test cases to verify gateway conditions, timer behaviour, and task routing behave as intended.
**Trigger:** Process model is completed and ready for validation before go-live
**Desired outcome:** All process paths are verified correct; edge cases and error paths are handled without unexpected process suspension
**Current pain:** No integrated test runner; testing requires manual zaak creation and step-by-step verification which is slow and incomplete
**Frequency:** monthly

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

### Open Data Publication (ORI)
Publishing council information as open data following the ORI standard. API access, linked data, integration with PLOOI. Over 100 Dutch municipalities participate.
**Trigger:** New meeting data or documents are published in the RIS
**Desired outcome:** Council data available as structured open data via standardized API, compliant with ORI and Woo
**Current pain:** Vendor-specific connectors required; inconsistent data quality; evolving standard; limited real-time capability
**Frequency:** Continuous (automated sync)

### Press Coverage & Media Access
Journalists access council meetings, documents, and officials for reporting. Includes press pass registration, gallery access, post-meeting briefings, and RIS research.
**Trigger:** Newsworthy item on council agenda or need for background research
**Desired outcome:** Journalist can efficiently access all public council information, attend meetings, contact officials
**Current pain:** Complex registration; limited press facilities; restrictions on filming/quoting; delayed recordings; declining journalism resources
**Frequency:** Continuous (more intense around plenary meetings)

### Decision Execution & Follow-up
After council makes a decision, the executive is responsible for executing it. Includes implementing policy changes, allocating budgets, and reporting back to council.
**Trigger:** Council adopts a proposal, motion, or amendment requiring executive action
**Desired outcome:** Decision is fully implemented and the council is informed of completion
**Current pain:** Difficulty linking decisions to implementation; no automated tracking; reporting often delayed; unclear accountability
**Frequency:** Continuous (each adopted decision requires follow-up)

### Live Meeting Following & Webcasting
Citizens and journalists follow meetings in real-time, physically or via live webcast/stream. Includes viewing agenda in real-time and tracking which item is being discussed.
**Trigger:** Council or committee meeting is in session
**Desired outcome:** Interested parties can follow the meeting in real-time with access to relevant documents
**Current pain:** Webcast quality varies; no real-time agenda tracking; difficult to find relevant document during live viewing; no live captions
**Frequency:** Every public meeting (3-6 per month)

### RIS Platform Migration & Transition
Migrating from one RIS to another including data migration of historical records, user transition, integration reconfiguration, and training.
**Trigger:** Contract expiration, dissatisfaction with current RIS, or need for new features
**Desired outcome:** Successful migration with complete data transfer, minimal disruption, and satisfied users
**Current pain:** Complex data migration from proprietary formats; risk of data loss; user resistance; training needs; vendor lock-in
**Frequency:** Every 4-8 years (contract cycles)

### Provincial States Meeting Cycle
Provincial States follow similar BOB model but larger scale. CdK chairs, griffier supports, factions coordinate through presidium. State proposals through statencommissies before statendag.
**Trigger:** Monthly meeting cycle of provincial states
**Desired outcome:** Provincial policy decisions made through structured committee and plenary process
**Current pain:** Similar to municipal pain points but larger scale; more formal procedures
**Frequency:** Monthly (statendag) plus committee meetings

### Handle Complex Multi-Domain Citizen Question
A citizen has a question spanning multiple domains (e.g., housing benefit, parking permit, and social assistance). The front desk officer creates linked zaken or a combined intake and routes each to the correct department.
**Trigger:** Citizen presents with multiple interconnected service needs in a single interaction
**Desired outcome:** All needs are captured, routed, and tracked under a single citizen profile; no request is lost or duplicated
**Current pain:** Systems do not support grouped intake; officer must create each zaak separately and manually inform all receiving departments
**Frequency:** weekly

## Stakeholders (308 linked)

### CEO / Managing Director
Chief Executive Officer or managing director responsible for day-to-day management of the company. In Dutch two-tier model, member of the Raad van Bestuur (management board).
**Responsibilities:** Setting corporate strategy, executing board decisions, representing the company externally, reporting to supervisory board, preparing annual accounts, convening shareholder meetings
**Pain points:** Complex approval chains for strategic decisions, balancing stakeholder interests, ensuring compliance with Dutch Corporate Governance Code, managing conflict of interest declarations, coordinating between management board and supervisory board
**Goals:** Efficient decision-making processes, clear audit trails for all governance decisions, streamlined communication with supervisory board and shareholders, digital-first governance workflows

### CFO / Financial Director
Chief Financial Officer responsible for financial reporting, internal controls, SOX compliance (if applicable), and financial governance. Interfaces with audit committee and external auditors.
**Responsibilities:** Financial reporting and annual accounts, internal controls (SOX Section 302/404 certification), risk management, treasury, tax compliance, audit coordination, dividend proposals
**Pain points:** SOX compliance documentation burden, coordinating with external auditors, ensuring internal control effectiveness, managing financial reporting deadlines, audit committee preparation workload
**Goals:** Automated internal control documentation, streamlined audit processes, real-time financial governance dashboards, efficient committee reporting

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

## Entities for This Spec (3)

Full data model: see `openspec/architecture/adr-000-data-model.md`.
This spec uses:

- **ActionItem**: A follow-up task from a meeting decision → Decision, Meeting
- **Decision**: A formal decision resulting from a vote → Motion, ActionItem
- **Minutes**: Official record of a meeting's proceedings → Meeting

## Other App Entities (do NOT redefine)

AgendaItem, Amendment, DigitalDocument, GovernanceBody, Meeting, MonetaryAmount, Motion, Offer, Order, Participant, Product, Report, Vote, VotingRound

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
