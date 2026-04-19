# Context Brief: Document Management

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p3-document-management
**Platform:** Nextcloud + OpenRegister

**Depends on:** p2-minutes-and-decisions, p2-motion-and-voting, p2-agenda-management, p2-meeting-management

## Dependency Specs (content)

These specs were already decided/implemented. Use them as context.

### p2-minutes-and-decisions
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

### Voter Register
**demand: 26** (6 tender mentions) | Category: other

### Attendance Register
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
**Trigger:** Citizen presents with multiple interconnected service needs in 
... (truncated)

### p2-motion-and-voting
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

### Voter Register
**demand: 26** (6 tender mentions) | Category: other

### Attendance Register
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
**Trigger:** Citizen presents with multiple interconnected service needs in a single
... (truncated)

### p2-agenda-management
# Context Brief: Agenda Management

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p2-agenda-management
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

### Voter Register
**demand: 26** (6 tender mentions) | Category: other

### Attendance Register
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
**Trigger:** Citizen presents with multiple interconnected service needs in a single
... (truncated)

### p2-meeting-management
# Context Brief: Meeting Management

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p2-meeting-management
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

### Voter Register
**demand: 26** (6 tender mentions) | Category: other

### Attendance Register
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
**Trigger:** Citizen presents with multiple interconnected service needs in a sing
... (truncated)

## Features (18 total, sorted by market demand)

### Template Management
**demand: 953** (312 tender mentions) | Category: document-management

### Council Document Archive
**demand: 817** (271 tender mentions) | Category: document-management

### Portal Voting with Digital Document Archiving
**demand: 416** (134 tender mentions) | Category: core

### Organize governance document archive
**demand: 389** (128 tender mentions) | Category: document-management

### Search within Woo Publication Selection
**demand: 198** (66 tender mentions) | Category: other

### Email archivering Corsa
**demand: 168** (52 tender mentions) | Category: other

### Archivering (Archiving)
**demand: 135** (41 tender mentions) | Category: other

### View Woo Publication Selection overview
**demand: 54** (18 tender mentions) | Category: other

### Receive notifications for Citizen Uploads Additional Documents
**demand: 54** (18 tender mentions) | Category: participation

### Archivering
**demand: 49** (7 tender mentions) | Category: other

### Generate a Woo-compliant publication report
**demand: 24** (8 tender mentions) | Category: analytics

### Export Woo Publication Selection data
**demand: 12** (4 tender mentions) | Category: document-management

### Participant Opinion Rationale Documentation
**demand: 1** | Category: other

### Akoma Ntoso export
**demand: unknown** | Category: document management
Export motions, amendments, and minutes as Akoma Ntoso XML

### Receive notifications for Woo Publication Selection
**demand: unknown** | Category: other

### TOOI classification
**demand: unknown** | Category: document management
Classify documents using TOOI thesaurus terms

### Document versioning
**demand: unknown** | Category: document management
Track document revisions with diff view

### Bulk export
**demand: unknown** | Category: document management
Export meeting packages (agenda + documents) as ZIP

## User Stories (8 linked)

### Story 1: Digital Archiving
**Priority:** wont
As a council clerk, I want to have digital archiving capabilities, so that the platform meets diverse organizational needs.

### Story 2: e Depot Archiving
**Priority:** wont
As a council clerk, I want to have e-depot archiving capabilities, so that the platform meets diverse organizational needs.

### Story 3: Tool Classification
**Priority:** wont
As a council clerk, I want to use tool classification, so that the platform meets diverse organizational needs.

### Story 4: Archive Consistency
**Priority:** wont
As a council clerk, I want to archive consistency, so that the platform meets diverse organizational needs.

### Story 5: Automatic Archiving
**Priority:** wont
As a council clerk, I want to have automatic archiving capabilities, so that the platform meets diverse organizational needs.

### Story 6: Archiving Requirements
**Priority:** wont
As a council clerk, I want to have archiving requirements capabilities, so that the platform meets diverse organizational needs.

### Story 7: Archivering
**Priority:** wont
As a meeting organizer, I want to have archivering capabilities, so that the platform meets diverse organizational needs.

### Story 8: Export dossier for long-term archiving
**Priority:** must-have
As a dossier manager, I want to export the complete dossier in archive format (MDTO) so that it meets long-term preservation standards

**Acceptance Criteria:**
Dossier exported in MDTO format, metadata complete, checksum verified

## Customer Journeys (15 linked)

### Design Metadata Schema for Document Type
An information architect defines the required and optional metadata fields for a new or revised document type, aligning with NEN-ISO 23081 and the organisation's information architecture.
**Trigger:** New document type is introduced or metadata model is being updated as part of an i-NUP or GEMMA alignment project
**Desired outcome:** Schema is documented, approved, published in the DMS configuration, and enforced at document creation
**Current pain:** Metadata schemas are defined in separate documentation tools and manually translated into DMS configuration, leading to drift between documentation and implementation
**Frequency:** quarterly

### Map Metadata to External Standards
An information architect creates and maintains mappings between the internal DMS metadata model and external standards such as MDTO, Dublin Core, or TOOI, enabling interoperability with other government systems.
**Trigger:** Integration with a new external system is required, or a government-wide standard is updated
**Desired outcome:** Metadata mappings are documented, implemented in export/import transforms, and validated against the target standard
**Current pain:** Mappings are undocumented or exist only in developer knowledge; each integration is built ad-hoc without a consistent mapping layer
**Frequency:** quarterly

### Provincial States Meeting Cycle
Provincial States follow similar BOB model but larger scale. CdK chairs, griffier supports, factions coordinate through presidium. State proposals through statencommissies before statendag.
**Trigger:** Monthly meeting cycle of provincial states
**Desired outcome:** Provincial policy decisions made through structured committee and plenary process
**Current pain:** Similar to municipal pain points but larger scale; more formal procedures
**Frequency:** Monthly (statendag) plus committee meetings

### Migrate Legacy Paper Records to Digital Archive
A records manager oversees the scanning, metadata extraction, and ingestion of legacy paper dossiers into the digital DMS archive.
**Trigger:** Organisation undertakes a digitalisation project or paper archive must be cleared from office space
**Desired outcome:** Digitised records are stored with complete metadata, linked to the correct retention schedule, and verified for authenticity
**Current pain:** Scanning workflows lack automated metadata extraction, requiring extensive manual indexing that is error-prone and time-consuming
**Frequency:** ad-hoc

### Water Board General Assembly Meeting
Water board general assembly meets at least 6x/year. Sets policy, approves budgets, oversees executive board. Chaired by dijkgraaf. Secretary-director maintains minutes.
**Trigger:** Scheduled general assembly meeting (minimum 6x/year)
**Desired outcome:** Policy decisions made, budgets approved, oversight exercised, complete minutes maintained
**Current pain:** Similar needs as municipalities but different governance; fewer commercial RIS options; unique stakeholder categories
**Frequency:** Minimum 6 times per year (general assembly); bi-weekly (executive board)

### Enrich Metadata on Legacy Documents
A records manager identifies documents with incomplete or incorrect metadata and performs bulk or individual remediation to bring them into compliance with the current metadata standard.
**Trigger:** Metadata quality audit reveals gaps, or a system migration exposes missing fields
**Desired outcome:** Target document set has complete, validated metadata meeting the current schema, with changes logged in the audit trail
**Current pain:** No bulk metadata editing capability; remediation must be done document by document, making large-scale enrichment projects impractical
**Frequency:** ad-hoc

### Press Coverage & Media Access
Journalists access council meetings, documents, and officials for reporting. Includes press pass registration, gallery access, post-meeting briefings, and RIS research.
**Trigger:** Newsworthy item on council agenda or need for background research
**Desired outcome:** Journalist can efficiently access all public council information, attend meetings, contact officials
**Current pain:** Complex registration; limited press facilities; restrictions on filming/quoting; delayed recordings; declining journalism resources
**Frequency:** Continuous (more intense around plenary meetings)

### RIS Platform Migration & Transition
Migrating from one RIS to another including data migration of historical records, user transition, integration reconfiguration, and training.
**Trigger:** Contract expiration, dissatisfaction with current RIS, or need for new features
**Desired outcome:** Successful migration with complete data transfer, minimal disruption, and satisfied users
**Current pain:** Complex data migration from proprietary formats; risk of data loss; user resistance; training needs; vendor lock-in
**Frequency:** Every 4-8 years (contract cycles)

### Open Data Publication (ORI)
Publishing council information as open data following the ORI standard. API access, linked data, integration with PLOOI. Over 100 Dutch municipalities participate.
**Trigger:** New meeting data or documents are published in the RIS
**Desired outcome:** Council data available as structured open data via standardized API, compliant with ORI and Woo
**Current pain:** Vendor-specific connectors required; inconsistent data quality; evolving standard; limited real-time capability
**Frequency:** Continuous (automated sync)

### Handle Internal Document Access Request
A records manager processes a request from a colleague who needs access to a restricted document, verifies authorisation, grants temporary access, and logs the disclosure.
**Trigger:** Staff member requests access to a document outside their normal access level
**Desired outcome:** Access is granted or refused with documented justification, access is time-limited, and the disclosure is logged for audit purposes
**Current pain:** Access requests are handled via email with no structured approval workflow or automatic expiry of granted access
**Frequency:** weekly

### Presidium Agenda Setting
The presidium (faction leaders + chair) meets to set the agenda. Decides which items go to which committee, hamerstuk vs bespreekstuk, and overall meeting planning.
**Trigger:** Scheduled presidium meeting at start of each meeting cycle
**Desired outcome:** Approved agenda with clear assignment of items to committees and plenary
**Current pain:** Manual compilation of agenda proposals; difficulty balancing workload across committees
**Frequency:** Every meeting cycle (approximately every 3-4 weeks)

### System Integration & Data Exchange
Managing integrations between RIS and other systems: zaaksysteem, DMS, AV/webcast, archiving, PLOOI platform.
**Trigger:** New system implementation, upgrade, or integration requirement
**Desired outcome:** Seamless data flow between RIS and connected systems with no manual re-entry
**Current pain:** Legacy APIs; proprietary interfaces; vendor lock-in; complex AV integration; multiple auth systems
**Frequency:** Ongoing (initial setup + continuous maintenance)

### Faction Position Coordination
Faction leader coordinates positions on upcoming agenda items through faction meetings. Distributes preparatory work, aligns voting positions, assigns spokespersons.
**Trigger:** Publication of agenda for upcoming council/committee meeting
**Desired outcome:** Aligned faction positions on all agenda items, assigned spokespersons, coordinated motion/amendment strategy
**Current pain:** Fragmented communication tools; no shared workspace; difficulty tracking faction-wide positions across multiple agenda items
**Frequency:** Weekly during active meeting periods

### AV & Webcast Infrastructure Management
Managing AV infrastructure in raadzaal and commissiekamers. Discussion systems, PTZ cameras, AV control, webcast/streaming, microphone management.
**Trigger:** Meeting scheduled requiring AV support; system maintenance/upgrade
**Desired outcome:** Reliable AV infrastructure supporting meetings with high-quality recording and streaming
**Current pain:** Complex multi-vendor systems; AV-RIS integration for indexing; maintenance windows; high costs; rapid tech evolution
**Frequency:** Every meeting (setup/support) + periodic maintenance

### Migrate Historic Email Archive to DMS
A records manager migrates an existing email archive (e.g. PST files or Exchange mailboxes) into the DMS, extracting metadata and linking emails to cases where possible.
**Trigger:** Email system migration, compliance audit, or information management policy requiring all official correspondence to be in the DMS
**Desired outcome:** Email archive is ingested with metadata, duplicates removed, and records linked to cases; archive is searchable from the DMS
**Current pain:** Email archives exist in isolated mailboxes or PST files that are unsearchable, unretained, and not linked to any case or policy dossier
**Frequency:** ad-hoc

## Stakeholders (60 linked)

### Board Secretary / Company Secretary
Corporate governance professional who manages all governance processes, board meetings, minutes, compliance, and stakeholder communication. Guardian of governance procedures and compliance.
**Responsibilities:** Preparing board packs and agendas, taking and distributing minutes, managing governance calendar, ensuring quorum, advising on governance procedures, maintaining corporate registers, coordinating AGM logistics, filing with KVK/AFM
**Pain points:** Manual board pack assembly, version control of sensitive documents, tracking action items across multiple boards and committees, ensuring timely distribution, managing multiple meeting calendars, paper-based minute signing processes
**Goals:** Digital board portal with secure document distribution, automated meeting scheduling and reminders, electronic minute approval workflows, integrated governance calendar, real-time action item tracking

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

### Works Council Representative
Representative of the works council (Ondernemingsraad), which has advisory and consent rights on major decisions in companies with 50+ employees. Has nomination rights for supervisory board under structure regime.
**Responsibilities:** Exercising advisory rights (adviesrecht) on strategic decisions, consent rights (instemmingsrecht) on HR policies, nominating supervisory board members (structure regime), attending shareholder meetings, reviewing major governance decisions
**Pain points:** Receiving governance information too late for meaningful input, limited access to decision-making timelines, difficulty tracking which decisions require works council involvement, inadequate digital tools for OR governance
**Goals:** Timely access to proposed decisions requiring OR advice/consent, digital workflow for adviesrecht and instemmingsrecht processes, transparent governance timeline visibility, secure communication with supervisory board

### HR Manager
Manages human resource decisions including hiring approvals, organizational changes, and HR policy. Participates in MT for people-related decisions.
**Responsibilities:** ["Processing hiring approval requests through the chain", "Advising MT on organizational restructuring decisions", "Managing HR policy drafting and approval process", "Coordinating consultation with works council (OR)", "Handling sensitive personnel decisions with proper governance", "Ensuring compliance with labor law in decisions"]
**Pain points:** ["Hiring approvals delayed by multi-layer approval chain", "Works council consultation timeline not tracked", "Policy decisions scattered across emails and documents", "No structured workflow for organizational change decisions", "Sensitive decisions require extra confidentiality controls"]
**Goals:** ["Streamlined hiring approval workflow", "Policy lifecycle management from draft to implementation", "Confidential decision handling with access controls", "Works council consultation tracking and deadlines"]

### Secretary / Management Assistant
Supports meeting preparation, minutes, action tracking, and decision documentation. Central role in the operational mechanics of organizational decision-making.
**Responsibilities:** ["Preparing meeting agendas and distributing materials", "Taking minutes and documenting decisions", "Tracking action items and follow-up on deadlines", "Managing the meeting calendar and room bookings", "Distributing decision outcomes to stakeholders", "Maintaining the decision register and archives"]
**Pain points:** ["Chasing people for agenda items before deadlines", "Minutes distributed but not read or actioned", "Action items tracked in spreadsheets with no visibility", "No standardized format for decision documentation", "Difficulty linking decisions across multiple meetings", "Version control issues with meeting documents"]
**Goals:** ["Digital agenda management with submission workflow", "Automated action item tracking with reminders", "Searchable decision register across all meetings", "Template-based meeting minutes with decision highlighting"]

### Board Secretary
Responsible for administrative duties including convocations, minutes (notulen), member administration, and correspondence. Key role in ALV preparation — must ensure proper notification timelines and agenda distribution per BW 2:41.
**Responsibilities:** ["Send ALV convocations within statutory deadlines", "Prepare and distribute meeting agendas and documents", "Take and distribute meeting minutes (notulen)", "Maintain member register and voting rights", "Handle correspondence and archives", "Verify quorum and proxy votes (volmachten)", "Manage besluitenlijst (decision register)"]
**Pain points:** ["Manual tracking of proxy votes and quorum", "Ensuring all members receive convocation in time", "Producing accurate minutes under time pressure", "Managing document versions for agenda items", "Tracking action items from multiple meetings"]
**Goals:** ["Paperless meeting preparation and distribution", "Automated quorum and voting calculations", "Searchable archive of decisions and minutes", "Streamlined member communication"]

### Board Member
General board member participating in board decisions and governance. May hold specific portfolios. Under WBTR, limited multiple voting rights — no single board member may outvote all others combined.
**Responsibilities:** ["Participate in board meetings and decisions", "Manage assigned portfolio or committees", "Attend and contribute to ALV", "Support board chair and fellow board members", "Act in interest of the association (not personal)", "Report on portfolio activities"]
**Pain points:** ["Volunteer time constraints", "Accessing meeting documents and decisions from previous meetings", "Understanding personal liability under WBTR", "Onboarding as new board member without institutional knowledge"]
**Goals:** ["Efficient board meetings with clear outcomes", "Easy access to all board documents and history", "Clear task assignments and follow-up tracking"]

## Other App Entities (do NOT redefine)

ActionItem, AgendaItem, Amendment, Area, ContactDetail, Decision, DigitalDocument, GovernanceBody, Meeting, Membership, Minutes, MonetaryAmount, Motion, Offer, Order, Participant, Person, Post, Product, Report, Speech, Vote, VotingRound

## Company-Wide Architecture Rules (17 ADRs)

These rules are MANDATORY for all Conduction apps.

### ADR-001-data-layer
- ALL domain data → OpenRegister objects. NO custom Entity/Mapper for domain data.
- App config → `IAppConfig`. NOT OpenRegister.
- Cross-entity references: OpenRegister relations (register+schema+objectId). NO foreign keys.
  MUST NOT store foreign keys or embed full objects.

### Schema standards

- Schemas: PascalCase, schema.org vocabulary, explicit types + required flags + description field.
- MUST NOT invent custom property names when a schema.org equivalent exists.
- Contact schemas MUST align with vCard properties (fn, email, tel, adr).
- Dutch government fields SHOULD use a mapping layer translating between international standards
  and Dutch specs — do not hardcode Dutch field names as primary.
- Schema changes that remove or rename properties are BREAKING. Adding optional properties is non-breaking.

### Register templates

- Location: `lib/Settings/{app}_register.json` (OpenAPI 3.0 + `x-openregister` extensions).
- Three template categories:
  - **App configuration** — define data models (schemas/registers/views/mappings).
    Mark with `x-openregister.type: "application"`.
  - **Mock data** — fictional but realistic seed data for dev/test.
    Mark with `x-openregister.type: "mock"`.
  - **Government standards** — aligned to Dutch API specs (BAG, BRP, KVK, DSO).
- Import mechanism: `ConfigurationService::importFromApp(appId, data, version, force)` →
  `ImportHandler::importFromApp()`. Called from repair step or `SettingsLoadService`.
- Idempotency: re-importing with `force: false` MUST NOT create duplicates. Match by slug
  using `ObjectService::searchObjects` with `_rbac: false` and `_multitenancy: false`.
  Use `version_compare` for skip logic.

### Seed data

Apps that store data in OpenRegister are empty on first install. An empty app cannot be
meaningfully tested — there are no objects to view, search, filter, or interact with.
This blocks both automated browser testing and manual QA. The Loadable Register Template
pattern (see Register templates above) already supports seed data via `components.objects[]`
with the `@self` envelope.

**Requirements:**

- Every app using OpenRegister MUST include 3-5 realistic objects per schema in
  `lib/Settings/{app}_register.json`.
- Use `@self` envelope: `{ "@self": { "register": ..., "schema": ..., "slug": ... }, ...properties }`.
  Register/schema MUST match keys; slug is unique human-readable identifier for matching.
- Use general organisation data (municipality, consultancy, travel agency, non-profit) —
  NOT context-specific. Varied, realistic field values.
- Mock data quality: real Dutch street names, valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`),
  correct municipality/KVK codes, BSNs that pass 11-proef. Fictional but distinguishable from real.
- Cross-register consistency: BRP→BAG, KVK→BAG, DSO→BAG references must be valid.
- Loaded on install alongside schemas via same `importFromApp()` pipeline.
- MUST be idempotent — re-importing skips existing objects matched by slug.

**In OpenSpec artifacts:**

- **In design.md**: MUST include a Seed Data section when change introduces/modifies schemas —
  define seed objects per schema with concrete field values and related items (files, notes, tasks, contacts).
- **In tasks.md**: MUST include a seed data generation task when change introduces/modifies schemas.

**Exceptions** (no seed data required):

- **nldesign** — has no OpenRegister schemas.
- **ExApp sidecar wrappers** (openklant, opentalk, openzaak, valtimo, n8n-nextcloud) — proxy
  external services and do not use OpenRegister.
- **nextcloud-vue** — shared library, no seed data applicable.
- Changes that only modify frontend components or non-schema backend logic (e.g., settings,
  permissions) do not require seed data.

**Limitations:** OpenRegister's `ImportHandler` currently supports only flat seed objects.
Related items (files, notes, tasks, contacts) linked through the relation system are tracked
on the product roadmap. Until then, seed data is limited to object properties defined in schemas.

### Deduplication check

- Before proposing new capability: search `openspec/specs/` and `openregister/lib/Service/` for overlap
  with ObjectService, RegisterService, SchemaService, ConfigurationService, and shared Vue components.
- If similar capability exists: MUST reference it and explain why new code is needed rather than extending.
- Proposals duplicating existing functionality without justification MUST be rejected.
- **In design.md**: MUST include a "Reuse Analysis" section listing existing OpenRegister services leveraged.
- **In tasks.md**: MUST include a "Deduplication Check" task verifying no overlap — document findings
  even if "no overlap found".

### Schema migrations

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
- Calendar events — `CalendarEventService`
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
- API calls: `axios` from `@nextcloud/axios` — auto-attaches CSRF token. NEVER raw `fetch()` for mutations.
  Loading state with `try/finally`.
- Translations: ALL user-visible strings via `t(appName, 'text')`. NO hardcoded strings.
  Translation keys MUST be English — Dutch translations go in `l10n/nl.json`.
- CSS: ONLY Nextcloud CSS variables (`var(--color-primary-element)`, etc.). NO hardcoded colors.
  NEVER reference `--nldesign-*` directly — nldesign app handles theming.
- Router: history mode, base `generateUrl('/apps/{app}/')`. Requires matching PHP routes in `routes.php`.
  Deep link URL templates MUST match the router mode — use path format (`/apps/{app}/entities/{uuid}`),
  NOT hash format (`/apps/{app}/#/entities/{uuid}`).
- OpenRegister dependency: settings returns `openRegisters` (bool) + `isAdmin`.
  Show empty state if OR missing. NEVER use `OC.isAdmin` — get from backend.
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog` (WCAG, theming).
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API or store.
- EVERY `await store.action()` call MUST be wrapped in `try/catch` with user-facing error feedback.
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports all
  NC components plus Conduction components. This ensures consistent theming and component versions.
- EVERY component used in `<template>` MUST be imported AND registered in `components: {}`.
  Vue 2 silently renders unknown elements — missing imports cause invisible runtime failures.

### NL Design System

- ALL UI components MUST use CSS custom properties from NL Design System tokens.
- MUST support theme switching via nldesign app's token sets.
- MUST meet WCAG AA compliance: keyboard-navigable, associated labels, color is not the sole
  method of conveying information.
- SHOULD work on 320px–1920px viewports; critical functionality MUST work at 768px (tablet).
- Exceptions: PDF generation (docudesk), admin-only screens (simpler styling allowed).

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
  Footer: `NcAppNavigationSettings` (gear foldout) with admin/config nav items.
  Settings item emits `@click="$emit('open-settings')"` — opens `NcAppSettingsDialog` modal.
  Do NOT route to `/settings` — in-app settings is a modal overlay, not a page.

**Dashboard:** `CnDashboardPage` with `CnStatsBlock` KPIs (4 cards: open/overdue/value/completed),
  status distribution chart, "My Work" list (grouped: overdue → due this week → rest).
  Fetch all collections in parallel via `Promise.all`. Widget templates via `#widget-{id}` slots.

**Index page:** `CnIndexPage` with `useListView(entityType, { sidebarState, objectStore })`.
  Inject sidebarState. Row click → `$router.push({ name: 'EntityDetail', params: { id } })`.
  Add button → new entity detail with id='new'.

**Detail page:** Two modes — edit (form component) / view (`CnDetailPage` + `CnDetailCard` sections).
  Header actions: Edit + Delete buttons. Related entities in table inside `CnDetailCard`.
  Props: `entityId` from route. `isNew = entityId === 'new'`. Sidebar via `CnObjectSidebar`.
  **Relations:** Every entity referenced in the spec MUST have a `CnDetailCard` section.
  Use `fetchUsed` for reverse lookups (find objects that reference THIS entity) and
  `fetchUses` for forward lookups (find objects THIS entity references).
  If the spec lists a "linked X section", it MUST be implemented — not deferred or stubbed.

**Settings — two surfaces, never a route:**
  *Admin settings* (`/settings/admin/{appid}`): `AdminRoot.vue` rendered by `settings.js` entry point,
  registered via `AdminSettings.php`. Layout: `CnVersionInfoCard` (FIRST) → `CnRegisterMapping` →
  `CnSettingsSection` per feature. Load via `GET /api/settings`, save via `POST /api/settings`.
  *In-app settings*: `UserSettings.vue` wrapping `NcAppSettingsDialog` — opened as a modal from the
  gear menu (`@open-settings` event on MainMenu), handled in `App.vue` with `:open` / `@update:open`.
  Do NOT create a `/settings` route. Do NOT create a standalone `SettingsView.vue` page component.

**Router:** Flat routes (no nesting), all named, props via arrow function for params.
  Routes: `/` (Dashboard), `/{entities}` (list), `/{entities}/:id` (detail).
  No `/settings` route — settings is a modal (see Settings section above).

**Store init:** `initializeStores()` in `store/store.js` — fetches settings, then calls
  `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each entity.
  Object store uses `createObjectStore` with plugins (files, auditTrails, relations).
  Settings store: Pinia `defineStore` with `fetchSettings()` and `saveSettings()`.

### ADR-005-security
- Auth: Nextcloud built-in ONLY. NO custom login, sessions, tokens, password storage.
- Admin check: `IGroupManager::isAdmin()` on BACKEND. Frontend-only checks = vulnerability.
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable).
- Identity: always derive from `IUserSession` on backend — NEVER trust frontend-sent user IDs or display names.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.
- Test collections: NEVER commit default credentials — use env variable placeholders.

### ADR-006-metrics
- Every app: `GET /api/metrics` (Prometheus text, admin auth) + `GET /api/health` (JSON, public).
- Metric names: `{app}_` prefix. MUST include `{app}_health_status` and `{app}_info`.
- Health check MUST verify OpenRegister connectivity (for apps that depend on it).

### ADR-007-i18n
# ADR-007: Internationalization (i18n)

## Status
Accepted

## Context
All Conduction Nextcloud apps serve Dutch government users but must support multiple languages. We need a consistent approach to internationalization across all apps.

## Decision

### Primary Language: English
- **English (en) is the source/primary language** for all code and translation keys.
- All `t()` keys and `$this->l10n->t()` strings MUST be written in English.
- `l10n/en.json` is the identity-mapped source file (key == value).
- Hardcoded Dutch strings in code MUST be converted to English keys with Dutch translations in `nl.json`.

### Required Languages
- Minimum: English (en) + Dutch (nl) translations.
- `l10n/en.json` and `l10n/nl.json` MUST exist in every app with a UI.
- Both files MUST contain exactly the same keys, with zero gaps.

### Frontend Translation
- JS: `t(appName, 'key')` for singular, `n(appName, 'singular', 'plural', count)` for plurals.
- `Vue.mixin({ methods: { t, n } })` for Options API components.
- `<script setup>` components MUST import `t` directly from `@nextcloud/l10n` (mixin does not apply).

### Backend Translation
- PHP: `$this->l10n->t('key')` for user-facing messages in JSONResponse.
- Controllers returning user-facing messages MUST inject `OCP\IL10N`.
- Log messages, internal exceptions, and database values are NOT translated.

### API and Data
- API field names: always English (language-neutral data layer).
- Date/number formatting: respect user locale via Nextcloud core.
- Each app with OpenRegister: define `register-i18n` spec listing translatable fields.

## Consequences
- All apps maintain two translation files that must stay in sync.
- Dutch strings used as translation keys (e.g., `t('app', 'Besluiten')`) are a violation — the English equivalent must be the key.
- New features must include both `en.json` and `nl.json` entries before merging.

### ADR-008-testing
- Every new PHP service/controller → PHPUnit tests in `tests/Unit/` (≥3 methods).
- Every new Vue component → test file (if test framework exists).
- Every new API endpoint → Newman/Postman collection in `tests/integration/`.
- Every spec scenario → browser test (GIVEN/WHEN/THEN verified via Playwright).
- All tests MUST pass in `composer check:strict`.
- Integration tests MUST cover error paths (403, 401, 400) — not just happy path (200).
- Test collections: use env variable placeholders for credentials — NEVER hardcode defaults.

### Smoke testing (before opening PR)

After implementing, verify your code actually works — quality gates catch lint/types, not logic:

1. Call each new API endpoint with `curl` — verify response shape and status code
2. Test at least one error path per endpoint (missing param, wrong auth, invalid input)
3. If the spec says a feature is deferred, verify it is NOT registered/enabled
4. If tasks.md marks a task `[x]`, verify it is fully implemented — not a stub or TODO

### Task completeness verification

Before marking a task `[x]` in tasks.md or opening a PR:
- Re-read every task in tasks.md
- For each `[x]` task, verify the implementation exists AND works — not a placeholder
- Stub components, empty relation sections, and TODO comments are NOT complete
- If a task cannot be completed, leave it `[ ]` and explain in the PR description

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
| 1 | **bugfix** | Hydra: fix iteration after review failure | `hydra-builder` | sonnet |
| 2 | **code-review** | Hydra: PR code review | `hydra-reviewer` | sonnet |
| 3 | **security-review** | Hydra: PR security review | `hydra-security` | sonnet |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | sonnet |
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

**Container images:**

| Image | Size | Purpose |
|-------|------|---------|
| `conduction/nextcloud-test:stable31` | 1.5GB | Prebuild NC server + PostgreSQL + OpenRegister (cloned) |
| `hydra-builder:latest` | 1.9GB | Code implementation: NC test env + Claude CLI + PHP + skills |
| `hydra-reviewer:latest` | 1.3GB | Code review: Claude CLI + review skills |
| `hydra-security:latest` | 1.9GB | Security review: Claude CLI + Semgrep + security skills |
| `specter-spec-writer:latest` | ~800MB | Spec generation: Claude CLI + openspec CLI + skills (no PHP) |
| `specter-llm-worker:latest` | ~500MB | Intelligence pipeline: Claude CLI + DB access |

**Credential separation:**
- **Specter:** `concurrentie-analyse/secrets/credentials.json` (work + private tokens)
- **Hydra:** `hydra/secrets/credentials.json` (work token only)

**Token detection:**
- Container mode: uses exit code (0 = success, non-zero checks output for rate limit)
- Local mode: checks output text for "rate limit" / "auth failed" strings

**NC test environment:**
- Prebuild image with PostgreSQL (matches production, not SQLite)
- Builder `COPY --from=conduction/nextcloud-test` at build time
- Entrypoint starts PG + enables OpenRegister at runtime
- Each container gets its own isolated NC+PG instance

**Spec generation flow:**
- `push_spec_pipeline.py` prepares repos in parallel, generates in `specter-spec-writer` containers
- Each spec gets its own container + clone (compartmentalized)
- Dependency tiers control ordering: Phase 1 → Phase 2 → Phase 3 → Phase 4
- Specs with met deps push to development directly (doc-only merge guard)
- Issues created with `yolo` label → Hydra auto-builds, reviews, merges, closes issue

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized per system (Specter has private fallback, Hydra doesn't)
- Container exit code determines token rotation (not mid-session JSONL text)
- Prebuild NC image eliminates 30-60s clone overhead per builder container
- Container images are the unit of deployment — version, test, rollback independently
- ADR-000 convention: every repo's data model is at `openspec/architecture/adr-000-data-model.md`
- `context-brief.md` in each change directory carries intelligence data through the full pipeline

### ADR-014-licensing
- Licence: EUPL-1.2 (European Union Public Licence). SPDX header on every source file.
- `appinfo/info.xml`: MUST use `<licence>agpl</licence>` — Nextcloud app store does not recognise EUPL.
- This is intentional dual-tagging, NOT a conflict. Do NOT change info.xml to eupl. Do NOT flag as review finding.
- PHP: `// SPDX-License-Identifier: EUPL-1.2` after `<?php` opening tag.
- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line.
- JS: `// SPDX-License-Identifier: EUPL-1.2` as first line.
- File header block: `@licence EUPL-1.2`, `@copyright {year} Conduction B.V.`, `@link https://conduction.nl`

### ADR-015-common-patterns
- Common Conduction patterns. These apply to ALL apps. Every item below was found 3+ times
  across multiple code reviews. Get these right during implementation — not after review.
- When fixing any pattern violation, ALWAYS generalize: grep for the same issue across ALL
  files and fix every instance in one pass. Fixing one file while leaving the same issue in
  nine others guarantees another review round.

### OpenRegister ObjectService API
- `findObject($register, $schema, $id)` — 3 positional args, register first
- `findObjects($register, $schema, $params)` — 3 positional args, $params is filter array
- `saveObject($register, $schema, $object)` — 3 positional args, $object is array
- NEVER `getObject($id)` or `saveObject($data)` — those 1-arg signatures do not exist
- When unsure, check the OpenRegister source or existing app code

### Store registration (Vue/Pinia)
- Register each entity type ONCE in `src/store/store.js` via `createObjectStore`
- NEVER register in both `OBJECT_TYPES` and `ENTITY_STORES` — pick one pattern
- Type names: kebab-case (`action-item`), NOT camelCase (`actionItem`)
- Use platform `createObjectStore` — do NOT build custom stores (hand-rolled object.js)

### Authorization enforcement
- ALL mutation endpoints MUST have `IGroupManager::isAdmin()` check on backend
- Settings endpoints: `#[AuthorizedAdminSetting]` or `@RequireAdmin` annotation
- NEVER rely on frontend-only auth — always enforce on backend
- User identity: derive from `IUserSession` — NEVER trust frontend-sent user IDs
- Null dependency checks: throw 503, do NOT silently return empty response

### Error responses
- NEVER return `$e->getMessage()` to API — use static, generic error messages
- Pattern: `catch (\Throwable $e) { return new JSONResponse(['message' => 'Operation failed'], 500); }`
- Log the real error: `$this->logger->error('Context', ['exception' => $e]);`
- Frontend: EVERY `await store.action()` MUST be in `try/catch` with user feedback

### API calls & CSRF
- Use `axios` from `@nextcloud/axios` for ALL API calls — it auto-attaches the CSRF token
- NEVER use raw `fetch()` for mutations — missing requesttoken causes silent 403 failures
- Pattern: `import axios from '@nextcloud/axios'` + `const { data } = await axios.post(url, payload)`

### Vue component imports
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports everything
- EVERY component used in `<template>` MUST be imported AND listed in `components: {}`
- Vue 2 silently renders unknown elements — a missing import = invisible runtime failure
- Pre-commit check: for every `<NcFoo>` or `<CnFoo>` in template, verify the import exists

### SPDX headers (see also ADR-014)
- EVERY new file needs an SPDX header — apply to ALL new files in one pass
- PHP: `// SPDX-License-Identifier: EUPL-1.2` after `<?php`
- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- JS: `// SPDX-License-Identifier: EUPL-1.2` as first line

### Dependency management
- When importing from a package, verify it exists in `package.json` before committing
- `@nextcloud/auth` for `getRequestToken()` — add to dependencies if missing
- Run `npm ci && npm run lint` to catch `n/no-extraneous-import` BEFORE pushing

### Translations (i18n)
- ALL user-visible strings: `this.t('appid', 'text')` in Vue, `$this->l->t('text')` in PHP
- NEVER hardcode Dutch or English strings in templates, CSV headers, or notifications
- NEVER bare `t()` in Vue — always `this.t()` (Options API)

### Data patterns
- Relations: verify `fetchUsed` vs `fetchUses` direction — wrong direction = empty cards
- Lifecycle: use the service's `transitionLifecycle()` — NEVER `saveObject()` directly for status
- Pagination: `_limit: 999` silently undercounts — use proper pagination or document the cap

### Nextcloud UI patterns
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog`
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable)
- Deferred features: if spec says "defer to phase N", do NOT register/enable them in info.xml or anywhere else
- Router: history mode with `generateUrl` base (see ADR-004). Deep link URLs must use path format, NOT hash format.
- Relations: `fetchUsed` = reverse lookup (who references me), `fetchUses` = forward lookup (what do I reference)
- Detail views: every spec-required "linked X section" MUST have a `CnDetailCard` — never stub or omit

### Pre-commit verification (run before EVERY commit)

Before committing, verify your code against these patterns:

1. **SPDX headers**: `grep -rL 'SPDX-License-Identifier' src/ lib/ --include='*.php' --include='*.vue' --include='*.js'`
   → Add headers to EVERY file missing one — all of them, not just one.
2. **ObjectService calls**: `grep -rn 'findObject\|saveObject\|findObjects' lib/ --include='*.php'`
   → Verify every call has 3 positional args: `($register, $schema, $idOrParams)`
3. **Error responses**: `grep -rn 'getMessage()' lib/Controller/ --include='*.php'`
   → Replace any `$e->getMessage()` in JSONResponse with a static error string
4. **Auth checks**: For every POST/PUT/DELETE controller method, verify `IGroupManager::isAdmin()` is called
5. **Store registration**: `grep -rn 'registerObjectType\|OBJECT_TYPES\|ENTITY_STORES' src/`
   → Verify each entity registered exactly once, kebab-case names
6. **Dependencies**: `npm run lint` — catches missing package.json entries
7. **Translations**: `grep -rn "'" src/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` — scan for hardcoded strings
8. **try/catch**: `grep -rn 'await.*Store\.' src/ --include='*.vue'` — verify every store call is wrapped
9. **No raw fetch**: `grep -rn 'fetch(' src/ --include='*.vue' --include='*.js'` — must use `@nextcloud/axios`, not raw fetch (CSRF)
10. **Import source**: `grep -rn "from '@nextcloud/vue'" src/` — must be zero matches. Use `@conduction/nextcloud-vue` instead.
11. **Component imports**: for every `<NcFoo>` or `<CnFoo>` in templates, verify the component is imported AND in `components: {}`
12. **Type slug consistency**: verify every entity type string across ALL files (store, search, routes, views) uses the same kebab-case slug — `grep -rn "agendaItem\|governanceBody\|actionItem" src/` should return zero matches
13. **Translation keys**: `grep -rn "t('.*'," src/ --include='*.vue' --include='*.js'` — verify ALL t() keys are English, not Dutch. Dutch translations go in `l10n/nl.json`.
14. **Route consistency**: verify every entity type referenced in search, navigation, or links has a matching named route in `src/router/`
15. **Task completeness**: re-read tasks.md — every `[x]` task must be fully implemented, not a stub

If ANY check fails, fix ALL instances (not just the first one) before committing.

### ADR-017-component-composition
# ADR-017: Component Composition Rules

## Status
Accepted

## Date
2026-04-14

## Context

Conduction apps share a Vue component library (`@conduction/nextcloud-vue`) that provides self-contained, higher-level components like `CnObjectDataWidget`, `CnStatsPanel`, `CnDetailPage`, and `CnTimelineStages`. These components internally render their own card wrappers (`CnDetailCard`), headers, and layout containers.

Developers have been wrapping these self-contained components inside additional layout containers (e.g. `CnDetailCard` wrapping `CnObjectDataWidget`), producing a "card-in-card" visual artifact where headers and borders are doubled. This was found across Procest, Pipelinq, and earlier OpenCatalogi iterations.

The same principle applies to `CnDetailPage` which renders its own `NcAppContent` wrapper — apps must not add another `NcAppContent` around it.

## Decision

### Self-contained components render their own container

The following components are **self-contained** and MUST NOT be wrapped in `CnDetailCard`, `NcAppContent`, or other layout containers:

| Component | Renders its own | Use directly inside |
|---|---|---|
| `CnObjectDataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnObjectMetadataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnStatsPanel` | Sections with headers | `CnDetailPage` slot or `<div>` |
| `CnDetailPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnDashboardPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnIndexPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnTimelineStages` | Standalone timeline | Inside `CnDetailCard` or any container (no own card) |

### How to identify self-contained components

A component is self-contained if its template root is a card, panel, or page-level wrapper. Check the component source: if it starts with `<CnDetailCard>`, `<div class="cn-*-card">`, or similar, it manages its own container.

### Correct patterns

```vue
<!-- CORRECT: CnObjectDataWidget renders its own card -->
<CnObjectDataWidget
  :schema="schema"
  :object-data="data"
  title="Case Information" />

<!-- CORRECT: CnTimelineStages is NOT self-contained, wrap it -->
<CnDetailCard :title="t('app', 'Status')">
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### Anti-patterns

```vue
<!-- WRONG: Double card wrapping -->
<CnDetailCard :title="t('app', 'Case Information')">
  <CnObjectDataWidget :schema="schema" :object-data="data" />
</CnDetailCard>

<!-- WRONG: Double page wrapping -->
<NcAppContent>
  <CnDetailPage :title="title">...</CnDetailPage>
</NcAppContent>
```

### External sidebar pattern

Components like `CnDetailPage` that support sidebars communicate with a parent-provided `objectSidebarState` via Vue's `provide`/`inject`. The sidebar component (`CnObjectSidebar`) MUST be rendered at the `NcContent` level in `App.vue`, NOT inside `NcAppContent`:

```vue
<!-- App.vue -->
<NcContent app-name="myapp">
  <MainMenu />
  <NcAppContent>
    <router-view />
  </NcAppContent>
  <CnObjectSidebar v-if="objectSidebarState.active" ... />
</NcContent>
```

## Consequences

- Developers must check if a shared component is self-contained before wrapping it
- The component library documents which components are self-contained in their JSDoc headers
- Code reviews should flag card-in-card nesting as a pattern violation
- Existing violations should be fixed when encountered (per ADR-015 pre-existing issues rule)

### ADR-018-widget-header-actions
# ADR-018: Widget Header Actions Pattern

## Status
Accepted

## Date
2026-04-14

## Context

Card and widget components across Conduction apps need action controls (buttons, dropdowns, selects) for user interactions like changing status, adding items, or toggling views. Developers have been placing these controls inline with card content, taking up vertical space and creating inconsistent layouts.

Nextcloud's own UI pattern places actions in the title bar (top-right) of panels and sidebars. Our shared component library should enforce this same pattern so all card/widget components have a consistent location for actions.

## Decision

### All card/widget components MUST support a `header-actions` slot

Every component that renders a title bar or header MUST provide a `header-actions` slot positioned in the **top-right of the header**, inline with the title. This is the standard location for action controls.

### Standard slot name: `header-actions`

All components use the slot name `header-actions` for consistency. Components that previously used `actions` retain it for backwards compatibility but `header-actions` is the canonical name.

### Component support status

All card/widget components in `@conduction/nextcloud-vue` now support `header-actions`:

| Component | Slot name | Notes |
|---|---|---|
| `CnDetailCard` | `header-actions` | Primary card component |
| `CnWidgetWrapper` | `header-actions` | Dashboard widget container |
| `CnObjectDataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnObjectMetadataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnStatsPanel` | `header-actions` | Added in this ADR |
| `CnSettingsCard` | `header-actions` | Added in this ADR |
| `CnConfigurationCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |
| `CnVersionInfoCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |

### What goes in header-actions

- Status change dropdowns / selects
- Add/create buttons
- Toggle switches (e.g. edit mode)
- Refresh buttons
- Filter controls specific to this widget

### What does NOT go in header-actions

- Save/cancel for the entire page (those belong in `CnDetailPage` `#header-actions`)
- Bulk action toolbars (those belong in `CnMassActionBar`)
- Form inputs that are part of the data being edited

### Usage pattern

```vue
<CnDetailCard :title="t('app', 'Status')">
  <template #header-actions>
    <NcSelect
      v-model="selectedStatus"
      :options="statusOptions"
      :placeholder="t('app', 'Change status...')" />
  </template>

  <!-- Card content -->
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### New components

When creating new card or widget components, the `header-actions` slot MUST be included from the start. The standard template pattern:

```vue
<div class="cn-my-widget__header">
  <h4 class="cn-my-widget__title">{{ title }}</h4>
  <div v-if="$slots['header-actions']" class="cn-my-widget__header-actions">
    <slot name="header-actions" />
  </div>
</div>
```

With CSS:
```css
.cn-my-widget__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cn-my-widget__header-actions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}
```

## Consequences

- All existing card components now support `header-actions`
- New components must include this slot from creation
- Existing apps should migrate inline actions to `header-actions` when touching those files
- Code reviews should flag action controls placed in card content as a pattern violation
- The `actions` slot name in CnConfigurationCard and CnVersionInfoCard is deprecated but retained for backwards compatibility

## App-Specific ADRs (5)

These ADRs are specific to Decidesk.

### 000-data-model: ADR-000: Data Model — decidesk
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


### adr-000-data-model: ADR-000: Data Model — Decidesk
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


### adr-001-popolo-data-standard: ADR-001: Popolo as Primary Data Standard
# ADR-001: Popolo as Primary Data Standard

**Status:** accepted
**Date:** 2026-04-16

## Context

DecideDesk models governance concepts (people, organizations, motions, votes, meetings)
that are common across parliaments, councils, boards, and assemblies worldwide. Multiple
standards exist for representing this data:

- **Popolo** (popoloproject.com) — international open data standard for political/governance
  information, used by projects like EveryPolitician, OpenAustralia, and as the foundation
  for the Dutch ORI standard
- **Schema.org** — general-purpose vocabulary, too broad for governance specifics
- **Akoma Ntoso** — OASIS standard for legislative documents (complementary, not competing)
- **Custom schemas** — app-specific, non-interoperable

## Decision

DecideDesk adopts **Popolo as its primary data standard**. Every entity in the data model
either maps directly to a Popolo class or is explicitly documented as an extension.

### Popolo classes implemented

| Popolo Class | DecideDesk Entity | Storage |
|---|---|---|
| Person | Person | OpenRegister |
| Organization | GovernanceBody | OpenRegister |
| Membership | Membership | OpenRegister |
| Post | Post | OpenRegister |
| ContactDetail | ContactDetail | OpenRegister |
| Motion | Motion | OpenRegister |
| VoteEvent | VotingRound | OpenRegister |
| Vote | Vote | OpenRegister |
| Count | (fields on VotingRound) | OpenRegister |
| Event | Meeting | CalDAV VEVENT |
| Area | Area | OpenRegister |
| Speech | Speech | OpenRegister |

### Extensions beyond Popolo

These entities are not in Popolo but are needed for governance workflows:

| Entity | Source | Rationale |
|---|---|---|
| AgendaItem | ORI standard | Structured agenda with ordering, types, durations |
| Amendment | ORI standard | Subclass of Motion with `amends` relation |
| Minutes (Report) | ORI standard | Official meeting record |
| ActionItem | Custom | Follow-up tasks from adopted motions |

### Key design choices

1. **No separate Decision entity.** Popolo has no Decision class. A decision is the
   outcome of a Motion (lifecycle: adopted/rejected + decisionText fields). This avoids
   redundant entities and matches how ORI and Popolo model outcomes.

2. **Person + Membership separation.** Popolo separates identity (Person) from
   organizational relationships (Membership). One person can be a member of multiple
   bodies with different roles. The previous Participant entity merged these incorrectly.

3. **Post for formal positions.** Popolo Post represents positions (Chair, Secretary)
   that exist independently of who fills them. This enables vacancy tracking and
   succession planning.

4. **Property naming follows Popolo conventions** in the API layer, with camelCase
   variants in PHP/JavaScript code. The ADR-000 data model documents both.

## Consequences

- ORI API output is a thin serialization of existing entities, not a complex mapping
- Data is interoperable with 265+ Dutch municipalities using ORI (which is Popolo-based)
- International governance projects can consume DecideDesk data without custom adapters
- New Popolo classes (e.g. future standards additions) can be adopted incrementally
- Speech entity deferred to later phase — placeholder in data model, not yet implemented


### adr-002-caldav-first-storage: ADR-002: CalDAV-First Storage Architecture
# ADR-002: CalDAV-First Storage Architecture

**Status:** accepted
**Date:** 2026-04-16

## Context

DecideDesk manages meetings (scheduling, lifecycle, attendance) and action items (tasks
assigned from decisions). The initial design stored everything in OpenRegister and synced
to Nextcloud Calendar via a CalendarEventService. This created:

1. **A sync layer** that must be maintained, debugged, and kept consistent
2. **Duplicate data** — meeting data in OpenRegister AND in Calendar
3. **Poor user experience** — meetings don't appear in Calendar until sync runs
4. **Missed integration** — Nextcloud Tasks app can't see action items

The previous design referenced a `CalendarEventService` for syncing — this service is
eliminated entirely by the CalDAV-first approach.

Meanwhile, Nextcloud already has a full CalDAV server (sabre/dav) that stores VEVENTs
and VTODOs natively, supports RFC 5545 X-properties for custom metadata, and preserves
them in round-trip (raw ICS blob stored in `calendarobjects` table).

## Decision

**CalDAV is the primary storage for meetings and action items.** OpenRegister stores
only governance-specific entities that have no CalDAV equivalent.

### What lives in CalDAV

| Entity | CalDAV Type | Standard Fields | X-DECIDESK-* Fields |
|---|---|---|---|
| Meeting | VEVENT | SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION, ATTENDEE, STATUS | LIFECYCLE, MEETING-TYPE, MEETING-MODE, QUORUM-REQUIRED, SERIES, BODY-UID |
| ActionItem | VTODO | SUMMARY, DESCRIPTION, DUE, STATUS, COMPLETED, ATTENDEE | MOTION-UID, MEETING-UID |

### What lives in OpenRegister

Everything else: Motion, Amendment, VotingRound, Vote, GovernanceBody, Person,
Membership, Post, ContactDetail, Area, AgendaItem, Minutes, Speech.

### OpenRegister wrapper objects

For relational queries (e.g. "all agenda items for meeting X"), OpenRegister holds thin
wrapper objects that store the CalDAV UID as a reference. The wrapper contains:
- `caldavUid` — the VEVENT/VTODO UID
- `calendarId` — the Nextcloud calendar ID
- Relations to other OpenRegister entities

The wrapper does NOT duplicate CalDAV data. To get meeting details, the app reads the
VEVENT via CalDAV. The wrapper exists solely for OpenRegister's relational query engine.

### CalDAV service layer

A `CalDavService` PHP class wraps Nextcloud's `\OCA\DAV\CalDAV\CalDavBackend` for:
- Creating/updating/deleting VEVENTs and VTODOs
- Reading X-DECIDESK-* properties from ICS blobs via sabre/vobject
- Managing a dedicated "DecideDesk" calendar per governance body
- ATTENDEE management mapped from Person/Membership entities

### X-DECIDESK-* property registry

All extended properties use the `X-DECIDESK-` prefix per RFC 5545 Section 3.8.8.2:

| Property | VEVENT/VTODO | Values | Description |
|---|---|---|---|
| X-DECIDESK-LIFECYCLE | VEVENT | draft, scheduled, opened, paused, adjourned, closed | Meeting state machine |
| X-DECIDESK-MEETING-TYPE | VEVENT | regular, extraordinary, committee, public-hearing | Meeting classification |
| X-DECIDESK-MEETING-MODE | VEVENT | in-person, digital, hybrid | Attendance mode |
| X-DECIDESK-QUORUM-REQUIRED | VEVENT | integer | Minimum attendees |
| X-DECIDESK-SERIES | VEVENT | string | Series identifier |
| X-DECIDESK-BODY-UID | VEVENT | uuid | GovernanceBody reference |
| X-DECIDESK-MOTION-UID | VTODO | uuid | Source motion reference |
| X-DECIDESK-MEETING-UID | VTODO | string | Source meeting CalDAV UID |

## Consequences

- **No sync layer** — meetings are native Calendar events, tasks are native Tasks
- **Users see meetings immediately** in their Nextcloud Calendar alongside personal events
- **Action items appear in Nextcloud Tasks** app without any integration code
- **CalDAV interop** — meetings sync to any CalDAV client (Thunderbird, iOS, Android)
- **X-properties are preserved** by any CalDAV-compliant client (RFC 5545 requirement)
- **OpenRegister queries** still work via wrapper objects for governance-specific joins
- **Migration needed** for existing Meeting/ActionItem data → CalDAV objects


### adr-003-ori-compatibility: ADR-003: ORI Compatibility Endpoint
# ADR-003: ORI Compatibility Endpoint

**Status:** accepted
**Date:** 2026-04-16

## Context

Open Raadsinformatie (ORI) is the Dutch open data standard for municipal council
information, maintained by VNG Realisatie and Open State Foundation. 265 of 345 Dutch
municipalities publish council data via ORI. The standard is based on Popolo with
Dutch-specific extensions (AgendaItem, Amendment, Report, Committee).

DecideDesk follows Popolo as its primary data standard (ADR-001). Since ORI is a
superset of Popolo, compatibility is straightforward.

## Decision

DecideDesk exposes an **ORI-compatible REST API endpoint** as an addition to its
standard API. The core architecture follows Popolo (international); ORI is a Dutch
municipal output format.

### Endpoint structure

```
/api/ori/v1/organizations       → GovernanceBody as ORI Organization
/api/ori/v1/persons             → Person as ORI Person
/api/ori/v1/memberships         → Membership as ORI Membership
/api/ori/v1/events              → Meeting (from CalDAV) as ORI Event/Meeting
/api/ori/v1/agendaitems         → AgendaItem as ORI AgendaItem
/api/ori/v1/motions             → Motion as ORI Motion
/api/ori/v1/amendments          → Amendment as ORI Amendment
/api/ori/v1/voteevents          → VotingRound as ORI VoteEvent
/api/ori/v1/votes               → Vote as ORI Vote
/api/ori/v1/reports             → Minutes as ORI Report
```

### Entity mapping

| DecideDesk Entity | ORI/Popolo Class | Key Differences |
|---|---|---|
| GovernanceBody | Organization / Committee | `bodyType` → `classification` |
| Person | Person | Direct mapping |
| Membership | Membership | Direct mapping |
| Meeting (CalDAV) | Meeting / Event | Read from CalDAV, map X-properties |
| AgendaItem | AgendaItem | `orderNumber` → `position` |
| Motion | Motion | `lifecycle` → `status`, `proposer` → `creator` |
| Amendment | Amendment | `amends` relation to parent Motion |
| VotingRound | VoteEvent | Counts expanded to separate Count objects |
| Vote | Vote | `value` → `option` |
| Minutes | Report | Direct mapping |

### What the ORI endpoint does NOT do

- It does not change the internal data model — Popolo is the source of truth
- It does not store data in ORI format — it serializes on read
- It does not implement the full ORI harvesting protocol — that requires a
  separate adapter (e.g. for Open State Foundation's crawler)

## Consequences

- Dutch municipalities can consume DecideDesk data via the standard ORI API
- DecideDesk appears in ORI-compatible tooling and dashboards
- The endpoint is a thin read-only serialization layer, not a separate data store
- International users ignore the ORI endpoint and use the standard Popolo-aligned API
- Future: ORI harvesting adapter can push data to the national ORI aggregator


## App Architecture ADRs from Repo (4 files)

These ADR files live in decidesk/openspec/architecture/.

### ADR-000-data-model
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

### ADR-001-popolo-data-standard
# ADR-001: Popolo as Primary Data Standard

**Status:** accepted
**Date:** 2026-04-16

## Context

DecideDesk models governance concepts (people, organizations, motions, votes, meetings)
that are common across parliaments, councils, boards, and assemblies worldwide. Multiple
standards exist for representing this data:

- **Popolo** (popoloproject.com) — international open data standard for political/governance
  information, used by projects like EveryPolitician, OpenAustralia, and as the foundation
  for the Dutch ORI standard
- **Schema.org** — general-purpose vocabulary, too broad for governance specifics
- **Akoma Ntoso** — OASIS standard for legislative documents (complementary, not competing)
- **Custom schemas** — app-specific, non-interoperable

## Decision

DecideDesk adopts **Popolo as its primary data standard**. Every entity in the data model
either maps directly to a Popolo class or is explicitly documented as an extension.

### Popolo classes implemented

| Popolo Class | DecideDesk Entity | Storage |
|---|---|---|
| Person | Person | OpenRegister |
| Organization | GovernanceBody | OpenRegister |
| Membership | Membership | OpenRegister |
| Post | Post | OpenRegister |
| ContactDetail | ContactDetail | OpenRegister |
| Motion | Motion | OpenRegister |
| VoteEvent | VotingRound | OpenRegister |
| Vote | Vote | OpenRegister |
| Count | (fields on VotingRound) | OpenRegister |
| Event | Meeting | CalDAV VEVENT |
| Area | Area | OpenRegister |
| Speech | Speech | OpenRegister |

### Extensions beyond Popolo

These entities are not in Popolo but are needed for governance workflows:

| Entity | Source | Rationale |
|---|---|---|
| AgendaItem | ORI standard | Structured agenda with ordering, types, durations |
| Amendment | ORI standard | Subclass of Motion with `amends` relation |
| Minutes (Report) | ORI standard | Official meeting record |
| ActionItem | Custom | Follow-up tasks from adopted motions |

### Key design choices

1. **No separate Decision entity.** Popolo has no Decision class. A decision is the
   outcome of a Motion (lifecycle: adopted/rejected + decisionText fields). This avoids
   redundant entities and matches how ORI and Popolo model outcomes.

2. **Person + Membership separation.** Popolo separates identity (Person) from
   organizational relationships (Membership). One person can be a member of multiple
   bodies with different roles. The previous Participant entity merged these incorrectly.

3. **Post for formal positions.** Popolo Post represents positions (Chair, Secretary)
   that exist independently of who fills them. This enables vacancy tracking and
   succession planning.

4. **Property naming follows Popolo conventions** in the API layer, with camelCase
   variants in PHP/JavaScript code. The ADR-000 data model documents both.

## Consequences

- ORI API output is a thin serialization of existing entities, not a complex mapping
- Data is interoperable with 265+ Dutch municipalities using ORI (which is Popolo-based)
- International governance projects can consume DecideDesk data without custom adapters
- New Popolo classes (e.g. future standards additions) can be adopted incrementally
- Speech entity deferred to later phase — placeholder in data model, not yet implemented

### ADR-002-caldav-first-storage
# ADR-002: CalDAV-First Storage Architecture

**Status:** accepted
**Date:** 2026-04-16

## Context

DecideDesk manages meetings (scheduling, lifecycle, attendance) and action items (tasks
assigned from decisions). The initial design stored everything in OpenRegister and synced
to Nextcloud Calendar via a CalendarEventService. This created:

1. **A sync layer** that must be maintained, debugged, and kept consistent
2. **Duplicate data** — meeting data in OpenRegister AND in Calendar
3. **Poor user experience** — meetings don't appear in Calendar until sync runs
4. **Missed integration** — Nextcloud Tasks app can't see action items

The previous design referenced a `CalendarEventService` for syncing — this service is
eliminated entirely by the CalDAV-first approach.

Meanwhile, Nextcloud already has a full CalDAV server (sabre/dav) that stores VEVENTs
and VTODOs natively, supports RFC 5545 X-properties for custom metadata, and preserves
them in round-trip (raw ICS blob stored in `calendarobjects` table).

## Decision

**CalDAV is the primary storage for meetings and action items.** OpenRegister stores
only governance-specific entities that have no CalDAV equivalent.

### What lives in CalDAV

| Entity | CalDAV Type | Standard Fields | X-DECIDESK-* Fields |
|---|---|---|---|
| Meeting | VEVENT | SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION, ATTENDEE, STATUS | LIFECYCLE, MEETING-TYPE, MEETING-MODE, QUORUM-REQUIRED, SERIES, BODY-UID |
| ActionItem | VTODO | SUMMARY, DESCRIPTION, DUE, STATUS, COMPLETED, ATTENDEE | MOTION-UID, MEETING-UID |

### What lives in OpenRegister

Everything else: Motion, Amendment, VotingRound, Vote, GovernanceBody, Person,
Membership, Post, ContactDetail, Area, AgendaItem, Minutes, Speech.

### OpenRegister wrapper objects

For relational queries (e.g. "all agenda items for meeting X"), OpenRegister holds thin
wrapper objects that store the CalDAV UID as a reference. The wrapper contains:
- `caldavUid` — the VEVENT/VTODO UID
- `calendarId` — the Nextcloud calendar ID
- Relations to other OpenRegister entities

The wrapper does NOT duplicate CalDAV data. To get meeting details, the app reads the
VEVENT via CalDAV. The wrapper exists solely for OpenRegister's relational query engine.

### CalDAV service layer

A `CalDavService` PHP class wraps Nextcloud's `\OCA\DAV\CalDAV\CalDavBackend` for:
- Creating/updating/deleting VEVENTs and VTODOs
- Reading X-DECIDESK-* properties from ICS blobs via sabre/vobject
- Managing a dedicated "DecideDesk" calendar per governance body
- ATTENDEE management mapped from Person/Membership entities

### X-DECIDESK-* property registry

All extended properties use the `X-DECIDESK-` prefix per RFC 5545 Section 3.8.8.2:

| Property | VEVENT/VTODO | Values | Description |
|---|---|---|---|
| X-DECIDESK-LIFECYCLE | VEVENT | draft, scheduled, opened, paused, adjourned, closed | Meeting state machine |
| X-DECIDESK-MEETING-TYPE | VEVENT | regular, extraordinary, committee, public-hearing | Meeting classification |
| X-DECIDESK-MEETING-MODE | VEVENT | in-person, digital, hybrid | Attendance mode |
| X-DECIDESK-QUORUM-REQUIRED | VEVENT | integer | Minimum attendees |
| X-DECIDESK-SERIES | VEVENT | string | Series identifier |
| X-DECIDESK-BODY-UID | VEVENT | uuid | GovernanceBody reference |
| X-DECIDESK-MOTION-UID | VTODO | uuid | Source motion reference |
| X-DECIDESK-MEETING-UID | VTODO | string | Source meeting CalDAV UID |

## Consequences

- **No sync layer** — meetings are native Calendar events, tasks are native Tasks
- **Users see meetings immediately** in their Nextcloud Calendar alongside personal events
- **Action items appear in Nextcloud Tasks** app without any integration code
- **CalDAV interop** — meetings sync to any CalDAV client (Thunderbird, iOS, Android)
- **X-properties are preserved** by any CalDAV-compliant client (RFC 5545 requirement)
- **OpenRegister queries** still work via wrapper objects for governance-specific joins
- **Migration needed** for existing Meeting/ActionItem data → CalDAV objects

### ADR-003-ori-compatibility
# ADR-003: ORI Compatibility Endpoint

**Status:** accepted
**Date:** 2026-04-16

## Context

Open Raadsinformatie (ORI) is the Dutch open data standard for municipal council
information, maintained by VNG Realisatie and Open State Foundation. 265 of 345 Dutch
municipalities publish council data via ORI. The standard is based on Popolo with
Dutch-specific extensions (AgendaItem, Amendment, Report, Committee).

DecideDesk follows Popolo as its primary data standard (ADR-001). Since ORI is a
superset of Popolo, compatibility is straightforward.

## Decision

DecideDesk exposes an **ORI-compatible REST API endpoint** as an addition to its
standard API. The core architecture follows Popolo (international); ORI is a Dutch
municipal output format.

### Endpoint structure

```
/api/ori/v1/organizations       → GovernanceBody as ORI Organization
/api/ori/v1/persons             → Person as ORI Person
/api/ori/v1/memberships         → Membership as ORI Membership
/api/ori/v1/events              → Meeting (from CalDAV) as ORI Event/Meeting
/api/ori/v1/agendaitems         → AgendaItem as ORI AgendaItem
/api/ori/v1/motions             → Motion as ORI Motion
/api/ori/v1/amendments          → Amendment as ORI Amendment
/api/ori/v1/voteevents          → VotingRound as ORI VoteEvent
/api/ori/v1/votes               → Vote as ORI Vote
/api/ori/v1/reports             → Minutes as ORI Report
```

### Entity mapping

| DecideDesk Entity | ORI/Popolo Class | Key Differences |
|---|---|---|
| GovernanceBody | Organization / Committee | `bodyType` → `classification` |
| Person | Person | Direct mapping |
| Membership | Membership | Direct mapping |
| Meeting (CalDAV) | Meeting / Event | Read from CalDAV, map X-properties |
| AgendaItem | AgendaItem | `orderNumber` → `position` |
| Motion | Motion | `lifecycle` → `status`, `proposer` → `creator` |
| Amendment | Amendment | `amends` relation to parent Motion |
| VotingRound | VoteEvent | Counts expanded to separate Count objects |
| Vote | Vote | `value` → `option` |
| Minutes | Report | Direct mapping |

### What the ORI endpoint does NOT do

- It does not change the internal data model — Popolo is the source of truth
- It does not store data in ORI format — it serializes on read
- It does not implement the full ORI harvesting protocol — that requires a
  separate adapter (e.g. for Open State Foundation's crawler)

## Consequences

- Dutch municipalities can consume DecideDesk data via the standard ORI API
- DecideDesk appears in ORI-compatible tooling and dashboards
- The endpoint is a thin read-only serialization layer, not a separate data store
- International users ignore the ORI endpoint and use the standard Popolo-aligned API
- Future: ORI harvesting adapter can push data to the national ORI aggregator
