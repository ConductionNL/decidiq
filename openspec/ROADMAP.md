# Decidesk Roadmap

This document tracks the planned development of Decidesk, a universal decision-making platform for governance bodies.

Features are organized into 4 phases. Demand scores are derived from intelligence research across 630 tenders, 20 competitors, and 1,877 user stories. All features are currently `planned`.

## Status Overview

| Phase | Domain | Features | Avg Demand | Status |
|-------|--------|----------|------------|--------|
| Phase 1 | Foundation/Core | 45 | — | planned |
| Phase 2 | Governance Core | 462 | 136 | planned |
| Phase 3 | Domains | 343 | — | planned |
| Phase 4 | Polish | 241 | — | planned |

---

## Phase 1 — Foundation

Core infrastructure: schemas, dashboard, CRUD, navigation, search, and seed data. This phase produces a working Nextcloud app with the data model in place but no governance-specific logic.

### Schemas and Data Model

| Feature | Demand | Description |
|---------|--------|-------------|
| OpenRegister schema definitions | — | Define all 11 entity schemas (Meeting, AgendaItem, Motion, Amendment, VotingRound, Vote, Minutes, Decision, GovernanceBody, Participant, ActionItem) |
| Schema.org type annotations | — | Map all entities to Schema.org types with Akoma Ntoso extensions |
| Register configuration | — | decidesk_register.json with OpenAPI 3.0.0 definitions |
| Seed data | — | Example governance bodies, meetings, and decisions for each of the 5 domains |

### Dashboard and Navigation

| Feature | Demand | Description |
|---------|--------|-------------|
| App dashboard | — | Overview of upcoming meetings, pending motions, recent decisions |
| Navigation structure | — | Left sidebar with sections: Meetings, Governance Bodies, Decisions, Settings |
| Search integration | — | Full-text search across meetings, motions, and decisions via OpenRegister |
| NL Design System theming | — | CSS custom property support for government theming |

### CRUD Operations

| Feature | Demand | Description |
|---------|--------|-------------|
| Meeting CRUD | — | Create, read, update, delete meetings via OpenRegister API |
| GovernanceBody CRUD | — | Manage governance bodies and their configuration |
| Participant management | — | Add/remove participants, assign roles |
| Basic list/detail views | — | List views with sorting/filtering, detail views for all entities |

---

## Phase 2 — Governance Core

The heart of Decidesk: meeting management, agenda building, motion/voting workflows, and minutes/decision recording. Prioritized by demand score.

### Minutes and Decisions (103 features, avg demand 186 — highest)

| Feature | Demand | Description |
|---------|--------|-------------|
| Automated minutes generation | 186 | Generate minutes from meeting proceedings, motions, and votes |
| Decision recording | 186 | Record formal decisions with references to motions and votes |
| Minutes approval workflow | 186 | Chair/secretary review and approval of draft minutes |
| Decision publication | 186 | Publish decisions via ORI API and Woo-compliant channels |
| Action item tracking | 186 | Create and track follow-up tasks from decisions |
| Minutes versioning | 186 | Track revisions with full audit trail |
| Digital signing of minutes | 186 | Chair and secretary sign approved minutes |
| Decision search and archive | 186 | Search historical decisions by topic, date, body, outcome |

### Motion and Voting (128 features, avg demand 140)

| Feature | Demand | Description |
|---------|--------|-------------|
| Budget amendment motions | 2025 | Motions to amend budget proposals with financial impact tracking |
| Proxy voting | 1242 | Delegate voting rights to another participant |
| Motion submission | 140 | Submit motions with title, description, proposer, and co-signers |
| Amendment workflow | 140 | Submit, debate, and vote on amendments to motions |
| Voting round management | 140 | Open/close voting rounds, set voting method (for/against/abstain, ranked choice, weighted) |
| Secret ballot | 140 | Anonymous voting with participation verification |
| Vote casting and tallying | 140 | Real-time vote collection and automatic result calculation |
| Quorum checking | 140 | Verify sufficient participants before opening a vote |
| Voting result publication | 140 | Display results and publish to ORI API |
| Motion status tracking | 140 | Track motion lifecycle through workflow states |

### Agenda Management (38 features, avg demand 137)

| Feature | Demand | Description |
|---------|--------|-------------|
| Agenda builder | 137 | Create and organize agenda items with drag-and-drop ordering |
| Agenda item types | 137 | Support for informational, discussion, and decision items |
| Time allocation | 137 | Set estimated duration per agenda item |
| Agenda publication | 137 | Publish agenda before meeting with attachments |
| Agenda amendments | 137 | Add/remove/reorder items during a meeting (with chair approval) |
| Recurring agenda items | 137 | Template items that appear on every meeting agenda |

### Meeting Management (193 features, avg demand 82)

| Feature | Demand | Description |
|---------|--------|-------------|
| Digital council meetings | 803 | Full support for legally valid digital/hybrid meetings per Wet digitaal vergaderen |
| Meeting scheduling | 82 | Create meetings with date, time, location, and iCalendar export |
| Meeting lifecycle | 82 | Open, pause, resume, adjourn, close meeting with state tracking |
| Attendance tracking | 82 | Record present, absent, and late-arriving participants |
| Speaking time management | 82 | Track and limit speaking time per participant per item |
| Meeting templates | 82 | Reusable templates for recurring meeting types |
| Hybrid meeting support | 82 | Track in-person and remote participants separately |
| Meeting document attachments | 82 | Attach supporting documents to meetings and agenda items |
| Meeting series | 82 | Link related meetings (e.g., quarterly board meetings) |

---

## Phase 3 — Domains

Domain-specific features: governance body configuration for all 5 domains, citizen participation tools, and document management.

### Governance Bodies (123 features, avg demand 148)

| Feature | Demand | Description |
|---------|--------|-------------|
| BOB model tracking | 1049 | Full BOB workflow (Beeldvorming-Oordeelsvorming-Besluitvorming) for Dutch municipalities |
| ORI API publishing | 720 | Publish meetings, agendas, motions, votes, and decisions via ORI API |
| Workflow template configuration | 148 | Configure state machine workflows per governance body |
| Domain presets | 148 | One-click setup for each of the 5 governance domains |
| Role and permission management | 148 | Define custom roles and permissions per governance body |
| Committee management | 148 | Manage sub-committees with their own meeting cycles |
| Term/period management | 148 | Track governance periods (council terms, board terms) |
| Membership history | 148 | Record when participants join/leave with role changes |

### Citizen Participation (125 features)

| Feature | Demand | Description |
|---------|--------|-------------|
| Public initiative submission | — | Citizens submit proposals for governance body consideration |
| Participatory budgeting | — | Budget allocation through citizen voting |
| Public consultation | — | Publish items for public input before decision |
| Referendum support | — | Organize binding or advisory referenda |
| Citizen dashboard | — | Public-facing view of upcoming decisions and participation opportunities |
| Feedback collection | — | Structured input gathering on proposals |
| Transparency portal | — | Public access to meeting calendars, agendas, decisions (Woo compliance) |

### Document Management (95 features)

| Feature | Demand | Description |
|---------|--------|-------------|
| Akoma Ntoso export | — | Export motions, amendments, and minutes as Akoma Ntoso XML |
| MDTO metadata | — | Attach MDTO-compliant metadata for archiving |
| TOOI classification | — | Classify documents using TOOI thesaurus terms |
| Document versioning | — | Track document revisions with diff view |
| Template management | — | Document templates for motions, minutes, decisions |
| Attachment management | — | Upload, organize, and link supporting documents |
| Bulk export | — | Export meeting packages (agenda + documents) as ZIP |

---

## Phase 4 — Polish

Reporting, collaboration tools, external integrations, and comprehensive standards compliance.

### Reporting and Analytics (78 features)

| Feature | Demand | Description |
|---------|--------|-------------|
| Decision statistics | — | Dashboard showing decision outcomes, voting patterns, attendance rates |
| Meeting efficiency metrics | — | Track actual vs. planned duration, items completed |
| Participation reports | — | Attendance and voting participation per member |
| Historical trend analysis | — | Compare metrics across governance periods |
| Custom report builder | — | Generate reports filtered by date range, body, topic |
| Export to PDF/CSV | — | Export reports and decision lists |

### Collaboration (86 features)

| Feature | Demand | Description |
|---------|--------|-------------|
| Motion co-authoring | — | Multiple participants collaborate on motion text |
| Discussion threads | — | Threaded discussions on agenda items and motions |
| Annotations | — | Comment on specific sections of documents |
| Notification preferences | — | Configurable alerts for meetings, votes, and decisions |
| Email integration | — | Send meeting invitations and decision notifications |
| Task assignment | — | Assign action items to participants with due dates |

### Integration (77 features)

| Feature | Demand | Description |
|---------|--------|-------------|
| iCalendar sync | — | Bi-directional calendar synchronization |
| OpenConnector webhooks | — | Event notifications for external systems |
| n8n workflow triggers | — | Trigger n8n workflows on meeting/decision events |
| OpenCatalogi publication | — | Publish public decisions to OpenCatalogi |
| Nextcloud Files integration | — | Link meeting documents to Nextcloud Files |
| Nextcloud Talk integration | — | Launch video calls for digital meetings |
| REST API (public) | — | Full public API following REST-API Design Rules |

### Standards Hardening

| Feature | Demand | Description |
|---------|--------|-------------|
| WCAG 2.1 AA audit | — | Full accessibility audit and remediation |
| EN 301 549 compliance | — | European ICT accessibility standard validation |
| JSON-LD responses | — | Linked data format for all API responses |
| NEN-ISO 27001 documentation | — | Security documentation for procurement |
| Gemeentewet compliance verification | — | Automated checks for legal procedure requirements |

---

## How This Works

1. Run `/app-explore` to refine features in `openspec/`
2. When a feature is `planned`, it appears in this roadmap
3. Run `/opsx-ff {feature-name}` to create the implementation spec
4. Update feature status as implementation progresses
5. When all changes for a feature are done, mark the feature `done`
