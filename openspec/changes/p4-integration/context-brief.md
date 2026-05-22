# Context Brief: Integration

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p4-integration
**Platform:** Nextcloud + OpenRegister

**Depends on:** p3-governance-bodies, p3-citizen-participation, p3-document-management

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer > Integraties / Beheer

**Rationale:** Outbound integrations layer  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Dependency Specs (content)

These specs were already decided/implemented. Use them as context.

### p3-governance-bodies
# Context Brief: Governance Bodies

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p3-governance-bodies
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
**Spec Type:** foundation
**Platform:** Nextcloud + OpenRegister

## Features (6 total, spec-linked, sorted by market demand)

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
**Current pain:** Complex multi-ve
... (truncated)

### p3-citizen-participation
# Context Brief: Citizen Participation

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p3-citizen-participation
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
**Spec Type:** foundation
**Platform:** Nextcloud + OpenRegister

## Features (6 total, spec-linked, sorted by market demand)

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
**Current pain:** Complex 
... (truncated)

### p3-document-management
# Context Brief: Document Management

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p3-document-management
**Spec Type:** capability
**Platform:** Nextcloud + OpenRegister

**Depends on:** p2-minutes-and-decisions, p2-motion-and-voting, p2-agenda-management, p2-meeting-management

## Dependency Specs (content)

These specs were already decided/implemented. Use them as context.

### p2-minutes-and-decisions
# Context Brief: Minutes and Decisions

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p2-minutes-and-decisions
**Spec Type:** capability
**Platform:** Nextcloud + OpenRegister

**Depends on:** p1-schemas-and-data-model, p1-dashboard-and-navigation, p1-crud-operations

## Dependency Specs (content)

These specs were already decided/implemented. Use them as context.

### p1-schemas-and-data-model
# Context Brief: Schemas and Data Model

**App:** Decidesk — Universal decision-making platform for governance bodies, associations, corporate boards, and operational meetings
**Spec:** p1-schemas-and-data-model
**Spec Type:** foundation
**Platform:** Nextcloud + OpenRegister

## Features (6 total, spec-linked, sorted by market demand)

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
**Desired outcome:** Reliable AV infrastructure supporting meetings with high-quality re
... (truncated)

## Features (1 total, sorted by market demand)

### reverse-proxy
**demand: 8** | Category: general
Discovered from GitHub issue in [competitor]/[competitor]

## User Stories (1 linked)

### Story 1: Integrate drip sequences with n8n workflows
**Priority:** should
As a marketing automation specialist, I want to connect drip sequences to n8n workflows, so that I can incorporate external data sources and complex logic into my automation.

**Acceptance Criteria:**
Promotiq exposes webhook triggers and actions for n8n; n8n can add/remove subscribers, trigger sequences, and query stats; bidirectional data flow

## Customer Journeys (15 linked)

### Define Typographic Scale for Government App
The UX designer defines a token-based typographic scale — font families, sizes, weights, and line heights — that aligns with Rijkshuisstijl guidelines and maps cleanly onto Nextcloud's heading and body text components.
**Trigger:** A new government Nextcloud app is being designed and requires a typography specification
**Desired outcome:** A complete typographic token set is defined, documented, and applied consistently across all Nextcloud text components without hardcoded font values
**Current pain:** Rijkshuisstijl uses the RO Sans typeface, which must be self-hosted due to licensing; integrating custom web fonts with Nextcloud's CSP and asset pipeline is complex and undocumented
**Frequency:** ad-hoc

### Map NL Design System Components to Nextcloud UI
The UX designer creates a mapping document that links each NL Design System component variant to its Nextcloud UI counterpart, identifying gaps and proposing token-level bridges for mismatches.
**Trigger:** A new app is being designed for a government Nextcloud deployment and must use NL Design System components
**Desired outcome:** A living component map that developers can consult to implement government-compliant styling, with documented workarounds for components that cannot be directly mapped
**Current pain:** No official mapping exists between NL Design System and nextcloud-vue components; designers and developers independently re-derive the same mappings on each project
**Frequency:** ad-hoc

### Design Citizen-Facing Login Page
The UX designer creates a branded login page that aligns with the municipality's visual identity and meets NL Design System component guidelines, then hands off token specifications to the developer for implementation.
**Trigger:** A new citizen-facing digital service is launched on the Nextcloud platform
**Desired outcome:** The login page uses approved NL Design System components, passes WCAG AA, and visually matches the municipality's style guide without custom CSS
**Current pain:** Nextcloud's login page has limited customisation hooks and the nldesign token integration does not yet cover all login page elements, forcing CSS overrides
**Frequency:** ad-hoc

### Align Theme with Common Ground Principles
The architect ensures the nldesign app's architecture and data model align with Common Ground principles — data at source, open standards, and vendor independence — particularly for token storage and distribution.
**Trigger:** The organisation commits to a Common Ground-compliant infrastructure review
**Desired outcome:** Token sets are stored in open formats (DTCG JSON), served via standards-based APIs, and portable between Common Ground-compatible platforms
**Current pain:** Token configuration is stored in Nextcloud's proprietary database schema, making it non-portable and creating vendor lock-in that conflicts with Common Ground principles
**Frequency:** ad-hoc

### Expose Catalog Data via Federation API
The developer configures the catalog's federation endpoint so that peer catalog instances (other municipalities or national registries) can harvest component data from this instance.
**Trigger:** A federation partner requests access to harvest components published by this catalog node
**Desired outcome:** Federation endpoint is live, authenticated, and returning well-formed publiccode.yml data to authorized consumers
**Current pain:** Federation protocol details are underdocumented; each integration requires custom negotiation between technical teams
**Frequency:** ad-hoc

### Integrate Catalog into Procurement Portal
The developer builds an integration between OpenCatalogi's API and a procurement or marketplace portal so that buyers can search and compare catalog components directly within their existing tooling.
**Trigger:** Procurement portal owner requests a catalog data feed or embedded search widget
**Desired outcome:** Catalog search results are accessible within the procurement portal with accurate, real-time data and deep links back to full component pages
**Current pain:** The catalog API lacks stable versioning and consistent authentication; integrators must handle frequent breaking changes without warning
**Frequency:** ad-hoc

### Connect HR Capacity Data
The ICT architect establishes a data feed from the HR system to planiq so that employee availability, FTE allocations, and leave are always current in resource planning.
**Trigger:** Resource planning inaccuracies linked to stale HR data, or new HR system deployed
**Desired outcome:** HR data automatically available in planiq within 24 hours of changes, with no manual synchronisation step
**Current pain:** HR and project planning run on separate systems with no integration; availability data is always out of date
**Frequency:** ad-hoc

### Expose Portfolio Data via API
The ICT architect configures planiq to publish portfolio and project data via an open API so that BI tools, ministerial reporting systems, and other platforms can consume it.
**Trigger:** Management requests automated feeds to a BI dashboard or external accountability system
**Desired outcome:** Portfolio data available via a documented, authenticated REST API consumed by at least one downstream system without manual exports
**Current pain:** Data consumers rely on periodic Excel exports sent by email, causing latency and version management problems
**Frequency:** ad-hoc

### Set Default Department Dashboard Template
The system administrator defines a baseline dashboard template that is applied to all new users in a department or role group. Individual users can still personalise within allowed bounds.
**Trigger:** Onboarding a new department cohort or rolling out a new application organisation-wide
**Desired outcome:** New users start with a relevant, role-appropriate dashboard on first login rather than a blank or entirely generic screen
**Current pain:** No templating mechanism means administrators either leave new users with unhelpful defaults or manually configure each account
**Frequency:** ad-hoc

### Integrate with Financial System
The ICT architect configures a live integration between planiq and the organisational ERP or financial administration system to synchronise budget and spend data automatically.
**Trigger:** New implementation project starts or financial data reconciliation problems escalate
**Desired outcome:** Budget and actuals synchronised automatically on a daily basis, eliminating manual re-keying and reconciliation errors
**Current pain:** Financial data must be exported from ERP and manually imported into project tools, causing delays and data quality issues
**Frequency:** ad-hoc

### Find API-Compatible Components for Integration
The developer searches the catalog by API standard or protocol (e.g., ZGW, NLX) to identify components that can be integrated into an existing solution architecture.
**Trigger:** Architecture design session requires selecting external components that comply with a specific API standard
**Desired outcome:** Developer identifies a shortlist of compatible components with linked API documentation and version history
**Current pain:** API compatibility metadata is missing or inconsistently described in publiccode.yml files, forcing manual investigation of GitHub repositories
**Frequency:** weekly

### Manage User Dashboard Permissions and Widget Library
The system administrator controls which widgets are available to which user roles, approves new widgets for the shared library, and revokes access to widgets connected to systems a role group should not access.
**Trigger:** A new data-connected widget is developed, a role changes scope, or a security review flags unauthorised data access through a widget
**Desired outcome:** Every user can only access widgets appropriate to their role and authorisation level, with no manual exceptions required
**Current pain:** Widget availability is either all-or-nothing; fine-grained role-based widget control requires custom development rather than configuration
**Frequency:** ad-hoc

### Download and Use Open Dataset
A citizen, journalist, or researcher searches the open data portal, previews a dataset, and downloads it in their preferred format to use in their own analysis.
**Trigger:** User has a research question or journalistic investigation that requires municipal data.
**Desired outcome:** Dataset found, previewed, and downloaded in CSV/JSON/GeoJSON within minutes, with clear licence and contact information.
**Current pain:** Datasets are published with minimal metadata and no preview; users cannot assess quality before downloading, leading to wasted effort.
**Frequency:** ad-hoc

### Compose Complete Case Dossier
A case handler assembles all documents related to a case into a structured dossier within the DMS, ensuring completeness before the case is closed or transferred.
**Trigger:** Case approaches closure or a dossier completeness check is triggered by the case management system
**Desired outcome:** Case dossier contains all required document types with correct metadata, is ordered per the dossier structure, and is locked for archiving
**Current pain:** No automated completeness check; case handlers must manually verify dossier contents against a checklist, often discovering gaps too late
**Frequency:** weekly

### Configure External System Widget Connections
The system administrator connects dashboard widgets to external APIs such as the zaaksysteem, HR system, financial system, and document management platform, configuring authentication and data mapping.
**Trigger:** Deploying mydash to a new department or integrating a newly procured back-office system
**Desired outcome:** Widgets pull live data from source systems without requiring individual users to manage credentials or API settings
**Current pain:** Each integration requires custom development and manual credential management; no standardised widget connector framework exists
**Frequency:** ad-hoc

## Stakeholders (14 linked)

### Case Handler
Manages active cases (zaken) and is responsible for linking all relevant documents to the correct case in the case management system. Works at the intersection of the DMS and the zaaksysteem.
**Responsibilities:** linking documents to cases via ZGW APIs, tracking document status per case, requesting missing documents from applicants, ensuring case dossier completeness, preparing dossiers for decisions
**Pain points:** manual linking of documents to cases, duplicate uploads across systems, no real-time sync between DMS and zaaksysteem, difficulty identifying which documents are still missing for a case
**Goals:** automatic document-to-case linking via ZGW APIs, complete and up-to-date digital case dossier, notifications when required documents arrive, one-click dossier export for decision-making

### Citizen / Open Data User
Member of the public, journalist, researcher, or civic tech developer who accesses municipal open data for transparency, journalistic investigation, or application development. An external stakeholder with no direct system access.
**Responsibilities:** downloading and analysing municipal datasets, building civic applications on open data APIs, holding the municipality accountable through data journalism
**Pain points:** datasets published in non-machine-readable formats (PDF, Excel), metadata missing or outdated, no API access requiring bulk file downloads, long delays between data updates and publication
**Goals:** access fresh machine-readable datasets via a stable REST or SPARQL API, understand data provenance and update frequency, reuse data without legal uncertainty

### ICT Architect
Responsible for the technical integration of planiq with the broader government IT landscape, including connections to HR, financial, and ticketing systems. Ensures data consistency and compliance with Common Ground and government interoperability standards.
**Responsibilities:** integration architecture design, API configuration, data governance, Common Ground compliance, system interoperability, security and privacy by design
**Pain points:** proprietary integrations that break with upgrades, lack of open API standards, difficulty integrating with legacy government systems, GDPR compliance gaps in data exchange
**Goals:** open standards-based integrations (REST/OpenAPI), Common Ground compatibility, secure and auditable data exchange, low-maintenance connector architecture

### System Administrator
IT professional responsible for configuring, maintaining, and integrating the Nextcloud environment and connected government applications. Manages user access and system integrations.
**Responsibilities:** configuring widget integrations, managing API connections to government systems, user provisioning, troubleshooting integration failures, maintaining uptime
**Pain points:** complex configuration required for each system integration, lack of centralised monitoring of integration health, manual reconfiguration when external APIs change
**Goals:** connect external government systems to the dashboard with minimal configuration, monitor integration health from a single interface, reduce manual maintenance effort

### Integration Developer
Builds integrations between OpenCatalogi and other government systems such as procurement platforms, architecture tools, and service portals.
**Responsibilities:** consume catalog APIs for integration use cases, implement webhooks and event subscriptions, build connectors to architecture or procurement tooling, report API issues
**Pain points:** API documentation is incomplete, breaking changes without versioning notice, inconsistent pagination and filtering behavior, no sandbox environment for development
**Goals:** build reliable integrations with stable well-documented APIs, access catalog data programmatically without workarounds, stay informed about API changes before they break integrations

### House Style Coordinator
Maintains and enforces the official visual identity of a government organization across all digital and print channels. Acts as the gatekeeper for brand consistency in digital platforms.
**Responsibilities:** managing design token definitions, approving color and typography changes, liaising with national Rijkshuisstijl guidelines, onboarding new token sets for municipal brands
**Pain points:** token updates in one system not propagating to Nextcloud, no audit trail for who changed which token, manual synchronization between Figma and production CSS, unclear versioning of token sets
**Goals:** single source of truth for all design tokens, automated distribution of token updates to Nextcloud, visual validation that applied tokens match approved brand guidelines

### UX Designer (Government)
Designs user interfaces for government digital services using NL Design System components. Bridges the gap between design system specifications and actual Nextcloud component implementations.
**Responsibilities:** designing screens in Figma using NL Design System component library, specifying token values per component state, reviewing implemented components against design specs, identifying component gaps in the Nextcloud theme
**Pain points:** Nextcloud components not matching NL Design System component specifications, no Figma-to-Nextcloud token sync, interactive states (hover, focus, active) inconsistently styled, limited component variants available in Nextcloud
**Goals:** 1:1 parity between Figma NL Design components and Nextcloud rendered output, token-driven component states for all interactive elements, design handoff without manual CSS annotation

### Common Ground Architect
Designs and governs the technical architecture of Common Ground compliant digital government services. Ensures Nextcloud integrations and UI components adhere to Common Ground principles and interoperability standards.
**Responsibilities:** defining technical standards for Common Ground compliant interfaces, reviewing Nextcloud app integrations for standards compliance, advising on API-first and component reuse principles, participating in VNG standardization working groups
**Pain points:** NL Design System adoption fragmented across Common Ground components, no clear standard for how Nextcloud apps should expose design token extension points, inconsistent application of government design standards across the Common Ground component ecosystem
**Goals:** NL Design System as the mandated UI standard across all Common Ground Nextcloud apps, published integration spec for token-based theming in Common Ground context, reusable styled component library shared across all government Nextcloud deployments

### API Integration Specialist
Technical specialist managing the connections between the case handling frontend and backend ZGW API components (Zaken, Documenten, Catalogi, Besluiten, Notificaties).
**Responsibilities:** configuring and monitoring ZGW API endpoints, troubleshooting failed case or document synchronisation, managing API tokens and authorisation scopes, coordinating with suppliers on breaking changes
**Pain points:** opaque API error messages that reach end users, no centralised logging of failed ZGW transactions, tight coupling between frontend releases and backend API versions
**Goals:** ensure zero data loss on case mutations, proactively detect integration failures before users notice, maintain clear API dependency documentation

### Middleware Engineer
Implements and maintains protocol adapters and message translation components that bridge legacy SOAP/StUF systems with modern REST and ZGW APIs in the government integration layer.
**Responsibilities:** develop and test protocol adapters, maintain WSDL and OpenAPI specifications in sync, implement message queue integrations, support gRPC connectors, document protocol quirks
**Pain points:** StUF schema variations between municipalities require custom workarounds per installation, no automated regression suite for protocol adapters, legacy systems have undocumented edge cases that surface only in production
**Goals:** maintain a single tested adapter per protocol with configurable municipality profiles, automate protocol conformance testing, gradually migrate legacy SOAP endpoints to REST without disruption

## Other App Entities (do NOT redefine)

ActionItem, AgendaItem, Amendment, Area, ContactDetail, Decision, DigitalDocument, GovernanceBody, Meeting, Membership, Minutes, MonetaryAmount, Motion, Offer, Order, Participant, Person, Post, Product, Report, Speech, Vote, VotingRound

## Company-Wide Architecture Rules (23 ADRs)

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
- Per-object authorization (IDOR prevention): every mutation endpoint that operates on a specific
  object MUST check that the authenticated user owns, is in the group of, or is admin for THAT
  object — not just that they are logged in. `#[NoAdminRequired]` opens the endpoint to all users;
  without a per-object check, any user can modify any object by guessing its ID.
  Pattern: fetch object → extract `assigneeUserId`/`assigneeGroupId`/`createdBy` → check
  (owner OR in group OR admin) → throw `OCSForbiddenException` if none apply. Extract into a
  reusable `authorizeXxx(object, user)` service method, called from every PUT/POST/DELETE.
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable).
- Identity: always derive from `IUserSession` on backend — NEVER trust frontend-sent user IDs or display names.
- Nextcloud endpoint defaults: NO annotation = admin-only. Non-admin endpoints (agent/staff actions)
  MUST have `#[NoAdminRequired]` attribute. Pair every `#[NoAdminRequired]` with a per-object auth
  check — never trust the session alone for mutation.
- **Auth attribute must match the method's actual requirement** (semantic consistency, not just
  syntactic presence — observed 2026-04-23 on decidesk#44 where the builder satisfied the route-
  auth gate by adding `#[NoAdminRequired]` to a method whose body calls `requireAdmin()`):
  - `#[PublicPage]` — genuinely public; body MUST NOT call `requireAdmin()`, `isAdmin()`, or
    return `Http::STATUS_UNAUTHORIZED/FORBIDDEN` conditionally. Use for login pages, OAuth
    callbacks, public manifests.
  - `#[NoAdminRequired]` — any authenticated user allowed; body MUST carry a per-object auth
    check (ADR-005 Rule 3 / `hydra-gate-no-admin-idor`). Body MUST NOT call `requireAdmin()` —
    that semantics belongs on `#[AuthorizedAdminSetting]` instead.
  - `#[AuthorizedAdminSetting(Application::APP_ID)]` — admin-only, framework-enforced at the
    middleware layer. Preferred for methods that call `requireAdmin()` / `isAdmin()` in body;
    lifts the check out of the controller into the routing table where it is declarative
    and grep-able.
  - No annotation — admin-only by Nextcloud default; prefer the explicit
    `#[AuthorizedAdminSetting]` for clarity.
  Enforcement: `hydra-gate-semantic-auth` (gate-9) catches common mismatches (`NoAdminRequired`
  + `requireAdmin()` body, `PublicPage` + body auth check). Gate-5 remains syntactic-only
  (attribute present); gate-9 is the semantic layer.
- Input validation: all user-supplied strings that flow into URLs (query params, path segments)
  MUST be URL-encoded (`encodeURIComponent` in Vue/JS, `rawurlencode` in PHP). Email Message-IDs,
  file names, and free-text fields commonly contain `<`, `>`, `/`, `@`, `&` which break unencoded.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.
- Error messages: use static, generic messages (`'Operation failed'`, `'Not authorized'`) — NEVER
  return `$e->getMessage()` to clients. Log the real error server-side with `$this->logger->error()`.
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

### Sentence Case for All UI Strings
- All translation keys and user-facing strings MUST use **sentence case**: only the first word is capitalized.
- Correct: `"Add directory"`, `"No results found"`, `"Delete selected"`, `"Save configuration"`
- Wrong (title case): `"Add Directory"`, `"No Results Found"`, `"Delete Selected"`
- Wrong (all lowercase): `"add directory"`, `"no results found"`
- **Exceptions** that keep their capitalization:
  - Proper nouns and product names: `"OpenRegister"`, `"Nextcloud"`, `"GitHub"`, `"DocuDesk"`
  - Acronyms: `"API"`, `"URL"`, `"PDF"`, `"SOLR"`, `"JSON"`, `"RBAC"`, `"OAS"`
  - Single-word strings still start with a capital: `"Delete"`, `"Search"`, `"Save"`

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

### Shared Component Library (@conduction/nextcloud-vue)
- The shared library does NOT translate internally — it accepts pre-translated strings via props.
- Components have English defaults for all label/text props (e.g., `addLabel="Add"`, `cancelLabel="Cancel"`).
- Consumer apps are responsible for passing `t()` results as prop values.
- The library lists `@nextcloud/l10n` as a peer dependency, not a direct dependency.

## Consequences
- All apps maintain two translation files that must stay in sync.
- Dutch strings used as translation keys (e.g., `t('app', 'Besluiten')`) are a violation — the English equivalent must be the key.
- Title case in translation keys (e.g., `"Add Directory"`) is a violation — use sentence case (`"Add directory"`).
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

| Priority | Type | Source | Container image | Model | Fallback |
|----------|------|--------|-----------------|-------|----------|
| 1 | **code-review** | Hydra: PR code review + in-container fixes | `hydra-reviewer` | sonnet | opus |
| 2 | **security-review** | Hydra: PR security review + in-container fixes | `hydra-security` | sonnet | opus |
| 3 | **applier** | Hydra: binary go/no-go gate (no fix authority) | `hydra-applier` | sonnet | opus |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | haiku | — |
| 5 | **audit** | Hydra: codebase audit | `hydra-builder` | sonnet | opus |
| 6 | **spec-generation** | Specter: push_spec_pipeline | `specter-llm-worker` | sonnet | haiku |
| 7 | **schema-synthesis** | Specter: generate/dedup schemas | `specter-llm-worker` | haiku | — |
| 8 | **classification** | Specter: classify/redistribute features | `specter-llm-worker` | haiku | — |
| 9 | **translation** | Specter: translate requirements | `specter-llm-worker` | haiku | — |
| 10 | **discovery** | Specter: research, feature extraction | `specter-llm-worker` | haiku | — |

**No-loop policy (openspec/changes/no-loop-review-pipeline):** Reviewers own fix
authority. The Applier is a read-only final gate that emits a binary pass/fail
verdict — it never modifies files. Every post-review outcome is terminal:
merge (on `applier:pass` or reviews passed with zero fixes) or `needs-input`
(on `applier:fail`, reviewer `agent-maxed-out`, or post-review deterministic
check failure). There is no fix-iteration loop and no `bugfix` container.

### Model strategy

**Principle:** Use the cheapest model that can do the job. Reserve expensive models for judgment work.

| Work type | Model | Rationale |
|-----------|-------|-----------|
| Build (implementation) | **Haiku** | Clear instructions (tasks.md, design.md). Pattern-following, not judgment. Faster and cheaper — 5 parallel Haiku builds burn far less quota than Sonnet. |
| Fix-quality / fix-browser (pre-review) | **Haiku** | "Fix this PHPCS error" or "fix this browser test failure" — explicit, targeted corrections triggered by deterministic check output during the build phase. |
| Code review (+ in-container fix authority) | **Sonnet → Opus** | Judgment + bounded fixes. Sonnet is the primary; falls back to Opus when Sonnet quota exhausted. Budget: 40 turns (up from 20) to cover review + self-verified fixes. |
| Security review (+ in-container fix authority in PR mode) | **Sonnet → Opus** | Critical: injection vectors, auth bypasses, secret leaks. Same fallback logic. Budget: 40 turns in PR mode, 120 in full-audit mode (audit mode has no fix authority). |
| Applier (Axel Pliér) | **Sonnet → Opus** | Final binary go/no-go. No fix tools. Reads hydra.json + PR state + ADRs, emits `{pass, blocking[]}`. Budget: 20 turns. |
| Audit | **Sonnet → Opus** | Full codebase analysis — needs depth. |

**Quota optimization:** Claude Max plans have separate "Sonnet only" and "all models" weekly limits. By defaulting builders to Haiku, the Sonnet quota is reserved for reviews only (~20 turns each, 2 per PR). When Sonnet runs out, reviews fall back to the **deeper** model (Opus), not the shallower one — because reviews are the last line of defense before human approval.

**Overrides:** Set `HYDRA_BUILDER_MODEL`, `HYDRA_REVIEWER_MODEL`, or `HYDRA_REVIEWER_FALLBACK_MODEL` env vars to change defaults.

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
| `hydra-reviewer:latest` | 1.3GB | Code review + bounded in-container fix authority (Juan Claude van Damme) |
| `hydra-security:latest` | 1.9GB | Security review + bounded in-container fix authority (Clyde Barcode) |
| `hydra-applier:latest` | 1.0GB | Binary go/no-go gate; no Write/Edit tools (Axel Pliér) |
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

### Container capability profiles

Each container persona runs with a different Linux capability set determined by the trust we extend to it. This is load-bearing for runtime behaviour — a container's `/workspace` is ONLY writable by the claude user if the build or the entrypoint arranges it, and the two code paths diverge based on cap profile.

| Persona | Caps added | Claude user | Workspace setup |
|---------|-----------|-------------|-----------------|
| Builder | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Dropped via `gosu` at run time | Entrypoint chowns at start, relies on DAC_OVERRIDE |
| Reviewer | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same as builder | Same — entrypoint chown |
| Security | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same | Same |
| **Applier** | **None** (minimum-cap — read-only judge) | **Runs as `claude:claude` via `docker --user`** (no gosu drop possible — can't setuid without SETUID) | **Must be pre-chowned at IMAGE BUILD TIME** — no runtime chown possible |

**The applier's minimum-cap profile has a hard consequence:** its Dockerfile MUST contain
```dockerfile
RUN mkdir -p /workspace && chown claude:claude /workspace && chmod 0775 /workspace
```
before the `WORKDIR /workspace` directive. Otherwise the non-root claude user cannot write files into its own workdir, `hydra_prefetch_pr_context` silently fails every redirect, Claude runs 0 turns, and the orchestrator records `pass=null, turns=0 → applier:fail`. Observed on decidesk#44 2026-04-23 06:01 UTC — looked like a harness bug, real cause was one missing `chown` line in the Dockerfile.

This is **the rule for any future minimum-cap persona**: if you drop DAC_OVERRIDE + SETUID for security reasons, the Dockerfile owns workspace ownership — the entrypoint cannot.

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized per system (Specter has private fallback, Hydra doesn't)
- Container exit code determines token rotation (not mid-session JSONL text)
- Prebuild NC image eliminates 30-60s clone overhead per builder container
- Container images are the unit of deployment — version, test, rollback independently
- ADR-000 convention: every repo's data model is at `openspec/architecture/adr-000-data-model.md`
- `context-brief.md` in each change directory carries intelligence data through the full pipeline
- Minimum-cap containers (applier) require Dockerfile-time workspace chown; higher-cap containers can chown at runtime. This split is permanent — don't ship a new minimum-cap persona without pre-chowning.

### ADR-014-licensing
- Licence: EUPL-1.2 (European Union Public Licence).
- `appinfo/info.xml`: MUST use `<licence>agpl</licence>` — Nextcloud app store does not recognise EUPL.
- This is intentional dual-tagging, NOT a conflict. Do NOT change info.xml to eupl. Do NOT flag as review finding.

## PHP files — PHPDoc tags only

License and copyright metadata on PHP files lives **only** in the main file docblock as PHPDoc tags:

```php
<?php

/**
 * Short Description
 *
 * Longer description.
 *
 * @category Controller
 * @package  OCA\{AppName}\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/{change-name}/tasks.md#task-N
 */

declare(strict_types=1);
```

**Required tags on every PHP file:** `@author`, `@copyright`, `@license`, `@link`, `@spec`. File-level `@spec` links back to the OpenSpec change that created or last modified the file (ADR-003). Classes and public methods also carry their own `@spec` tag.

**Do NOT add:**
- `SPDX-FileCopyrightText: ...` lines in the docblock — that duplicates `@copyright`.
- `SPDX-License-Identifier: ...` lines in the docblock — that duplicates `@license`.
- `// SPDX-*` line comments before or after the docblock.

## Vue / JS / CSS files

These file types don't carry PHPDoc. Use SPDX header as the first line:

- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- JS / TS: `// SPDX-License-Identifier: EUPL-1.2`
- CSS / SCSS: `/* SPDX-License-Identifier: EUPL-1.2 */`

## Repo-level REUSE compliance

Every app repo SHOULD carry a `REUSE.toml` at its root declaring license + copyright for every file pattern. This is the authoritative source for REUSE compliance — `reuse lint` reads it instead of requiring per-file SPDX headers for PHP files:

```toml
version = 1

[[annotations]]
path = "**/*.php"
SPDX-FileCopyrightText = "2026 Conduction B.V. <info@conduction.nl>"
SPDX-License-Identifier = "EUPL-1.2"
```

## Hydra quality gate

`scripts/run-quality.sh`'s `spdx-headers` gate enforces: every `lib/**/*.php` file has both `@license` and `@copyright` PHPDoc tags. Missing either fails the gate.

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

### ADR-016-routes
- Routes: `appinfo/routes.php` is the ONLY registration path. NO runtime-registered routes, NO route
  fragments in `info.xml`, NO bootstrapped route providers added from `Application::register()`.
- `info.xml` is app metadata only (name, version, dependencies, categories, screenshots). It must
  never carry `<route>` / `<navigation>` entries that map URLs to controllers.
- Every route entry names `controller#method` explicitly — no wildcard auto-discovery, no regex
  generators. Snake_case controller maps to CamelCase class: `meeting#public_state` →
  `MeetingController::publicState()`. Lowering discoverability is the point: grepping `routes.php`
  returns the full URL surface area of the app.
- Admin settings pages: register the settings section via `\OCP\Settings\ISection` in
  `Application::register()`, but the settings URL itself is a standard `appinfo/routes.php` entry
  pointing at a controller method marked with `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- Public (unauthenticated) endpoints: declare `#[PublicPage]` + `#[NoCSRFRequired]` on the method,
  and keep the route in `appinfo/routes.php` — do not invent a separate public-routes file.
- Rationale: the mechanical gates (`hydra-gate-route-auth`) scan `appinfo/routes.php` only. Every
  endpoint living there gets its auth attribute verified; an endpoint registered elsewhere
  bypasses the gate and can ship to production without its middleware posture checked. One file,
  one gate, no drift.
- Gate layering: `hydra-gate-route-auth` (gate-5) is **syntactic** — it verifies the method
  carries any of the four valid auth attributes (`#[PublicPage]` / `#[NoAdminRequired]` /
  `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting]`). It does NOT check that the chosen attribute
  matches the method's actual requirement. The **semantic** layer is `hydra-gate-semantic-auth`
  (gate-9) which enforces attribute-to-body consistency per ADR-005. Both gates must pass —
  syntactic alone produces the "minimum-to-clear-the-gate" anti-pattern where a builder adds
  the cheapest attribute (`#[NoAdminRequired]`) to a method whose body calls `requireAdmin()`
  just to pass gate-5. See ADR-005 for the full attribute-to-body mapping.
- Migration: any app with routes declared in `info.xml` or injected via `Application::boot()` must
  move them to `appinfo/routes.php` before the next build — the gate treats such endpoints as
  absent, and any related controller method without an auth attribute will surface as a FAIL.

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

### ADR-019-integration-registry
# ADR-019: Integration Registry Pattern

## Status
Proposed

## Date
2026-04-21

## Context

Conduction apps (OpenCatalogi, Procest, Pipelinq, MyDash, Decidesk, DocuDesk, ZaakAfhandelApp, Larpingapp, Softwarecatalog, OpenRegister itself) all consume the same set of "things linked to an object" — files, notes, tasks, calendar events, mail, contacts, deck cards, talk conversations, and an expanding catalogue of NC-ecosystem and external services.

Until now this was implemented in two rigid places:

- `OCA\OpenRegister\Service\LinkedEntityService::TYPE_COLUMN_MAP` — a hardcoded PHP constant naming the 8 supported NC entity types.
- `@conduction/nextcloud-vue::CnObjectSidebar` — a Vue component with 5 hardcoded tabs and inline imports for each.

Adding a new integration required modifying both core OR and the shared component library. External services (OpenProject, XWiki, ...) had no path at all. Of the 8 backend-supported types, only 5 had sidebar UI and only 2 had widget components — a glaring asymmetry that grew worse with every new backend integration that landed without UI.

## Decision

Adopt a **two-sided integration registry** pattern as the canonical mechanism for declaring "things that can be linked to or rendered alongside an OpenRegister object."

### The contract — one provider, three artifacts

Every integration ships a vertical slice declared via:

1. A PHP class implementing `OCA\OpenRegister\Service\Integration\IntegrationProvider` (registered via DI tag `IntegrationProvider`).
2. A frontend registration call `OCA.OpenRegister.integrations.register({ id, label, icon, tab, widget, ... })`.

The two registrations share the same `id` — backend and frontend are paired by id, not by import.

### Three-stage filter

What the user actually sees is decided by three independent filters, each with distinct ownership:

| Stage | Owner | Question |
|---|---|---|
| **Registry** | Provider author (system) | Does this integration exist + is the required NC app installed? |
| **Schema** | Schema author (data designer) | Is this integration relevant to objects of this schema? |
| **Component** | Page author (app developer) | Should this integration appear on THIS surface? |

Stage 1 is `IntegrationRegistry::getEnabled()`. Stage 2 is the schema's `configuration.linkedTypes` whitelist. Stage 3 is the rendering component's `excludeIntegrations` prop (or equivalent layout choice).

Each stage has clear ownership; debugging "why isn't X showing?" walks the three stages in order.

### Widget parity is a hard rule

Registering an integration without **both** a sidebar tab component **and** a card widget component is a CI-enforced failure. The check runs in pre-commit, repository CI, and the hydra quality gate. Tab-only or widget-only integrations are not permitted.

### Four widget surfaces with graceful fallback

Widgets render across four surfaces: `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`. A registered widget receives the `surface` as a prop and may branch internally. Optional surface-specific components (`widgetCompact`, `widgetExpanded`, `widgetEntity`) are used when present. A new surface added in the future falls back to the main `widget` — no re-registration required from existing integrations.

### External integrations route through OpenConnector

Providers may declare `getStorageStrategy() === 'external'` and reference an OpenConnector source. OR's `ExternalIntegrationRouter` handles dispatch + auth-status surfacing. OR does not own credentials — OpenConnector does. The provider declares its `authRequirements()` so OR can show a unified admin UI and surface auth status via OCS capabilities.

### Schema validator is registry-driven

`Schema::validateLinkedTypesValue()` consults `IntegrationRegistry::listIds()` rather than a hardcoded constant. New integrations are immediately valid as `linkedTypes` values without core changes.

### Reference-property auto-rendering

A new schema property marker `referenceType: <integration-id>` causes `CnFormDialog` and `CnDetailGrid` to render the matching integration's `single-entity` widget inline next to the property. The integration registry is the single source of truth for "how to render a linked thing of this type" everywhere it appears, not just in sidebars and dashboards.

## Consequences

### Positive

- **Extensibility**: any Conduction app, third-party integrator, or external-service connector can add an integration without modifying OR core or `@conduction/nextcloud-vue`.
- **Consistency**: every integration is rendered the same way, with the same lifecycle, the same RBAC hooks, the same auth surface, the same parity contract.
- **Discoverability**: integrations are advertised via OCS capabilities — mobile apps, partner integrations, and other NC apps can discover what's available without proprietary endpoints.
- **Parallelism**: leaf changes (one per integration) hang off this contract and run in parallel through hydra's pool. The current backend-vs-UI asymmetry cannot recur — parity is enforced.
- **Future flexibility**: the contract is "linked thing"–shaped so `RelationsService` (object↔object) can be unified under the same registry in a future change without breaking changes.

### Negative

- **Onboarding ceremony**: adding a new integration means more files than before (provider, tab, widget, registration, spec delta, tests). Mitigated by `scripts/scaffold-integration.sh <id>` which generates the skeleton.
- **Bundle discipline**: an integration that fails to register (wrong load order, missed `register()` call) silently vanishes. Mitigated by the parity CI gate catching missing declarations pre-merge and a dev-mode warning when a backend provider has no frontend counterpart.
- **One more abstraction**: developers reading sidebar/dashboard code must understand "why isn't this just a static import?" Mitigated by the developer guide and this ADR.

### Migration risks

- **Schema `linkedTypes` referencing not-yet-registered ids**: handled — validation is permissive on read (warns but doesn't reject), strict on write only when adding.
- **External consumers of `LinkedEntityService::TYPE_COLUMN_MAP`**: the constant is private-by-convention and not documented as public API; we don't expect external consumers. It is `@deprecated` here and removed in a follow-up cleanup change once built-in providers stabilise.
- **`CnObjectSidebar` props/slots**: every existing prop and slot is preserved. Snapshot tests guard against regressions on the 5 existing tabs.

## Companion ADR

This ADR codifies the **mechanism**. A separate companion ADR — **ADR-020: Apps Consume OpenRegister Abstractions** — codifies the broader **principle**: Conduction apps hook into OpenRegister's abstractions (registers, schemas, objects, integrations, RBAC, audit, archival, ...) rather than building parallel mechanisms. ADR-020 is authored separately; ADR-019 is the first concrete instance of that principle being applied systematically.

## Implementation reference

- Umbrella change: `openregister/openspec/changes/pluggable-integration-registry/` (proposal, design, tasks, spec, hydra.json)
- Implementation files: `openregister/lib/Service/Integration/`, `nextcloud-vue/src/integrations/`
- Developer guide: `openregister/docs/integrations/README.md`
- Scaffold script: `openregister/scripts/scaffold-integration.sh`
- Parity check: `openregister/scripts/check-integration-parity.sh`

## References

- ADR-004 — Frontend (Vue 2, axios, components)
- ADR-007 — i18n (nl + en required)
- ADR-010 — NL Design System
- ADR-011 — Schema standards
- ADR-017 — Component composition
- ADR-018 — Widget header actions
- ADR-020 — Apps consume OR abstractions (companion, separate change)

## Ownership

OpenRegister team owns the registry contract, the built-in providers, and the schema validator changes. `@conduction/nextcloud-vue` maintainers own the frontend registry, surface contracts, and the three new widgets. Each integration leaf change has its own owner.

### ADR-020-gate-scope-to-pr-diff
# ADR-020 — Mechanical gates are scoped to the PR diff, not the whole repo

## Context

Hydra's 8 mechanical gates (`scripts/run-hydra-gates.sh`) were authored as repo-wide scanners: every `lib/**.php` file was checked on every pipeline run. This made pre-existing debt in unchanged files block every new PR. Concretely, decidesk#44 / #45 bounced through `code-review:fail → security-review:fail → needs-input` multiple cycles because `lib/Controller/SettingsController.php` (not touched by either PR) had two genuine findings — missing `#[AuthorizedAdminSetting]` on `load()` and missing `STATUS_UNAUTHORIZED` guard on `index()`. The reviewer cannot fix unchanged files in bounded scope, the builder will not re-enter fix mode for someone else's debt, and the applier refuses to override reviewer-fail verdicts. Result: two genuinely-clean PRs stuck in a ping-pong for days.

The reviewer's CLAUDE.md has long instructed Claude to apply the diff scope manually, but that is (a) advisory, not enforced, and (b) wastes turns on every run.

## Decision

Every mechanical gate in `scripts/run-hydra-gates.sh` must honor the `--scope-to-diff [BASE_REF]` flag. When set, the gate iterates only over files added, copied, modified, or renamed (`--diff-filter=ACMR`) between `BASE_REF` (default `origin/development`) and `HEAD`. Inherited debt in unchanged files is documented by a full-repo cleanup PR, not enforced via review blockers on unrelated work.

All four pipeline positions that invoke gates use `--scope-to-diff`:

| Position | Invocation site | Why scope-to-diff |
|---|---|---|
| Builder Rule 0b wrapper | `images/builder/entrypoint.sh` | Builder is creating the PR; the diff is its output. |
| Code reviewer pre-flight | `images/reviewer/entrypoint.sh` | Juan reviews the PR, not the base branch. |
| Code reviewer post-flight | `images/reviewer/entrypoint.sh` | Post-flight gate fails when Juan introduces debt; inherited debt is out of scope. |
| Security reviewer pre-flight | `images/security/entrypoint.sh` | Same rationale as code review. |
| Security reviewer post-flight | `images/security/entrypoint.sh` | Same. |

The applier runs no gates directly — it consumes the reviewers' verdicts, which now reflect scope-correct findings.

Base ref is overridable via the `HYDRA_GATE_BASE_REF` env var (default `origin/development`) for repos with a different mainline.

Gate 4 (`composer-audit`) is skipped entirely when scope-to-diff is active and neither `composer.json` nor `composer.lock` is in the diff — dep vulnerabilities are unchanged if deps are unchanged. Gate 6 (`orphan-auth`) scopes the *defining* file by diff but keeps its caller grep repo-wide so a method newly-added in the PR is still validated against any legitimate same-file or cross-file caller.

## Consequences

**Positive**
- Existing debt in unchanged files no longer blocks PRs on unrelated features. The decidesk#44/#45 ping-pong is structurally impossible going forward.
- Builder, reviewer, and security all see the same scoped gate output — no more cycle-of-life where each position reads different baselines.
- Faster pipeline runs: scanning ~20 changed files instead of ~200+ repo files per gate.

**Negative**
- Inherited debt is genuinely invisible to the pipeline until it lands in a PR. Mitigation: a full-repo audit (scope-to-diff off) runs on the `ready-for-audit` label via `cron-audit.sh`, keeping the base-branch state observable.
- A PR that ONLY modifies a file lightly (e.g. renames it) may have gates pass on that file even if it has pre-existing debt. Acceptable — gates judge what the PR touched, not the file's full history.

**Deferred to Phase G.1**
- `composer check:strict` (phpcs, phpmd, psalm, phpstan) and `phpunit` / `npm run lint` are still full-repo. They run inside `composer`/`phpunit` which don't accept per-file scoping cleanly without per-tool argument passthrough. The same scoping story will land there next; for now, the reviewer's manual scope filter (`/tmp/pr-scope.txt`) remains the safety net.

## Verification

Smoke-test on decidesk PR #131 (feature/47/p2-motion-and-voting-core-t2) 2026-04-23:
- Full-repo scan: 2 FAIL (SettingsController in unchanged file)
- `--scope-to-diff --base origin/development`: ALL 8 GATES GREEN

The PR is now unblockable by unrelated debt without sacrificing gate coverage on the 19 files it actually changed.

### ADR-021-bounded-fix-scope-by-shape
# ADR-021: Reviewer bounded-fix scope is defined by change shape, not line count

**Status:** accepted
**Date:** 2026-04-23

## Context

The reviewer containers (Juan Claude van Damme for code, Clyde Barcode for security) run with bounded fix authority — they MAY apply small remediations in-container, commit, and push. The original rule in their CLAUDE.md:

> The fix is bounded to **1–3 lines in one file**.

This rule was an attempt to keep reviewers out of architectural territory. In practice it failed in two directions:

**1. Wrong-shaped for common security patterns.** A typical missing-authorization fix — add a `checkUserRole($uid, ['chair','secretary'])` block with try/catch — is 5–10 physical lines. Reviewers correctly declined to fix under the 3-line rule. On decidesk#45 (PR#129), Clyde flagged the same two auth stubs across **eight review cycles** from 2026-04-21 to 2026-04-23, each time declining as "exceeds 3-line bounded fix scope" or "architectural decision needed". The fix was literally mirroring a sibling method (`transitionLifecycle`) in the same class — zero new concepts, just apply the existing pattern. The 3-line limit turned a mechanical fix into architectural churn.

**2. Ambiguous under formatter changes.** Does "line" mean physical lines? Logical statements? With braces? A single prettier or phpcs run can convert a 3-line compact form into a 7-line expanded form and flip fix authority on or off. Reviewers should not be measuring code in a unit that formatters can redefine.

Meanwhile, genuine architectural work — new services, new schemas, new DI — IS well understood across the team. The category error was confusing "how much code changes" with "how much thinking changes".

A 10-line change that mirrors a sibling method is safer than a 2-line change that invents a new concept. We should scope by what the change touches, not by its size.

## Decision

Reviewer bounded-fix scope is defined by **change shape**, not line count. A fix is in-scope when ALL of these hold:

1. **The shape is one of:**
   - Modify an existing method body (guard clause, try/catch, validation, escape, swap unsafe call for safe one)
   - Add a new **private** helper method in the same class (no public API change)
   - Apply a pattern that **already exists in the same file or class, OR in a sibling controller/service of the same app** — mirror the precedent
   - Add a missing attribute / annotation / docblock tag
   - Swap an unsafe API for its safe counterpart (`md5` → `password_hash`, raw SQL → prepared statement, raw HTML → `htmlspecialchars`)
   - **Add a constructor parameter to inject a dependency that is already injected in a sibling controller/service of the same app** — strictly to enable a mechanical fix above (e.g. `IUserSession` → null-check → 401, `IGroupManager` → `isAdmin()` guard). The registration block in `Application.php` is updated at the same time.

2. **The change does NOT:**
   - Introduce a brand-new dependency that no sibling class in the same app already uses (first-use DI is an architectural choice — escalate)
   - Add a new service, class, interface, or route
   - Touch database schema or migrations
   - Change any public method signature visible to callers outside the class
   - Rewrite the file's top-level control flow

3. **Self-verify stays green.** Semgrep (security) or phpcs + covering phpunit (code) on the touched file produces 0 new findings.

The "sibling precedent" clause is explicit: **if a method in the same class OR in a sibling controller/service of the same app demonstrates the fix, the "architectural decision needed" escape hatch does NOT apply.** This is the clause that closes the #45 trap — the precedent in `transitionLifecycle` makes mirroring it mechanical, regardless of how many lines the mirror takes. The sibling-class extension closes the #73 trap — `MinutesVersionController`, `DecisionSearchController`, and `NotificationSubscriptionController` each lacked `IUserSession` and required a new constructor param to add auth guards, but `MinutesApprovalController` in the same app already injected it; mirroring that constructor shape is mechanical, not architectural. The bright line stays at **first-use DI** — a dependency no sibling class in the same app already uses is a genuine architectural choice and still escalates.

## Consequences

**Positive**
- Auth-guard mirroring is now in-scope for reviewers — the most common security-fix pattern stops escalating.
- Scope is robust under formatter changes: `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on one line or three lines is the same fix.
- The "architectural" label is reserved for genuine architectural work (new services, new roles, new DI) where a human really does need to decide something.
- Fewer `needs-input` escalations on recurring findings — fewer retry cycles — less pipeline capacity burned per PR.

**Negative**
- Reviewers have slightly more scope and therefore slightly more room to make wrong calls. Mitigations:
  - The self-verify gate (Semgrep / phpcs + phpunit green on the touched file) is unchanged — still a hard stop on regressions.
  - "No new DI / schema / public signature" is a bright line that protects the expensive classes of change.
  - "Pattern exists in same file/class" is conservative — it prevents invention, only permits mirroring.
- Reviewers now need to read adjacent methods in the same class to check for precedent. This is a small turn-count cost but produces strictly better fixes.

**Neutral**
- Line-count as a heuristic is abandoned. Reviewers still prefer small fixes over large ones — the shape rules make that natural without encoding a brittle number.

## Implementation

Applied to:
- `images/reviewer/CLAUDE.md` — the "Bound-fixable" row in the fix-category table + the "Warnings ARE in scope for fix" section
- `images/security/CLAUDE.md` — the "What you MAY fix in-container" and "What you MUST NOT fix" sections

Rolled out via PR [#136](https://github.com/ConductionNL/hydra/pull/136), 2026-04-23.

## References

- Observed failure: decidesk#45 security-review, 8 cycles documented in [docs/retrospectives/decidesk-44-45-phase-g.md](../../docs/retrospectives/decidesk-44-45-phase-g.md)
- Observed failure: decidesk#73 security-review, 5+ cycles 2026-04-23 — 7 WARNING gate-7 findings across `MinutesVersionController`, `DecisionSearchController`, `NotificationSubscriptionController`; each cycle declined under the "no new DI" rule even though `MinutesApprovalController` in the same app already injected the needed `IUserSession` / `IGroupManager`. Manually closed by the operator, driving the sibling-class relaxation above.
- ADR-013 (container pool) defines the reviewer personas; this ADR defines their authority surface.
- ADR-020 (gate scope-to-diff) is the adjacent Phase G work — together these two ADRs remove the two biggest classes of false-escalation observed on the pipeline.

### ADR-022-apps-consume-or-abstractions
# ADR-022: Apps Consume OpenRegister Abstractions

## Status
Proposed

## Date
2026-04-23

## Context

Conduction maintains ~13 Nextcloud apps (decidesk, docudesk, pipelinq, procest, opencatalogi, openconnector, mydash, larpingapp, shillinq/budgetq, zaakafhandelapp, nldesign, softwarecatalog, and the in-flight idea apps). Each app needs features that overlap heavily: objects with schemas, role-based access, audit trails, archival/retention policies, mapping/transformation, relation management, sidebar tabs with notes/tasks/files, dashboard widgets, integrations with NC-native and external services.

OpenRegister has grown into the **foundation** that provides these as shared abstractions: registers, schemas, objects, RBAC, audit-trail-immutable, archival-destruction-workflow, mappings, relations, object-interactions, and — with ADR-019 — a pluggable integration registry.

When a new app is built (or an existing app evolves), its authors face a choice: consume OR's abstraction, or build a parallel mechanism in-app. The "parallel mechanism" path is attractive at first — it's self-contained, it can be tweaked without coordinating with OR, and it avoids adding a dependency. But every instance observed so far has produced the same end state over time:

- **Duplicate data models** (an app-local Person vs OR contacts; an app-local AccessRule vs OR RBAC).
- **Drift** — app-local audit trails stop tracking things OR's audit does (replayable ordering, hash chains, retention-aware purge).
- **Missed features** — an app that rolled its own "linked files" sidebar never gets calendar/deck/polls/maps/collectives when OR adds them to the integration registry.
- **Impossible cross-app queries** — "show me all cases assigned to Jan across all Conduction apps" requires the contact linkage to be uniform.
- **Duplicate ADRs** — app-local ADRs restating what OR's already decided, then drifting.

ADR-019 codified the **mechanism** for one specific class of abstraction (integrations). This ADR codifies the **principle** that generalises: when OR has an abstraction that fits, apps consume it rather than reinvent.

## Decision

### Apps consume OpenRegister abstractions over local duplication

When an app needs functionality that OR already provides as an abstraction, the app MUST consume the OR abstraction. Rolling a parallel implementation in-app is not permitted unless explicitly justified (see "exceptions" below).

### What counts as an "OR abstraction"

Any capability exposed by OpenRegister that has a contract, a public API, and is documented as reusable. The current list (non-exhaustive):

| Abstraction | What it provides |
|---|---|
| **Registers + schemas + objects** | Versioned typed entities with validation, queries, events |
| **Authorization RBAC** | Role + scope + object-level permissions, per-schema and per-property |
| **Audit trail (immutable)** | Append-only hash-chained event log per object |
| **Archival + destruction workflow** | Retention classification, archival, purge — aligned with Archiefwet |
| **Mappings** | Cross-system transformation between source + target schemas |
| **Relations** | Typed links between OR objects |
| **Object interactions** (`object-interactions` spec) | Files, notes, tasks, tags, audit per object — the built-in part of the integration registry |
| **Integration registry (ADR-019)** | Pluggable NC-native + external integrations with tab+widget parity |
| **Audit hash chain** | Cryptographic verification of audit event order |
| **Content versioning** | Snapshot/restore of object states |
| **Deep link registry** | Cross-app navigation with stable object references |
| **TMLO metadata** | Dutch-gov metadata vocabulary compliance |
| **MCP discovery** | AI-agent discovery endpoint for all OR-backed capabilities |
| **Events + webhooks** | CloudEvents over NC's event dispatcher |

New abstractions land in OR via its own openspec process. When they're merged, this ADR's list updates.

### The positive case — how to consume

1. **Use OR's PHP service via DI injection.** Don't wrap it in an app-local service that adds nothing. Thin adapters are fine; duplication isn't.
2. **Register for OR's extensibility points.** The integration registry takes DI-tagged providers (ADR-019). RBAC takes scoped role definitions. Audit takes event listeners. Apps extend through these points, not by building parallel machinery.
3. **Follow OR's schemas when OR has a schema.** If OR already defines a `contact` or `case` or `organisation` model, an app using those concepts MUST reuse the OR schema and its register — not a local copy with the same-ish fields.
4. **Call OR's REST API from the frontend via `@conduction/nextcloud-vue`.** The shared library wraps OR's API; apps that bypass it and call OR's raw endpoints re-solve problems the shared lib already solved.

### Anti-patterns

These have all been observed and should be treated as review-blocking:

- **Parallel link tables.** An app creating its own `{app}_email_links` / `{app}_contact_links` table when OR's integration registry already provides the equivalent via `openregister_*_links`. (Observed via decidesk's initial CalDAV plan using `X-DECIDESK-*` properties duplicating OR's `X-OPENREGISTER-*` mechanism.)
- **App-local schema validators.** An app writing its own JSON schema validation when OR already validates against the schema it owns.
- **Home-grown audit trails.** An app writing to a private events table instead of OR's audit trail for actions on OR-owned objects.
- **App-local RBAC on OR objects.** An app defining its own role/permission scheme for objects that live in OR's register.
- **Duplicate sidebar tab systems.** An app registering its own object-sidebar tabs outside the integration registry (ADR-019).
- **App-local "linked bookmarks/files/notes/..." that mirror an OR integration.** If OR has an integration for it, the app consumes it.
- **Duplicate ADRs.** An app-local ADR restating an org-wide ADR. The stale copies of `adr-004-frontend.md` in app repos (removed 2026-04-19) are the canonical example.

### Exceptions (when an app may build a parallel mechanism)

A parallel mechanism is acceptable only when one of the following is true, **and documented in an app-local ADR that references this ADR and justifies the divergence**:

1. **Fundamentally different domain requirements.** The app's use-case has constraints OR can't satisfy (e.g., sub-millisecond latency, append-only write with no read, special encryption-at-rest keys per tenant).
2. **OR is blocked on a dependency the app can't wait for.** Time-sensitive delivery where adding the feature to OR would push out 3+ months, and the app ships its own interim solution with an explicit migration plan.
3. **Prototype / spike.** Temporary local code with a written sunset date (max 90 days) and an owner.

Every exception requires an app-local ADR. "We didn't know OR had this" is not an exception.

### Enforcement

- **Code review gate.** Reviewers reject PRs that duplicate an OR abstraction without an explicit ADR-backed justification.
- **Specter's spec generation** surfaces applicable OR abstractions in each app's context brief (ADR-019 already flows in via `generate_spec_content.py`). The expectation is that feature specs reference the OR abstraction they consume.
- **Hydra quality gate (future).** A mechanical gate that flags common anti-patterns — parallel link tables, duplicate ADR files, schema-validator reinvention, local RBAC code acting on OR objects. Tracked as a follow-up to this ADR; implementation issue to be opened separately.
- **This ADR list updates when OR adds an abstraction.** Keeping the list current is the OR team's responsibility; when a new abstraction becomes stable, it goes in this table via a small PR against this file.

## Consequences

### Positive

- **One source of truth per capability.** Features of files/notes/tasks/calendar/mail/contacts/etc. evolve in OR; every app benefits.
- **Cross-app consistency.** "Jan is the applicant on this case" means the same thing in procest, pipelinq, and zaakafhandelapp.
- **Smaller apps.** Each app ships less code because it consumes more. A new app in 2026 should be mostly schemas + app-specific business logic; the plumbing is OR.
- **Uniform audit/RBAC/retention.** Government compliance (Archiefwet, AVG, Woo, BIO) has one implementation to verify, not 13.
- **The integration registry compounds.** When OR adds the `integration-calendar` leaf, every app using OR objects gets meeting linkage without any per-app work.

### Negative

- **App authors need to learn OR's contracts.** The onboarding curve for a new Conduction developer includes understanding OR's schemas, RBAC model, audit trail, and integration registry. Mitigated by OR's docs + this ADR list.
- **OR becomes a bottleneck for shared changes.** If a capability needs a fix, OR has to ship it. Mitigated by keeping OR fast-moving + prioritising the long-tail abstractions that unblock multiple apps.
- **Exception discipline matters.** Without rigorous review of the app-local ADR justifications, exceptions become the norm. Mitigated by the code-review gate and the explicit sunset date on prototype exceptions.

### Migration

Apps currently in violation (openconnector's bespoke linked-entity handling, decidesk's X-DECIDESK-* CalDAV properties, app-local audit copies) are not required to migrate immediately. Each gets a tracked "consume-OR-abstraction" issue with a target date. See the openregister integration registry umbrella ([openregister#1307](https://github.com/ConductionNL/openregister/issues/1307)) for the calendar/email/deck/contacts/talk migration pattern.

## Related

- **ADR-019** — Integration Registry Pattern (the first concrete instance of this principle).
- **Openregister spec** — `openregister/openspec/changes/pluggable-integration-registry/` (the implementation that made the integration class of abstractions consumable).
- **Stale-duplicate incident 2026-04-19** — app repos carried stale copies of `adr-004-frontend.md` that drifted from the hydra master; removed across all app repos. The lesson that seeded this ADR.

## Ownership

- The OR team owns the list of abstractions in this ADR.
- Each app's maintainers own applying it inside their repo.
- Hydra reviewers enforce it at code-review time.

### ADR-023-action-authorization
# ADR-023: Action-level authorization via admin-configured action/group mappings

**Status:** accepted
**Date:** 2026-04-23

## Context

Conduction apps mix **data authorization** (who can read/write which OpenRegister objects) and **action authorization** (who can invoke which controller methods / workflow steps). The two are related but not the same:

- A chair of "Board A" can read all Board A minutes (data RBAC → OpenRegister) AND can invoke `generateMinutesDraft()` on them (action RBAC → app).
- A regular member of Board A can read the same minutes (data RBAC → OpenRegister) but CANNOT invoke `generateMinutesDraft()` (action RBAC denies).
- A Nextcloud admin can invoke `create()` on `SettingsController` (action RBAC → admin-only) regardless of any board membership.

OpenRegister already owns the **data** layer: object-level ownership, schema/register permissions, per-relation filtering (ADR-022 lists RBAC as one of the shared abstractions it provides). Apps consume this cleanly.

Apps DO NOT have a shared pattern for the **action** layer. Observed across decidesk / docudesk / pipelinq, the action-auth implementations range from:

- `IGroupManager::isAdmin()` hardcoded checks in controller bodies (wrong — locks governance actions to Nextcloud sysadmins, not to chairs/secretaries — see #44 / #45 on 2026-04-23)
- Missing entirely (the endpoint gates on data RBAC alone — wrong for actions that cross objects, like "generate report across all boards I chair")
- Inline `!in_array('chair', $roles)` checks that are (a) not discoverable by admins, (b) require a code change to adjust, (c) duplicated across controllers

The consistent answer needs to: live in app code (each app has its own actions), be **declarative** (admin can see and change the matrix without touching code), and be **testable** (gate-7 / gate-9 can mechanically verify each routed action either delegates to this service or is explicitly marked admin-only).

## Decision

### Rule 1 — Data RBAC is OpenRegister's job; apps never roll their own

OpenRegister decides for itself who may read / write / list which objects. App code that fetches, lists, or mutates domain objects MUST go through OpenRegister's `ObjectService` and trust the service's filtering + per-object permissions. Apps do not implement:

- Object-ownership checks (OpenRegister does it via `createdBy` / `owner` / schema settings)
- Register/schema-level access gates (OpenRegister does it via register permissions)
- Group-based read/write filtering on data (OpenRegister does it via `relations.group` / schema RBAC)
- Schema / register configuration (that's OpenRegister's own admin UI, not the consuming app's)

If the data-layer RBAC has a gap, **fix it in OpenRegister** (ADR-012 — push logic up to the shared foundation, don't re-implement per app).

### Rule 2 — Action RBAC is the app's job, declared in admin settings

Every app defines a registry of **actions** — named operations that a controller method executes. Examples (decidesk):

- `minutes.generate-draft` — produces a draft from a meeting transcript
- `minutes.distribute` — sends final minutes to the governance body
- `decision.publish` — marks a decision as published, triggers notifications
- `analytics.view-summary` — reads aggregate metrics across bodies
- `settings.write` — admin-only settings writes

Each action is mapped to a set of **user groups** via an admin-configured matrix, stored in `IAppConfig` under a well-known key. Every app maintains its own seed data for the initial mapping; the template ships a skeleton file per app that declares the action list with `["admin"]` as the default for every action. This default is **the safest first-install posture** — nothing is accidentally opened to non-admins until an admin explicitly broadens it. The admin settings panel is the only place to edit the matrix.

```json
// stored as IAppConfig["decidesk"]["actions"]
//
// First-install values (seed from the app, admin-only everywhere).
// The admin editing the matrix is the only path to broaden — code
// changes must not relax the default.
{
  "minutes.generate-draft":   ["admin"],
  "minutes.distribute":       ["admin"],
  "decision.publish":         ["admin"],
  "analytics.view-summary":   ["admin"],
  "settings.write":           ["admin"]
}
```

After admin customization (example — illustrative, not default):

```json
{
  "minutes.generate-draft":   ["chairs", "secretaries"],
  "minutes.distribute":       ["chairs", "secretaries"],
  "decision.publish":         ["chairs"],
  "analytics.view-summary":   ["chairs", "secretaries", "board-members"],
  "settings.write":           ["admin"]
}
```

**Naming convention**: `<domain>.<verb-phrase>` with dot as separator, lowercase, hyphens-in-phrases. `minutes.generate-draft`, `decision.publish`, `analytics.view-summary`. NOT `decidesk:minutes:generateDraft`. This keeps the keys grep-friendly, stable across refactors, and matches how schema keys look in OpenRegister.

The **admin settings panel** (registered via `\OCP\Settings\ISection`, route carries `#[AuthorizedAdminSetting(Application::APP_ID)]`) renders this matrix: rows = actions, columns = user groups, checkboxes = allowed. Admin edits + saves → `IAppConfig` updated. NO code change required to adjust who can do what.

Controllers enforce the mapping with a single helper call:

```php
#[NoAdminRequired]
public function generateDraft(string $minutesId): JSONResponse {
    $user = $this->userSession->getUser();
    if ($user === null) {
        return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }

    $this->actionAuth->requireAction($user, 'minutes.generate-draft');
    // Throws OCSForbiddenException if none of $user's groups are mapped
    // to 'minutes.generate-draft' in the admin matrix.

    // ... data-layer work via ObjectService (OpenRegister enforces its own
    //     per-object permissions on top of this action check).
}
```

### Rule 3 — When admin IS required (not delegated to action RBAC)

The following stay `#[AuthorizedAdminSetting(Application::APP_ID)]` and live **only on the admin settings page** — they are NOT expressible as action mappings because they are the plumbing the action matrix itself depends on:

- **Configuring the action ↔ group matrix** (the admin settings panel itself)
- **App configuration** — any `IAppConfig` writes (feature flags, feature toggles, workflow parameters, anything that affects app-wide behavior)
- **Backup / restore operations** — data export, re-import, cross-environment migration
- **App integration configuration** — connections to external systems (n8n, SOLR, external APIs), webhook URLs, integration feature flags
- **Credential management** — API keys, OAuth tokens, basic-auth credentials for any third-party service
- **One-off admin operations** — re-import seed data, purge caches, run migrations, trigger re-indexing

Everything a non-admin (chair / secretary / board-member / agent / regular user) might legitimately invoke during normal operation = an **action**, gated via `requireAction()`. Admin settings page handles the plumbing; user settings page / per-user UI never touches the plumbing. The user settings page is for user-personal preferences only (UI theme, notification opt-ins) — not for anything the action matrix references.

Rule of thumb: if the operation mutates something the action matrix references (keys the matrix looks up, values the matrix resolves to, integrations the actions depend on) → admin. Everything else → action.

### Rule 4 — Middleware attribute + body check layered

Per ADR-005 and ADR-016:

- `#[PublicPage]` — genuinely public (login pages, OAuth callbacks). Body does NO auth check.
- `#[NoAdminRequired]` — any authenticated user may reach the endpoint. Body **MUST** call `$this->actionAuth->requireAction($user, 'action.name')` for action-level gating. Absence of this call is a gate-9 failure — see enforcement below.
- `#[AuthorizedAdminSetting(Application::APP_ID)]` — framework-level admin gate for the exceptions in Rule 3. Body does no further admin check (the middleware already enforced it).

### Rule 5 — Gate-9 enforces the action-auth pattern mechanically

`hydra-gate-9` (semantic-auth) is extended to check:

| Pattern | Verdict |
|---|---|
| `#[NoAdminRequired]` + body calls `$this->actionAuth->requireAction(...)` | PASS |
| `#[NoAdminRequired]` + body calls `$this->authorize*(...)` (per-object auth helper per ADR-005 Rule 3) | PASS |
| `#[NoAdminRequired]` + body calls `$this->requireAdmin()` / `isAdmin()===false`→403 | FAIL — the wrong layer; use `#[AuthorizedAdminSetting]` for admin-only or `requireAction()` for role-based |
| `#[NoAdminRequired]` + no recognized auth gate in body | FAIL — inadequately gated, open endpoint |
| `#[PublicPage]` + any body auth check | FAIL — public is public, no body checks |
| `#[AuthorizedAdminSetting]` + `requireAction()` in body | PASS but redundant (middleware already gated to admin) — not a fail, but the lint could suggest removal |

Enforcement rolls out in two phases to give apps time to migrate without breaking their pipelines:

1. **Soft-fail phase** (announce in ADR): gate emits warnings, doesn't fail the gate. Apps that haven't migrated yet stay green.
2. **Hard-fail phase** (date-stamped): gate treats missing `requireAction()` as FAIL. Decided when majority of apps have adopted the pattern.

## Consequences

### Positive
- Governance actions (minutes drafting, decision publishing, quorum checks) can be delegated to chairs / secretaries / board members — NOT Nextcloud sysadmins. Current decidesk bug class (#44 + #45) goes away structurally.
- Admins can re-map actions to groups without a code change — useful when an org shifts responsibilities mid-deployment.
- One helper (`$this->actionAuth->requireAction()`) per gated method — consistent, grep-able, testable.
- Gate-7 / gate-9 enforcement has a clear target to check for (`requireAction()` call in body).
- Template repo ships this out of the box — new apps inherit the pattern instead of each rolling their own.

### Negative
- Initial setup burden: admin must populate the action matrix on first install. Mitigated with sensible defaults in `create-labels`-style seed data per app.
- Two layers of auth per request (action matrix check + OpenRegister per-object check) = two service calls per gated endpoint. Negligible cost (both are app-local memory or indexed DB).
- Admin who mis-configures the matrix can lock chairs out of essential actions. Mitigated with a "reset to defaults" button + `occ decidesk:actions:reset`.

### Neutral
- Replaces "lock everything to admin" over-restriction with "configurable by admin" flexibility. For ops that currently have only Nextcloud admins, the first-install default can be "admin-only" per action — the matrix is editable but the safe default survives if nobody touches it.

## Implementation plan

1. **This ADR** — accepted.
2. **Reference implementation in decidesk**:
   - New `OCA\Decidesk\Service\ActionAuthService` with `requireAction(IUser $user, string $action): void` — throws `OCSForbiddenException` when $user's groups don't intersect the matrix entry for $action
   - New `OCA\Decidesk\Settings\ActionMatrixAdmin` settings section (`\OCP\Settings\ISettings` + template) showing the action×group matrix, admin-only
   - `IAppConfig` key `decidesk.actions` storing the JSON mapping
   - Refactor the 13 + 2 controller methods caught by gate-9 on #44 / #45 to use `requireAction()`
   - **Seed data per app** — each app ships its own `actions.seed.json` (or equivalent) declaring the action list with `["admin"]` as default. App migration runs it on first install.
3. **Port to `nextcloud-app-template`**: copy `ActionAuthService` + skeleton settings panel + seed-data pattern. Parametrized so new apps just declare their action names. Default values all `["admin"]`.
4. **Gate-9 extension (soft-fail phase first)**:
   - Detect `#[NoAdminRequired]` + body-has-`requireAction()`-call → PASS
   - Detect `#[NoAdminRequired]` + body-has-`authorize*()`-call (per-object auth per ADR-005) → PASS
   - Detect `#[NoAdminRequired]` + no recognized gate → emit warning (soft-fail)
   - Detect `#[NoAdminRequired]` + `requireAdmin()` / `isAdmin()===false` → FAIL (hard — the wrong layer)
   - Warnings hit the verdict JSON but do not set the gate to FAIL during migration.
5. **Migrate existing apps** (hydra, decidesk first, then docudesk / pipelinq / procest / …) to the new pattern.
6. **Gate-9 hard-fail phase**: after apps are migrated, flip warnings → fails. Date-stamp to set on the PR that ships the hard-fail variant.
7. **Unblock #44 + #45**: once decidesk has `ActionAuthService`, their 13+2 methods plug into `requireAction('minutes.generate-draft')` etc. The current parked state resolves as a retry cycle.

## References

- ADR-005 (security) — per-object authorization rule + admin checks
- ADR-016 (routes) — auth attribute rules + gate layering
- ADR-021 (bounded-fix scope) — mentions `checkUserRole($uid, ['chair','secretary'])` as the correct shape (now formalized via `requireAction`)
- ADR-022 (apps consume OR abstractions) — lists RBAC as one of OpenRegister's shared abstractions; this ADR clarifies that the scope is **data** RBAC, not **action** RBAC
- decidesk#44 / #45 — both pending role-based fix that this ADR unblocks

## App-Specific ADRs (4)

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


## App Architecture ADRs from Repo (7 files)

These ADR files live in decidesk/openspec/architecture/.

### ADR-000-data-model
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

### ADR-ADR-001-popolo-data-standard
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

### ADR-ADR-002-caldav-first-storage
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

### ADR-ADR-003-ori-compatibility
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
