# Proposal: Citizen Participation

## Why

Phase 1 and Phase 2 established the internal governance workflows (meetings, agendas, motions, voting, minutes, and decisions) for governance bodies and staff. However, Decidesk remains an admin-only platform with no public-facing capabilities. Citizens cannot view decisions, participate in deliberation, vote on proposals, or engage in participatory budgeting. This limits adoption in jurisdictions that require citizen engagement pathways (digital accessibility mandates, participatory democracy requirements, transparency laws like the Dutch Woo).

Market demand analysis shows 51 citizen participation features requested, with the top features (online voting procedures, citizen panels, participatory budgeting, digital deliberation) cited in 67, 100, 70, and 74 tender documents respectively. This is the highest-demand capability set, yet presently unaddressable in Decidesk.

Phase 3 introduces **citizen-facing capabilities**: public voting interfaces, citizen panels, participatory budgeting, digital deliberation forums, citizen dashboards, transparency portals, and offline participation support. This transforms Decidesk from a governance-body administrative tool into a universal decision-making platform accessible to the citizens it serves.

## What Changes

### Frontend
- New **public citizen portal** with no authentication requirement for read-only access (decisions, meeting calendars, published agendas, voting results)
- **Citizen dashboard**: overview of active votes, citizen panels, budgets, and deliberation opportunities
- **Online voting interface**: simplified, accessible voting UI for citizens (separate from staff voting UI)
- **Citizen panel management UI**: registration, panel member communications, feedback collection
- **Participatory budgeting interface**: proposal submission, voting, results visualization
- **Public consultation/deliberation spaces**: threaded discussion, structured feedback, consensus building
- **Offline participation forms**: PDF generation for printing, QR codes for digital submission from analog channels
- **Transparency portal**: searchable decisions, meeting recordings, minutes, ORI-compatible open data API output
- **Notifications system**: email/SMS notifications for citizen engagement events (votes opened, panel invitations, budgets published)

### Backend
- **Public API endpoints** for citizen data access (read-only for non-authenticated users, authenticated for participation)
- **Participation tracking** via OpenRegister relations (citizen votes, panel memberships, proposal submissions, feedback)
- **Accessibility compliance** enforcement (WCAG 2.1 AA for all citizen-facing UIs)
- **Multi-language support** for citizen interfaces (Dutch + English minimum)
- **Integration with NL Design System** tokens for theming and consistency
- **Offline submission pipeline** (QR code scanning, PDF form processing, manual entry fallback)
- **Notification delivery** (email, SMS, in-app messages)
- **Transparency audit logging** (all citizen views/interactions logged per Woo compliance)

### Data Model Extensions
- **CitizenVote** entity: records individual citizen votes on motions/amendments (distinct from staff/member votes)
- **CitizenPanel** entity: structured citizen advisory groups (membership, term, scope)
- **ParticipatoryBudget** entity: budget allocation proposals with voting
- **Deliberation** entity: structured discussion forum per topic/motion
- **Notification** entity: tracks citizen notification preferences and delivery
- **PublicConsultation** entity: formal consultation rounds with feedback collection
- **TransparencyRecord** entity: audit trail of citizen access to public data

### Data Access Control
- **Public data**: meeting calendar, published agendas, adopted decisions, voting results, minutes (if published) — accessible without authentication
- **Authenticated participation**: citizen votes, panel memberships, budget votes, deliberation posts — require Nextcloud login or external identity federation (SAML, OIDC)
- **Staff-only data**: draft agendas, confidential motions, internal notes, unapproved minutes — hidden from public view
- **Per-governance-body policies**: each organization controls which content is public, which is staff-only, which requires authentication

## Capabilities

### New Capabilities
- `citizen-voting`: Online voting procedures and result visualization for citizens. Citizens can view active votes, submit votes, and see tallied results. Support for simple majority, weighted voting, ranked-choice. Separate UI from staff voting. Per-governance-body voting method configuration.
- `citizen-panels`: Management and participation in structured citizen advisory panels. Support for panel creation, membership invitation/acceptance, term dates, scope definition, and feedback collection. Public roster of panels with descriptions.
- `participatory-budgeting`: Budgeting features for citizen input. Propose projects, submit budgets, vote on allocations, view results. Multi-round workflows (proposal → voting → monitoring). Public transparency on budget status.
- `public-consultations`: Structured consultation and deliberation spaces. Threaded discussions linked to motions/decisions. Feedback collection with structured forms. Consensus tracking. Moderation tools.
- `citizen-dashboard`: Citizen-facing dashboard showing active opportunities (votes, panels, budgets, consultations), personalized notifications, participation history, and recommended next steps.
- `transparency-portal`: Public access to governance information. Searchable decisions by date/topic/area, meeting calendar with agenda/minutes/recordings, voting results, ORI-compatible API access, open data export.
- `offline-participation`: Support for citizens with limited digital access. PDF form generation, QR code scanability, paper-based submission workflows, telephone intake, in-person registration at public access points. Analog forms automatically imported into digital system.
- `citizen-notifications`: Notification delivery system. Email/SMS/in-app notifications for citizen engagement events. Preference management. Batch delivery. Compliance with GDPR/Woo notification requirements.

### Modified Capabilities
- `meeting-management` (**BREAKING**): Add `isPublic` boolean to Meeting entity. When true, agenda (if published), recordings, and minutes are visible to unauthenticated users. Defines governance-body default publishing policy. Existing meetings default to `isPublic: false` (staff-only) — no behavior change for existing meetings.
- `motion-and-voting` (**BREAKING**): Add `citizenVotingAllowed` boolean and `citizenVotingMethod` to Motion entity. When true, citizens can participate in voting alongside staff votes. Existing motions default to `citizenVotingAllowed: false` — no behavior change unless explicitly enabled. Voting results show separate tabs: "Staff votes" vs "Citizen votes" vs "Combined".
- `decisions` (**BREAKING**): Add `isPublished` enum field to Decision entity (values: `internal`, `public`, `confidential`). Default `internal` (staff-only). When `public`, decision is visible in transparency portal. Supports later scheduled publication. Existing decisions default `internal` — no behavior change.

## Impact

### Code Affected
- **Backend**: `lib/Controller/` (new public/citizen endpoints), `lib/Service/` (citizen voting, panels, budgeting logic)
- **Frontend**: `src/views/` (new citizen dashboard, voting UI, panel UI, consultation UI), `src/components/` (citizen-specific components), `src/router/` (public routes)
- **Data**: OpenRegister register schemas (new CitizenVote, CitizenPanel, ParticipatoryBudget, etc. schemas)
- **API**: New public `/api/citizens/` endpoint namespace

### Dependencies
- **Nextcloud**: Core auth, IUserSession (for authenticated citizen endpoints), IGroupManager (role-based access)
- **OpenRegister**: ObjectService (store citizen data), SearchService (find active votes/panels)
- **@nextcloud/vue**: NcDialog, NcButton, NcEmptyState (citizen UI components)
- **@conduction/nextcloud-vue**: CnDataTable, CnDetailPage, CnDetailCard, CnFormDialog (citizen participation forms)
- **NL Design System**: Token set switching per governance-body branding (per ADR-010)
- **External**: Optional SAML/OIDC identity federation for citizen authentication (defer to phase 4)

### Governance Domains Impacted
All 5 domains can benefit from citizen participation:
1. **Legislative** (municipalities, provinces) → citizens vote on referenda, participate in deliberation
2. **Associations/NGOs** → members vote, provide feedback via citizen panels
3. **Corporate** → shareholders participate in voting, supervisory board decisions published
4. **Corporate operations** → employees vote on operational decisions, see meeting transparency
5. **Citizen participation** (initiatives, budgets, referenda) → PRIMARY USE CASE for this phase

### Testing Requirements
- **WCAG 2.1 AA compliance** for all citizen-facing UIs (keyboard nav, color contrast, screen reader support)
- **Offline participation workflow** tested end-to-end (PDF generation → QR scan → import → vote recorded)
- **Multi-governance-body isolation** verified (citizen from body A cannot see body B's data)
- **ORI API output** validated against ORI specification schema
- **Notification delivery** tested for all channels (email, SMS, in-app)
