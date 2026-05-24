# Decidesk — Feature Analysis & Product Strategy

## Executive Summary

There is **no universal decision-making platform** that covers the full governance spectrum. The market is completely siloed: council information systems (GO Raadsinformatie, Notubiz) serve only legislative bodies; board portals (Diligent, OnBoard) target only corporate boards; meeting productivity tools (Fellow.app, Otter.ai) handle only operational meetings; and participatory platforms (Decidim, Loomio) focus exclusively on citizen engagement. No single product spans all five decision-making domains: legislative, corporate governance, corporate operations, associations, and citizen participation.

**Key insight**: Decision-making is fundamentally about agendas, motions, voting, minutes, and action tracking — processes that are structurally identical across domains but served by incompatible tools. A Nextcloud-native platform can unify these workflows while leveraging built-in collaboration (Talk, Calendar, Files, Mail) that competitors must build or integrate separately.

**Market opportunity**: The board portal market alone is projected at $5-7B by 2030 (11-20% CAGR). $541B is wasted on meetings globally. AI meeting tool adoption grew 17x in 2024. Half of Dutch municipal RIS systems have broken search. Diligent costs $48-155K/year, creating a massive price gap. Otter.ai and Fireflies.ai face lawsuits over consent and biometric data — a self-hosted, privacy-first alternative does not exist.

## 1. Competitive Landscape

### 1.1 Nextcloud App Store

| Name | Status | Approach |
|------|--------|----------|
| **Nextcloud Polls** | Active, maintained | Simple poll/survey creation — no meeting management, no formal governance |
| **Nextcloud Deck** | Bundled, active | Kanban board for tasks — sometimes repurposed for action items but not a decision tool |
| **Nextcloud Forms** | Bundled, active | Form builder — used for surveys but no voting or governance features |
| **Nextcloud Talk** | Bundled, active | Video/chat — useful for meetings but no agenda, voting, or minutes |

**Finding**: No Nextcloud app addresses structured decision-making. Polls is the closest but lacks meeting context, formal voting procedures, motion management, and minutes generation. The entire governance domain is unserved.

### 1.2 Self-Hosted Open Source

| Name | License | Stars | Positioning | Strengths | Weaknesses |
|------|---------|-------|-------------|-----------|------------|
| **Loomio** | AGPL-3.0 | 2,531 | Async group decisions | Multiple voting types, visual opinion summaries, threaded discussions, 30+ languages | No meeting management, no agenda builder, no minutes, Ruby/Vue stack, small community |
| **Decidim** | AGPL-3.0 | 1,726 | Citizen participation | Proposals & amendments, participatory budgets, assemblies, 50+ languages, used by 80+ governments | No board governance, no corporate meetings, no minutes generation, Ruby on Rails monolith |
| **OpenSlides** | MIT | 596 | Parliamentary assemblies | Motion management, speaker lists, electronic voting, projector management, committee structure | German-centric, no corporate governance, no citizen participation, Angular/Python stack |
| **OpenRaadsinformatie** | LGPL-3.0 | 35 | Open data aggregation | Multi-municipality data, full-text search, Popolo standard, no API key required | Not a product but a data standard; read-only — no meeting management or decision support |

### 1.3 Enterprise SaaS

| Name | Price | Positioning | Strengths | Weaknesses |
|------|-------|-------------|-----------|------------|
| **Diligent Boards** | $48-155K/yr | Board portal market leader | AI Smart Book Builder/Minutes/Risk Scanner, real-time collaboration, 50% Fortune 1000, SOC 1/2/3/ISO | Extreme cost, proprietary lock-in, boards-only — no legislative or operational meetings |
| **BoardEffect** | $5-15K/yr | Mid-market board portal | AI board book summarization, e-voting, surveys, secure messaging (Diligent subsidiary) | Boards-only, no legislative features, no citizen participation, US-hosted |
| **OnBoard** | Quote-based | Fast-growing challenger | AI meeting minutes, digital voting, skills tracking, D&O questionnaires, SOC 2/ISO 27001 | Quote-based pricing, boards-only, no Dutch market presence |
| **Admincontrol** | EUR 500+/mo | Nordic board portal | Board portal + data room combo, Nordic BankID/MitID, 97% renewal rate (Visma group) | Nordic-focused, no legislative features, no participation tools |
| **iBabs** | Per-user SaaS | Dutch board/council portal | AI transcription & minutes, digital voting & signatures, offline access, ISO 27001, Microsoft 365 | Proprietary, siloed to board meetings, no citizen participation, pricing opaque |
| **Fellow.app** | $7-25/user/mo | Meeting productivity | AI transcription & summaries, collaborative agendas, action items, 1:1 tools, Slack/Teams/Zoom integration | No formal voting, no governance features, no minutes as legal documents, SaaS-only |
| **Meeting Decisions** | $9.90/user/mo | Teams-embedded meetings | Secure voting, eSignature, AI notetaker, 100 AI credits/license, native Microsoft Teams | Teams-only, no standalone option, no legislative features, limited to Microsoft ecosystem |
| **Sherpany** | Quote-based | European board management | Swiss-hosted, GDPR-focused, meeting lifecycle management | Enterprise pricing, boards-only, limited public information |

### 1.4 Dutch Government & Legislative

| Name | Type | Customers | Strengths | Weaknesses |
|------|------|-----------|-----------|------------|
| **GO Raadsinformatie** | Proprietary SaaS | Dutch municipalities | Agenda/document management, webcasting (GO Raad Direct), paperless meetings, zaaksysteem integration, ORI API | Proprietary, estimated EUR 10-50K/yr, no citizen participation, WCAG compliance claimed but search inadequate |
| **Notubiz** | Proprietary SaaS | ~250 local authorities (50%+ NL market) | Full-service model, meeting minutes, live broadcasting (NotuCast), recording (NotuRecord), speech-to-text, council instruments | Called a "doolhof" (maze) by NOS Nieuwsuur, broken search, high staff turnover, estimated EUR 15-60K/yr |
| **Parlaeus (Qualigraf)** | Proprietary SaaS | Dutch/French municipalities | Meeting management, live streaming, speech-to-text, AI notes, member profiles, document annotations, mobile app | Proprietary, DocWolves/Qualigraf merger created confusion, pricing opaque |
| **OpenRaadsinformatie** | LGPL-3.0 standard | 265/345 NL municipalities (data) | Open data API, Popolo standard, multi-municipality aggregation, no API key | Read-only aggregation layer — not a meeting management tool, maintained by Open State Foundation |

## 2. Feature Matrix

### 2.1 Decision Management

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 1 | Decision CRUD with status lifecycle (proposed/active/decided/archived) | **MVP** | Core entity — every domain needs to track decisions |
| 2 | Decision list with search, sort, filters | **MVP** | Navigation and retrieval across growing decision corpus |
| 3 | Decision detail view with full context (motion, vote result, minutes reference) | **MVP** | Critical UX pattern — decisions must be traceable to their origin |
| 4 | Decision categorization (tags, topics, policy areas) | **MVP** | Organization and retrieval — municipal councils tag by portfolio |
| 5 | Decision assignment to responsible parties | **MVP** | Accountability — who must execute the decision |
| 6 | Decision timeline/audit trail (who proposed, who voted, when decided) | **MVP** | Legal requirement for formal governance bodies |
| 7 | Decision search across all meetings and bodies | **V1** | Cross-cutting search — "what did we decide about parking policy?" |
| 8 | Decision dependency tracking (this decision supersedes/amends that one) | **V1** | Legislative chains — amendments reference original decisions |
| 9 | Decision impact analysis (linked action items, affected documents) | **V1** | Governance oversight — what changed as a result of this decision |
| 10 | Decision templates (recurring decision types with pre-filled fields) | **V1** | Efficiency — standard resolutions (budget approval, appointment) reuse structure |
| 11 | Public decision register (citizen-facing portal) | **Enterprise** | Transparency requirement for government bodies (WOO/Woo compliance) |
| 12 | Decision federation (share decisions across Nextcloud instances) | **Enterprise** | Cross-organization governance — inter-municipal cooperation (Gemeenschappelijke Regelingen) |

### 2.2 Meeting Management

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 13 | Meeting CRUD with type classification (council, board, committee, MT, ALV) | **MVP** | Core entity — meetings are the container for all governance activities |
| 14 | Meeting list with calendar view and filters | **MVP** | Overview and scheduling — see all upcoming governance meetings |
| 15 | Meeting detail view with agenda, attendees, documents | **MVP** | Single-page meeting overview before and during the session |
| 16 | Meeting series (recurring meetings with linked history) | **MVP** | Most governance bodies meet regularly (monthly council, weekly MT) |
| 17 | Attendee management with role assignment (chair, secretary, member, observer) | **MVP** | Formal meetings require role-based permissions and protocol |
| 18 | Quorum tracking (minimum attendance for valid decisions) | **MVP** | Legal requirement — decisions without quorum are void (BW 2:40) |
| 19 | Meeting status workflow (scheduled/convened/in-progress/adjourned/closed) | **MVP** | Process tracking — know where each meeting is in its lifecycle |
| 20 | Convocation generation and distribution (via Nextcloud Mail/notifications) | **V1** | Statutory requirement — ALV convocation 14-28 days in advance |
| 21 | Attendance registration (present/absent/proxy) | **V1** | Formal record — who was present when decisions were made |
| 22 | Hybrid meeting support (in-person + remote participant tracking) | **V1** | Post-COVID standard — 74% of meetings now have remote participants |
| 23 | Meeting cost calculator (duration x attendee hourly rates) | **V1** | Analytics insight — $541B wasted globally, make cost visible |
| 24 | Speaking time tracking with balance indicators | **V1** | DEI impact — women's speaking time increased 65% when tracked (Equal Time study) |
| 25 | Meeting package/board book builder (auto-assemble agenda + documents) | **Enterprise** | Board portal feature — Diligent's AI Smart Book Builder is their key differentiator |
| 26 | Video/audio integration via Nextcloud Talk | **Enterprise** | Full meeting experience — join, record, transcribe without leaving Decidesk |

### 2.3 Agenda Management

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 27 | Agenda CRUD with ordered items (drag-and-drop reordering) | **MVP** | Core entity — every formal meeting needs a structured agenda |
| 28 | Agenda item types (discussion, decision, information, procedural) | **MVP** | Different items require different workflows (voting vs. noting) |
| 29 | Document attachments per agenda item (via Nextcloud Files) | **MVP** | Documents drive decisions — attach reports, proposals, financial statements |
| 30 | Agenda item time allocation (estimated duration per item) | **MVP** | Meeting efficiency — prevent agenda overload |
| 31 | Agenda templates (standard meeting types with pre-defined items) | **V1** | Efficiency — council meetings always open with "vaststelling agenda" and close with "rondvraag" |
| 32 | Agenda item proposals (members submit items for consideration) | **V1** | Democratic process — council members and MT members propose agenda items |
| 33 | Agenda item carry-over (unfinished items auto-move to next meeting) | **V1** | Practical need — meetings frequently run out of time |
| 34 | Document submission deadlines per agenda item | **V1** | Process discipline — documents must be available before the meeting |
| 35 | Consent agenda (batch non-controversial items for single vote) | **V1** | Efficiency pattern — commonly used in boards and councils (hamerstukken) |
| 36 | Agenda publication (public/member-only with configurable visibility) | **Enterprise** | Transparency — public agendas required for government meetings (WOO) |
| 37 | Agenda versioning (track changes between draft and final) | **Enterprise** | Audit trail — know what changed after the presidium approved the agenda |

### 2.4 Voting System

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 38 | Simple majority voting (for/against/abstain) | **MVP** | Most common voting type across all domains |
| 39 | Named/roll-call voting (record who voted what) | **MVP** | Transparency requirement for councils and boards |
| 40 | Secret ballot voting | **MVP** | Required for personnel decisions (board elections, appointments) |
| 41 | Voting result display with counts and percentages | **MVP** | Immediate feedback — show outcome when voting closes |
| 42 | Quorum validation before vote (prevent invalid votes) | **MVP** | Legal safeguard — vote is void without quorum |
| 43 | Qualified/supermajority voting (2/3, 3/4 thresholds) | **V1** | Required for statute amendments, constitutional changes (BW 2:42) |
| 44 | Proxy voting (delegate your vote to another member) | **V1** | Common in associations and shareholder meetings |
| 45 | Weighted voting (votes proportional to shares/membership) | **V1** | Corporate governance — shareholder votes weighted by shares |
| 46 | Multi-option voting (choose from 3+ options) | **V1** | Practical need — selecting between multiple proposals |
| 47 | Voting deadline (async voting with close date) | **V1** | Loomio's core feature — time-boxed decisions for distributed teams |
| 48 | Ranked-choice / preferential voting | **Enterprise** | Advanced method for multi-candidate elections |
| 49 | Approval voting (approve/disapprove each option independently) | **Enterprise** | Modern voting theory — reduces strategic voting |
| 50 | Blockchain-verifiable vote records | **Enterprise** | Trust layer for high-stakes votes — provably immutable audit trail |

### 2.5 Motion & Amendment Management

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 51 | Motion CRUD (submit, second, discuss, vote, resolve) | **MVP** | Core legislative workflow — motions drive council decisions |
| 52 | Motion lifecycle (draft/submitted/seconded/debated/voted/carried/defeated) | **MVP** | Formal process tracking — know the status of every motion |
| 53 | Motion-to-decision linking (approved motion becomes decision) | **MVP** | Workflow completion — close the loop from proposal to decision |
| 54 | Amendment CRUD with diff view (show what changes vs. original) | **V1** | Legislative essential — amendments are the core of council debate |
| 55 | Amendment voting order (sub-amendments first, then amendments, then main) | **V1** | Parliamentary procedure — voting order is legally prescribed |
| 56 | Motion co-sponsorship (multiple parties support a motion) | **V1** | Political coalition building — visible support before formal vote |
| 57 | Motion withdrawal and replacement | **V1** | Process — authors can withdraw motions before vote |
| 58 | Moties/amendementen tracking (Dutch council instrument types) | **V1** | Dutch government — motie, amendement, initiatiefvoorstel, schriftelijke vragen |
| 59 | Motion template library (standard formats per governance body) | **Enterprise** | Standardization — ensure motions follow required format |
| 60 | Amendment conflict detection (two amendments that contradict each other) | **Enterprise** | Process integrity — flag when amendments cannot both pass |

### 2.6 Resolution & Minutes

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 61 | Minutes generation from meeting (attendees, decisions, action items) | **MVP** | Core output — minutes are the legal record of the meeting |
| 62 | Minutes template with configurable sections | **MVP** | Structure — minutes follow a standard format per meeting type |
| 63 | Action item extraction from minutes (who, what, when) | **MVP** | Accountability — 44% of action items are never completed (HBR) |
| 64 | Minutes approval workflow (draft/review/approved) | **V1** | Legal process — minutes must be formally approved at next meeting |
| 65 | Resolution register (searchable archive of all passed resolutions) | **V1** | Compliance — organizations must maintain a decision register |
| 66 | Minutes PDF export with digital signatures | **V1** | Legal validity — formal minutes require signatures of chair and secretary |
| 67 | AI-assisted minutes drafting (summarize discussion, extract decisions) | **Enterprise** | AI meeting tools grew 17x in 2024 — self-hosted alternative to Otter.ai/Fireflies |
| 68 | AI transcription integration (speech-to-text for recorded meetings) | **Enterprise** | Privacy-first transcription — Otter.ai faces lawsuits over consent; self-hosted solves this |

### 2.7 Process Configuration (State Machine Templates)

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 69 | Pre-built governance templates (council meeting, board meeting, ALV, MT) | **MVP** | Out-of-box usability — users should not configure from scratch |
| 70 | Configurable decision workflow per body type | **V1** | Different bodies have different procedures (Robert's Rules vs. Dutch Reglement van Orde) |
| 71 | Custom meeting type creation with field configuration | **V1** | Flexibility — organizations have unique meeting types |
| 72 | Role-based permissions per governance body | **V1** | Security — council members see different things than committee observers |
| 73 | Process template import/export (share configurations between organizations) | **Enterprise** | Scalability — a municipality template can be shared across 345 Dutch municipalities |
| 74 | BPMN-compatible workflow runtime | **Enterprise** | Advanced automation — complex multi-step approval chains |

### 2.8 Meeting Efficiency & Analytics

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 75 | Meeting duration tracking (planned vs. actual) | **MVP** | Basic efficiency metric — first step to improving meetings |
| 76 | Action item completion rate dashboard | **V1** | Accountability metric — track whether decisions lead to action |
| 77 | Decision velocity (average time from proposal to decision) | **V1** | Process efficiency — identify bottlenecks in governance |
| 78 | Meeting frequency and participation trends | **V1** | Organizational insight — are we meeting too much or too little? |
| 79 | Speaking time analytics (per member, per meeting, over time) | **V1** | DEI and engagement — identify imbalances and disengagement |
| 80 | Meeting cost analytics (total hours x rates, per body, per quarter) | **Enterprise** | Executive insight — make meeting costs visible to management |
| 81 | Governance health scorecard (composite metric across bodies) | **Enterprise** | Board-level reporting — overall governance effectiveness |

### 2.9 Dashboard & My Work

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 82 | Personal dashboard (my upcoming meetings, my action items, my votes pending) | **MVP** | Productivity essential — one place to see everything requiring attention |
| 83 | Upcoming meetings widget with quick-join | **MVP** | Efficiency — reduce clicks to enter a meeting |
| 84 | Pending decisions requiring my vote | **MVP** | Action-oriented — never miss a vote deadline |
| 85 | Action items assigned to me with due dates | **MVP** | Accountability — see what you owe from past decisions |
| 86 | Cross-body overview (all governance bodies I belong to) | **V1** | Members often sit on multiple committees/boards |
| 87 | Overdue item highlighting with escalation | **V1** | Proactive management — surface items that need attention |
| 88 | Nextcloud Dashboard widget integration | **V1** | Platform integration — Decidesk data on the Nextcloud home screen |
| 89 | Workload analytics per member (items assigned, completion rate) | **Enterprise** | Management visibility — identify overloaded or disengaged members |

### 2.10 Admin Settings

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 90 | Nextcloud admin settings page | **MVP** | App configuration entry point |
| 91 | Governance body management (CRUD councils, boards, committees) | **MVP** | Core configuration — define the organizational structure |
| 92 | Default meeting templates selection | **MVP** | Out-of-box experience — pre-select templates for common body types |
| 93 | Voting method configuration per body | **MVP** | Each body uses different voting rules |
| 94 | Quorum rules configuration (absolute number or percentage) | **V1** | Flexible quorum — some bodies need 50%+1, others need specific member counts |
| 95 | Decision numbering scheme configuration (auto-increment, year-based) | **V1** | Administrative — decisions need unique identifiers (e.g., B2026-0042) |
| 96 | Meeting type and category management | **V1** | Customization — add organization-specific meeting categories |
| 97 | Retention policy configuration (archive after X months) | **Enterprise** | Compliance — government bodies have specific retention requirements (Archiefwet) |
| 98 | Multi-tenant governance (manage multiple organizations) | **Enterprise** | Service provider use case — one Nextcloud instance serving multiple bodies |

### 2.11 User Settings

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 99 | Personal notification preferences (email, push, in-app) | **MVP** | User control — not everyone wants the same notification level |
| 100 | Default view preference (calendar/list/kanban) | **MVP** | UX personalization — power users prefer list, casual users prefer calendar |
| 101 | Timezone and locale settings | **V1** | International governance bodies with members in different timezones |
| 102 | Digest frequency (real-time, daily, weekly) | **V1** | Noise control — reduce notification fatigue |
| 103 | Proxy/delegate configuration (who can act on my behalf) | **Enterprise** | Formal delegation — assign voting proxy for vacation periods |

### 2.12 Notifications

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 104 | Meeting invitation and reminder notifications | **MVP** | Basic — know about upcoming meetings |
| 105 | Vote pending notification (new vote requires your input) | **MVP** | Action-oriented — time-sensitive voting deadlines |
| 106 | Action item assigned notification | **MVP** | Accountability — know when you are assigned a task |
| 107 | Decision outcome notification (motion carried/defeated) | **MVP** | Awareness — know the result of votes you participated in |
| 108 | Agenda published notification | **V1** | Preparation — review agenda before the meeting |
| 109 | Minutes available for approval notification | **V1** | Workflow — minutes need formal approval at next meeting |
| 110 | Action item overdue reminder | **V1** | Proactive — escalate before deadlines are missed |
| 111 | Quorum risk alert (not enough RSVPs for upcoming meeting) | **V1** | Prevention — warn organizers before the meeting fails quorum |
| 112 | Convocation deadline reminder (days until statutory deadline) | **Enterprise** | Compliance — remind secretary of ALV convocation timing requirements |
| 113 | Governance calendar sync (all meetings to Nextcloud Calendar) | **Enterprise** | Platform integration — governance meetings appear alongside regular calendar |

### 2.13 Communication & Collaboration

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 114 | Internal notes on any entity (via ICommentsManager) | **MVP** | Collaboration — annotate decisions, agenda items, motions |
| 115 | Document co-editing (Nextcloud Files + Collabora/OnlyOffice) | **MVP** | Platform leverage — edit meeting documents collaboratively |
| 116 | Threaded discussion per agenda item | **V1** | Async deliberation — discuss items before the meeting (Loomio's strength) |
| 117 | Talk integration (per-meeting/per-body chat room via IBroker) | **V1** | Unique differentiator — real-time chat alongside formal governance |
| 118 | User mentions in notes and discussions | **V1** | Team collaboration — draw attention to specific people |
| 119 | Shared document folders per governance body (Files) | **V1** | Document management — all board documents in one place |
| 120 | Email distribution list per body (via Nextcloud Mail) | **Enterprise** | Communication — reach all members of a body via email |
| 121 | Public comment periods on published decisions | **Enterprise** | Citizen participation — Decidim-style public input on governance decisions |

### 2.14 Security & Compliance

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 122 | RBAC via OpenRegister (body-level, meeting-level permissions) | **MVP** | Access control — members see only bodies they belong to |
| 123 | Full audit trail (who changed what, when, on every entity) | **MVP** | Legal accountability — governance requires traceable records |
| 124 | WCAG AA compliance | **MVP** | Government requirement — accessibility is non-negotiable |
| 125 | Conflict of interest declaration and recusal tracking | **V1** | Governance integrity — members must declare conflicts and recuse from votes |
| 126 | GDPR data export (right of access for member data) | **V1** | EU compliance — members can request all their governance data |
| 127 | GDPR data deletion (right to erasure with retention exceptions) | **V1** | EU compliance — balanced against archival obligations (Archiefwet) |
| 128 | NL Design System theming (via nldesign app) | **V1** | Dutch government visual compliance — Rijkshuisstijl and municipal tokens |
| 129 | Data classification labels (public/confidential/secret) per item | **Enterprise** | Information security — board discussions often contain confidential material |
| 130 | ISO 27001 compatible audit logging | **Enterprise** | Enterprise compliance — structured logs for security audits |
| 131 | End-to-end encryption for confidential votes | **Enterprise** | Trust — secret ballots must be cryptographically secure |

### 2.15 Integration

| # | Feature | Tier | Justification |
|---|---------|------|---------------|
| 132 | Nextcloud Calendar sync (meetings as calendar events) | **MVP** | Platform essential — governance meetings in user's calendar |
| 133 | Nextcloud Files integration (document attachments via IRootFolder) | **MVP** | Platform essential — all meeting documents managed via Files |
| 134 | REST API (via OpenRegister) | **V1** | Interoperability — external systems can query decisions and meetings |
| 135 | Open Raadsinformatie API compatibility (publish to ORI standard) | **V1** | Dutch gov interop — 265/345 municipalities use ORI for open data |
| 136 | Nextcloud Flows automation (triggers on decision/vote/meeting events) | **V1** | Low-code automation — send email when decision is made, create task on action item |
| 137 | Zaaksysteem integration (decision triggers a zaak in OpenZaak/Procest) | **V1** | Dutch gov workflow — council decisions often initiate administrative processes |
| 138 | Webhook support (notify external systems on events) | **Enterprise** | External integration — connect to existing governance toolchains |
| 139 | Microsoft 365 calendar/email bridge | **Enterprise** | Enterprise reality — many organizations use M365 alongside Nextcloud |
| 140 | BPMN process engine integration (Flowable/Camunda) | **Enterprise** | Advanced automation — complex multi-step governance workflows |

## 3. Settings & Notifications

### 3.1 Admin Settings

Derived from feature matrix items #90-98:

| Setting | Tier | Source Features |
|---------|------|----------------|
| Governance body management (create/edit/archive bodies) | **MVP** | #91 |
| Default meeting template per body type | **MVP** | #92 |
| Voting method defaults (simple majority, named, secret) | **MVP** | #93 |
| Quorum rules (percentage or absolute count) per body | **V1** | #94 |
| Decision numbering scheme (auto-increment, year-prefix) | **V1** | #95 |
| Meeting type and category taxonomy | **V1** | #96 |
| Document retention policies per body type | **Enterprise** | #97 |
| Multi-tenant organization management | **Enterprise** | #98 |
| AI feature configuration (transcription model, language) | **Enterprise** | #67, #68 |
| Federation endpoints (trusted Nextcloud instances) | **Enterprise** | #12 |

### 3.2 User Settings

Derived from feature matrix items #99-103:

| Setting | Tier | Source Features |
|---------|------|----------------|
| Notification channel preference (email/push/in-app) | **MVP** | #99 |
| Default view mode (calendar/list/board) | **MVP** | #100 |
| Timezone and locale | **V1** | #101 |
| Digest frequency (real-time/daily/weekly) | **V1** | #102 |
| Proxy/delegate assignment | **Enterprise** | #103 |
| Calendar sync toggle per governance body | **V1** | #113 |

### 3.3 Notifications

Derived from feature matrix items #104-113:

| Notification | Trigger | Tier |
|-------------|---------|------|
| Meeting invitation | Meeting created or user added as attendee | **MVP** |
| Meeting reminder | Configurable time before meeting start | **MVP** |
| Vote pending | New vote opened that requires user input | **MVP** |
| Action item assigned | Action item created and assigned to user | **MVP** |
| Decision outcome | Vote closed, motion carried or defeated | **MVP** |
| Agenda published | Final agenda released for upcoming meeting | **V1** |
| Minutes for approval | Draft minutes shared for review | **V1** |
| Action item overdue | Due date passed without completion | **V1** |
| Quorum risk | Insufficient RSVPs for upcoming meeting | **V1** |
| Convocation deadline | Statutory deadline approaching for convocation | **Enterprise** |
| Governance calendar | All meeting events synced to Nextcloud Calendar | **Enterprise** |

## 4. Gap Analysis

### 4.1 What Competitors Do Well

- **Enterprise SaaS (Diligent, OnBoard, iBabs)**: Polished board portal UX, AI features (smart minutes, book builder), mobile apps with offline access, SOC/ISO certifications, 24/7 support
- **Self-hosted OSS (Loomio, Decidim, OpenSlides)**: Full data sovereignty, no licensing costs, strong community governance, open APIs, democratic design process
- **Dutch RIS (GO, Notubiz, Parlaeus)**: Deep domain expertise in Dutch legislative procedures, webcasting/recording, council instrument tracking (moties/amendementen), zaaksysteem integration
- **Meeting productivity (Fellow.app, Meeting Decisions)**: Seamless calendar/Teams integration, AI transcription and summarization, lightweight adoption (no formal setup needed)

### 4.2 What They Lack

No competitor covers all five decision-making domains. This is the fundamental market gap:

| Domain | GO/Notubiz | Diligent/OnBoard | Fellow/Otter | Loomio | Decidim | OpenSlides | **Decidesk** |
|--------|-----------|-----------------|-------------|--------|---------|------------|-------------|
| Legislative (councils, parliaments) | Yes | No | No | No | No | Partial | **Yes** |
| Corporate governance (boards, AGM) | No | Yes | No | Partial | No | No | **Yes** |
| Corporate operations (MT, departments) | No | No | Yes | Partial | No | No | **Yes** |
| Associations (ALV, member orgs) | No | No | No | Partial | No | Partial | **Yes** |
| Citizen participation | No | No | No | Partial | Yes | No | **Yes** |

Additional gaps across the market:

| Gap | Impact | Decidesk Advantage |
|-----|--------|-------------------|
| No self-hosted AI meeting intelligence | Otter.ai/Fireflies face lawsuits over consent and biometric data; no GDPR-native alternative exists | Self-hosted transcription via Nextcloud AI (Whisper), data never leaves the server |
| No integrated collaboration platform | Every competitor requires 5+ separate tool integrations for chat, files, calendar, email, tasks | Nextcloud provides all of these natively — zero integration cost |
| No cross-organization federation | Municipalities cooperating in a Gemeenschappelijke Regeling cannot share governance data | Nextcloud federation protocol enables cross-instance decision sharing |
| No design token theming | No competitor supports Dutch government design standards (Rijkshuisstijl) | NL Design System via nldesign app provides compliant theming |
| Meeting tools lack formal governance | Fellow/Otter have no voting, no motions, no minutes-as-legal-documents | Decidesk treats formal governance as core, not an afterthought |
| Governance tools lack meeting efficiency | Diligent/iBabs do not track meeting costs, speaking time, or participation patterns | Analytics from day one — make $541B meeting waste visible |
| Dutch RIS search is broken | Half of 342 municipalities have inadequate search (NOS Nieuwsuur) | Full-text search via OpenRegister with faceting by body, date, topic |
| Price gap | Diligent: $48-155K/yr; GO/Notubiz: EUR 10-60K/yr; iBabs: opaque per-user pricing | Open source core; self-hosted; no per-user fees |

### 4.3 Nextcloud-Native Advantages

| Capability | Why Competitors Cannot Match It |
|------------|-------------------------------|
| Zero-cost collaboration stack | Chat (Talk), files, calendar, mail, tasks — all built in. Competitors need 5+ separate integrations. |
| Federated cross-org governance | Nextcloud federation protocol enables sharing decisions/meetings across instances. No governance tool has federation. |
| Design token theming | NL Design System via nldesign app is Nextcloud-specific. Government visual compliance out of the box. |
| Data platform reuse | OpenRegister objects are shared across Procest (zaaksysteem), OpenCatalogi, Pipelinq. Decision data is not siloed. |
| Self-hosted AI | Whisper transcription, LLM summarization — all on-premise. Solves the Otter.ai consent lawsuit problem. |
| Air-gapped deployment | Military, intelligence, critical infrastructure — SaaS governance tools cannot function without internet. |
| Virtual calendar provider | Governance meetings appear in user's calendar without sync configuration. |
| Talk rooms per governance body | Built-in real-time chat with video — no competitor bundles this. |
| Nextcloud Flows automation | Low-code automation triggers on governance events (decision made, vote closed, action item overdue). |

## 5. Strategic Positioning

### 5.1 Positioning Statement

**Decidesk is the decision-making platform that works everywhere decisions happen.** From municipal council chambers to corporate boardrooms, from association assemblies to management team meetings — one tool for the entire governance spectrum. Built natively into Nextcloud, it turns your collaboration platform into a universal decision engine with voting, minutes, motions, and analytics already connected to your files, calendar, and chat.

### 5.2 Differentiation Strategy

Three pillars:

1. **Universal governance** — The only platform spanning all five decision-making domains (legislative, corporate governance, corporate operations, associations, citizen participation). Competitors serve one or two domains; Decidesk serves all with configurable process templates.

2. **Privacy-first intelligence** — Self-hosted AI transcription and minutes generation without sending audio/video to third-party clouds. Addresses the Otter.ai/Fireflies consent lawsuit problem. GDPR-native by architecture, not by policy.

3. **Platform leverage** — Every Nextcloud capability (Talk, Files, Calendar, Mail, AI, Flows, Federation, NL Design System) automatically benefits Decidesk. Competitors must build or buy these integrations. This structural advantage compounds over time.

### 5.3 Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Feature gap vs. specialized tools (Diligent for boards, Notubiz for councils) | High | Focus MVP on the universal decision lifecycle; don't try to match every specialized feature in V1 |
| Domain expertise required (parliamentary procedure, corporate law, association statutes) | High | Pre-built templates per domain with sensible defaults; advisory board from governance practitioners |
| Regulatory complexity (Archiefwet, BW 2:41, WOO, Reglement van Orde) | Medium | Templates encode regulatory requirements; compliance as configuration, not code |
| AI transcription quality (self-hosted Whisper vs. cloud Otter.ai) | Medium | Enterprise tier only; improve with fine-tuning; quality is rapidly improving |
| User adoption across diverse audiences (council clerks, board secretaries, MT members) | High | Role-based simplified views; guided onboarding per domain; NL Design System for familiar government UX |
| OpenRegister dependency | Medium | Actively developed, used by multiple apps (Pipelinq, OpenCatalogi, Procest) |
| Competition from Microsoft Teams meeting features (Copilot, Loop, Planner) | High | Teams lacks formal governance (voting, motions, minutes-as-legal-documents); position as governance layer on top of any platform |

## 6. Recommended Feature Set Summary

### MVP (30 features)

Replace informal meeting notes and disconnected email threads with structured governance. Covers the universal decision lifecycle: agenda, meeting, motion, vote, decision, minutes, action items.

**Decision Management**
1. Decision CRUD with status lifecycle
2. Decision list with search, sort, filters
3. Decision detail view with full context
4. Decision categorization (tags, topics)
5. Decision assignment to responsible parties
6. Decision timeline/audit trail

**Meeting Management**
7. Meeting CRUD with type classification
8. Meeting list with calendar view
9. Meeting detail view with agenda and attendees
10. Meeting series (recurring)
11. Attendee management with roles (chair, secretary, member)
12. Quorum tracking
13. Meeting status workflow

**Agenda Management**
14. Agenda with ordered items (drag-and-drop)
15. Agenda item types (discussion, decision, information)
16. Document attachments per agenda item
17. Agenda item time allocation

**Voting System**
18. Simple majority voting (for/against/abstain)
19. Named/roll-call voting
20. Secret ballot voting
21. Voting result display
22. Quorum validation before vote

**Motion & Minutes**
23. Motion CRUD with lifecycle
24. Motion-to-decision linking
25. Minutes generation from meeting
26. Minutes template with configurable sections
27. Action item extraction from minutes

**Dashboard & Platform**
28. Personal dashboard (my meetings, my votes, my action items)
29. Nextcloud Calendar + Files integration
30. Pre-built governance templates (council, board, ALV, MT), RBAC, audit trail, WCAG AA, EN/NL

### V1 (30 additional features)

Compete with iBabs and Notubiz for Dutch government; compete with Fellow.app for corporate meetings. Full analytics, advanced voting, async deliberation, and Dutch government integration.

31. Convocation generation and distribution
32. Attendance registration (present/absent/proxy)
33. Hybrid meeting support
34. Meeting cost calculator
35. Speaking time tracking with balance indicators
36. Agenda templates
37. Agenda item proposals from members
38. Agenda item carry-over
39. Consent agenda (hamerstukken)
40. Qualified/supermajority voting
41. Proxy voting
42. Weighted voting
43. Multi-option voting
44. Voting deadline (async voting)
45. Amendment CRUD with diff view
46. Amendment voting order
47. Motion co-sponsorship
48. Moties/amendementen tracking (Dutch instruments)
49. Minutes approval workflow
50. Resolution register
51. Minutes PDF export with signatures
52. Configurable decision workflow per body
53. Action item completion rate dashboard
54. Decision velocity analytics
55. Meeting frequency and participation trends
56. Threaded discussion per agenda item (async deliberation)
57. Talk integration per body
58. Open Raadsinformatie API compatibility
59. Zaaksysteem integration (Procest bridge)
60. NL Design System theming, GDPR export/deletion, conflict of interest tracking

### Enterprise (20 additional features)

Large municipalities, multi-organization deployments, and regulated industries. AI-powered meeting intelligence, federation, and advanced process automation.

61. AI-assisted minutes drafting
62. AI transcription integration (self-hosted Whisper)
63. Decision federation across Nextcloud instances
64. Meeting package/board book builder
65. Video/audio integration via Nextcloud Talk
66. Public decision register (WOO compliance)
67. Agenda publication with configurable visibility
68. Ranked-choice / preferential voting
69. Approval voting
70. Blockchain-verifiable vote records
71. Process template import/export
72. BPMN-compatible workflow runtime
73. Meeting cost analytics (per body, per quarter)
74. Governance health scorecard
75. Data classification labels (public/confidential/secret)
76. ISO 27001 compatible audit logging
77. End-to-end encryption for confidential votes
78. Microsoft 365 calendar/email bridge
79. Multi-tenant governance
80. Public comment periods on published decisions
