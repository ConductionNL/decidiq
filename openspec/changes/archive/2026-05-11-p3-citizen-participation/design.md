# Design: Citizen Participation

## Context

Phase 3 (p3-citizen-participation) opens Decidesk to public-facing citizen engagement. Phases 1 and 2 established internal governance workflows — meetings, agendas, motions, voting, minutes, and decisions — all accessible only to authenticated staff. Phase 3 adds a **public citizen portal** with unauthenticated read access, participation mechanisms (voting, panel membership, participatory budgeting, deliberation), a transparency portal for published governance data, and accessibility-first offline participation channels.

Market demand analysis identified citizen participation features as the highest-demand capability set: online voting procedures (demand: 394, 67 tender mentions), citizen panels (305, 100 tender mentions), digital deliberation forums (262, 70 tender mentions), and offline participation options (258, 74 tender mentions). Dutch municipalities under the Wet open overheid (Woo) and Wet digitaal vergaderen have legal transparency obligations presently unaddressable in Decidesk.

**Current state:** Decidesk is staff-only. All Meeting, Motion, VotingRound, Decision, and Minutes data is hidden behind Nextcloud authentication. There is no public API namespace, no citizen-specific entities, and no WCAG-compliant public portal.

**Constraints:**
- ADR-002 (CalDAV-first): only Meeting and ActionItem live in CalDAV; all new citizen entities use OpenRegister
- ADR-001 (Popolo): new entities mapped to Popolo where applicable; ORI/custom extensions otherwise
- ADR-003 (ORI compatibility): public decisions and meetings surfaced via ORI API
- Company ADR-001-data-layer: no custom DB tables; all domain data via ObjectService
- Company ADR-005-security: public endpoints must not leak staff-only data; per-object authorization on all mutations
- WCAG 2.1 AA mandatory for all citizen-facing components (Wet digitaal vergaderen)

**Stakeholders:** Citizens, council clerks (griffiers), faction leaders, transparency advocates, journalists, municipal ICT administrators, water board secretaries, corporate board secretaries.

---

## Goals / Non-Goals

**Goals:**
- Publish governance information (decisions, meeting calendars, voting results) to unauthenticated citizens
- Enable authenticated citizen participation: votes, panel memberships, budget proposals, deliberation posts
- Extend Meeting, Motion, and Decision entities with publication-control fields (additive, backward-compatible)
- Add 7 new OpenRegister schemas for citizen participation workflows
- Deliver WCAG 2.1 AA-compliant citizen portal with NL Design System theming per governance body branding
- Extend ORI API per ADR-003 with `/api/ori/v1/decisions` endpoint
- Support offline participation via PDF forms and QR code submission pipeline
- Notification delivery for citizen engagement events (email, in-app; SMS deferred)

**Non-Goals:**
- External SAML/OIDC identity federation for citizen authentication (deferred to Phase 4)
- AI-powered deliberation or automated consensus analytics
- Large-scale (200,000+ concurrent) participation events — Phase 3 targets small-medium bodies
- Full ORI harvesting protocol integration (separate adapter, future work)
- Blockchain-based vote integrity verification
- Real-time WebSocket meeting streaming — recordings published post-meeting
- SMS notification delivery (requires third-party gateway; deferred)

---

## Decisions

### Decision 1: All citizen participation entities stored in OpenRegister

**Choice:** CitizenVote, CitizenPanel, ParticipatoryBudget, BudgetProposal, PublicConsultation, Deliberation, and Notification schemas are added to `lib/Settings/decidesk_register.json` and stored via `ObjectService`.

**Rationale:** None of these entities map to CalDAV types (VEVENT/VTODO). Per ADR-002, only Meeting and ActionItem use CalDAV. All governance-specific entities live in OpenRegister. This pattern is consistent with existing entities (Motion, Vote, GovernanceBody) and inherits built-in capabilities: CRUD REST API, full-text search, filtering, pagination, audit trails, file attachments, and relation management — without custom code.

**Alternatives considered:**
- Custom DB tables: Rejected per company ADR-001-data-layer — no custom Entity/Mapper for domain data.
- Separate citizen register vs. single governance register: A single register per governance body is simpler for multi-tenancy and relational queries. Separate registers would require cross-register joins not supported by OpenRegister's relation model.

---

### Decision 2: Separate `/api/citizens/` namespace from staff endpoints

**Choice:** All citizen-facing API endpoints use the `/api/citizens/` namespace. Existing staff endpoints remain unchanged.

**Rationale:** A dedicated namespace makes authorization intent explicit: public endpoints have no-auth access by design, staff endpoints retain Nextcloud authentication. This avoids entangling authorization logic with query parameters and eliminates the risk of accidentally exposing staff-only data via flag omission.

**Alternatives considered:**
- Extend existing endpoints with `?public=true`: Rejected — authorization becomes entangled with query parameters; privilege escalation risk if flag validation has a gap.
- Expose OpenRegister endpoints directly with public access: Rejected — OpenRegister access control is tenant-scoped, not intent-scoped; cannot selectively hide draft agendas, confidential motions, or unapproved minutes from the same endpoint.

---

### Decision 3: CitizenVote entity separate from staff Vote entity

**Choice:** A new `CitizenVote` entity records citizen participation votes separately from staff/member votes (existing `Vote` entity linked to `VotingRound`).

**Rationale:** Staff votes are part of the formal governance `VotingRound` lifecycle with quorum rules, weighted votes, proxy delegation, and Akoma Ntoso / ORI serialization requirements. Citizen votes are participatory/advisory in nature, may occur on referenda without a `VotingRound`, and may be optionally anonymous. Mixing them would compromise formal vote counting integrity, complicate the ORI API output, and violate the auditability separation required by the Gemeentewet. Results are displayed in separate tabs: Staff / Citizen / Combined.

**Alternatives considered:**
- Reuse `Vote` entity with `type: citizen` discriminator: Rejected — Vote is tightly coupled to VotingRound; citizen votes span non-VotingRound contexts (referenda, citizen initiatives).
- Aggregate all votes into a single count: Rejected — Woo transparency and governance audit requirements mandate showing citizen and formal votes separately.

---

### Decision 4: Additive schema extensions to Meeting, Motion, Decision

**Choice:** Add `isPublic` (boolean, default `false`) to Meeting; `citizenVotingAllowed` (boolean, default `false`) + `citizenVotingMethod` (string, optional) to Motion; `isPublished` (enum: `internal` | `public` | `confidential`, default `internal`) to Decision.

**Rationale:** Existing data must not change behavior on upgrade. All new fields default to the most restrictive value. Optional property additions are non-breaking per company ADR-011-schema-standards. `importFromApp(force: false)` will apply the version bump and update schemas without data loss.

**Alternatives considered:**
- Separate `PublicMeeting` entity wrapping Meeting: Over-engineered for a single boolean flag; adds relation overhead.
- Separate `PublishedDecision` shadow entity: Requires sync logic between Decision and shadow; single entity with status field is cleaner and consistent with how `isPublished` is handled in Minutes.

---

### Decision 5: PublicLayout.vue replaces Nextcloud shell for citizen routes

**Choice:** A `PublicLayout.vue` component replaces the Nextcloud app shell for all routes under `/citizens/`. Routes are public by default; Nextcloud authentication is optional (deepens functionality when authenticated).

**Rationale:** Nextcloud's navigation sidebar and admin menu are irrelevant to citizens and potentially confusing. NL Design System requires governance body-specific CSS custom property token sets applied at the layout level per company ADR-010. A separate layout allows WCAG 2.1 AA compliance (simplified DOM structure, correct landmark regions) without impacting the staff UI.

**Alternatives considered:**
- Embed citizen portal in a standalone app separate from Nextcloud: Higher maintenance burden; loses Nextcloud authentication context for authenticated participation; certificate and token handling would need to be rebuilt.
- Use Nextcloud guest user accounts: Does not support anonymous browsing without account creation; SAML/OIDC federation not ready until Phase 4.

---

### Decision 6: TransparencyRecord omitted — use OpenRegister audit trail

**Choice:** Do not create a separate `TransparencyRecord` schema. Woo access logging is handled by OpenRegister's built-in `auditTrail` field available on all objects.

**Rationale:** OpenRegister's audit trail already captures read/write events per object per Woo traceability requirements. A custom TransparencyRecord would duplicate this and add maintenance overhead. The built-in mechanism is maintained by OpenRegister, not Decidesk.

**Alternatives considered:**
- Custom TransparencyRecord schema: Creates data duplication and maintenance burden. Rejected.

---

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| GDPR exposure — citizen PII (name, BSN, email) in public API responses | Sanitize all public endpoint responses; exclude PII fields; explicit opt-in required for notification delivery; no PII in server-side logs |
| Multi-tenancy isolation breach — citizen of body A sees body B data | Per-object authorization on all mutation endpoints; OpenRegister `_multitenancy: true` enforced; integration test suite covers cross-body isolation (task 23.6) |
| Offline submission fraud — fake paper form injection | QR codes include HMAC signature with expiry; offline submissions enter a staff review queue before being recorded to OpenRegister |
| WCAG 2.1 AA failure at launch | Axe scanner mandatory in CI pipeline; keyboard + NVDA/JAWS testing required before release; NL Design System components are pre-validated |
| OpenRegister schema migration conflict when adding fields to existing schemas | New fields are optional with defaults; `importFromApp(force: false)` skips unchanged schemas; non-breaking per ADR-011 |
| PDF generation library lock-in (TCPDF / Dompdf) | `OfflineFormGenerator` interface abstracts library; swap without API change |
| High-concurrency voting events (>10,000 simultaneous citizens) | Phase 3 scoped to small-medium governance bodies; OpenRegister write locking prevents duplicate votes; horizontal scaling is Phase 5 work |
| Notification opt-in compliance | Notification schema stores explicit consent timestamp; unsubscribe via single link (no login required); batch delivery excludes opted-out recipients |

---

## Migration Plan

1. **Register definition update** — Add 7 new schemas (`CitizenVote`, `CitizenPanel`, `ParticipatoryBudget`, `BudgetProposal`, `PublicConsultation`, `Deliberation`, `Notification`) and extend 3 existing schemas (`Meeting.isPublic`, `Motion.citizenVotingAllowed/citizenVotingMethod`, `Decision.isPublished`) in `lib/Settings/decidesk_register.json`. Version bump triggers re-import via `ConfigurationService::importFromApp()` on next repair step.

2. **Backward-compatible field defaults** — All new fields carry safe defaults (`false` / `internal`). Existing objects without these fields behave identically to before upgrade.

3. **Public Vue routes** — Added to Vue router under `/citizens/`; no conflicts with existing staff routes under `/governance/`.

4. **ORI endpoint extension** — `/api/ori/v1/decisions` added per ADR-003 structure; existing ORI endpoints are unchanged.

5. **Seed data** — Imported alongside schemas via `importFromApp()`. Idempotent via slug matching; re-importing skips existing objects.

**Rollback strategy:**
- Revert `decidesk_register.json` to previous version → new schemas become unused; existing schemas revert to field-less versions. OpenRegister ignores unknown fields on existing objects.
- Remove public Vue routes → citizen portal pages return 404.
- Remove new PHP controllers → `/api/citizens/` namespace returns 404.
- Existing staff workflows are unaffected by all reversions.

---

## Open Questions

1. **Anonymous vs. authenticated citizen votes in deliberation:** Should deliberation posts allow anonymous submission? The proposal says "optionally authenticated" — but governance bodies running legally-binding referenda may require verified identity. Resolution needed before implementing `CitizenVote.voterId` nullable logic.

2. **Ranked-choice voting algorithm:** Condorcet method or Instant-Runoff Voting? Condorcet is more defensible for pairwise comparison in governance contexts; IRV is more familiar to citizens. Decision deferred to implementation phase.

3. **Offline PDF generation library:** TCPDF (PHP-native), Dompdf, or Headless Chrome? Tagged PDF accessibility (WCAG PDF/UA) requires evaluation of each library's PDF/UA support before final selection.

4. **Notification delivery infrastructure:** Does Decidesk send emails directly via Nextcloud `IMailer`, or delegate to an n8n workflow via OpenRegister event hooks? Direct `IMailer` is simpler for Phase 3; n8n delegation enables richer templates and delivery tracking.

---

## Seed Data

All seed objects are defined in `lib/Settings/decidesk_register.json` under `components.objects[]` using the `@self` envelope format per company ADR-001-data-layer. Slugs are globally unique human-readable identifiers used for idempotent re-import matching.

### CitizenVote — 3 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "CitizenVote",
    "slug": "citizen-vote-windmolens-voor"
  },
  "voteValue": "voor",
  "voterId": "burger-jan-de-vries",
  "motionId": "motion-windmolens-haarlemmermeer",
  "weight": 1,
  "isProxy": false,
  "castAt": "2026-03-12T19:45:00+01:00",
  "notes": null
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "CitizenVote",
    "slug": "citizen-vote-windmolens-tegen"
  },
  "voteValue": "tegen",
  "voterId": "burger-marie-jansen",
  "motionId": "motion-windmolens-haarlemmermeer",
  "weight": 1,
  "isProxy": false,
  "castAt": "2026-03-12T20:02:00+01:00",
  "notes": null
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "CitizenVote",
    "slug": "citizen-vote-omgevingsplan-rijnenburg-voor"
  },
  "voteValue": "voor",
  "voterId": "burger-piet-bakker",
  "motionId": "motion-omgevingsplan-rijnenburg",
  "citizenPanelId": "citizen-panel-klimaatraad-utrecht",
  "weight": 1,
  "isProxy": false,
  "castAt": "2026-02-18T11:20:00+01:00",
  "notes": "Ingediend via papieren formulier, gescand door griffier op 2026-02-19"
}
```

---

### CitizenPanel — 4 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "CitizenPanel",
    "slug": "citizen-panel-bewonersraad-zuidoost"
  },
  "name": "Bewonersraad Zuidoost Amsterdam",
  "description": "Adviesorgaan voor bewoners in Amsterdam Zuidoost. Geeft gevraagd en ongevraagd advies over wonen, veiligheid en leefbaarheid in de wijk.",
  "scope": "Wonen, openbare ruimte en veiligheid in Amsterdam Zuidoost",
  "memberCount": 12,
  "termStart": "2025-01-01",
  "termEnd": "2027-01-01",
  "statusLifecycle": "active",
  "createdBy": "griffier@amsterdam.nl"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "CitizenPanel",
    "slug": "citizen-panel-klimaatraad-utrecht"
  },
  "name": "Klimaatraad Utrecht",
  "description": "Burgerpanel voor klimaatbeleid in Utrecht. Adviseert het college van B&W over CO2-neutraliteit 2030 en de gemeentelijke energiestrategie.",
  "scope": "Klimaat, energie en duurzaamheid in de gemeente Utrecht",
  "memberCount": 15,
  "termStart": "2025-03-15",
  "termEnd": "2026-03-15",
  "statusLifecycle": "active",
  "createdBy": "griffier@utrecht.nl"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "CitizenPanel",
    "slug": "citizen-panel-jeugdpanel-rotterdam"
  },
  "name": "Jeugdpanel Rotterdam",
  "description": "Vertegenwoordiging van jongeren (12–25 jaar) voor Rotterdams jeugdbeleid, onderwijsvoorzieningen en recreatieve infrastructuur.",
  "scope": "Jeugdbeleid, onderwijs, sport en recreatie",
  "memberCount": 8,
  "termStart": "2024-09-01",
  "termEnd": "2026-09-01",
  "statusLifecycle": "active",
  "createdBy": "griffier@rotterdam.nl"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "CitizenPanel",
    "slug": "citizen-panel-ondernemersraad-eindhoven"
  },
  "name": "Ondernemersraad Eindhoven",
  "description": "MKB-adviesraad voor economisch beleid in Eindhoven. Geeft input op bestemmingsplannen, horecabeleid en vestigingsbeleid.",
  "scope": "Economisch beleid, bestemmingsplannen en horeca",
  "memberCount": 10,
  "termStart": "2025-06-01",
  "termEnd": "2027-06-01",
  "statusLifecycle": "active",
  "createdBy": "griffier@eindhoven.nl"
}
```

---

### ParticipatoryBudget — 3 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "ParticipatoryBudget",
    "slug": "partbudget-wijkbudget-centrum-amsterdam-2026"
  },
  "name": "Wijkbudget Centrum 2026",
  "description": "Jaarlijks bewonersbudget voor de Amsterdamse binnenstad. Bewoners dienen projectvoorstellen in en stemmen op hun favorieten.",
  "totalAmount": 150000.00,
  "currency": "EUR",
  "submissionDeadline": "2026-02-28T23:59:00+01:00",
  "votingDeadline": "2026-03-31T23:59:00+02:00",
  "status": "voting",
  "resultsPublished": false
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "ParticipatoryBudget",
    "slug": "partbudget-dorpsbudget-maassluis-2025"
  },
  "name": "Dorpsbudget Maassluis 2025",
  "description": "Participatief budget voor kleine verbeteringen in de leefomgeving van Maassluis. Drie projecten uitgevoerd.",
  "totalAmount": 50000.00,
  "currency": "EUR",
  "submissionDeadline": "2025-11-30T23:59:00+01:00",
  "votingDeadline": "2025-12-31T23:59:00+01:00",
  "status": "closed",
  "resultsPublished": true
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "ParticipatoryBudget",
    "slug": "partbudget-buurtfonds-eindhoven-noord-2026"
  },
  "name": "Buurtfonds Eindhoven Noord",
  "description": "Bewonersbudget voor de wijk Eindhoven Noord, gericht op groen, sociale ontmoeting en verkeersveiligheid.",
  "totalAmount": 75000.00,
  "currency": "EUR",
  "submissionDeadline": "2026-04-30T23:59:00+02:00",
  "votingDeadline": "2026-05-31T23:59:00+02:00",
  "status": "submission",
  "resultsPublished": false
}
```

---

### BudgetProposal — 4 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "BudgetProposal",
    "slug": "budgetproposal-speeltuin-waterlooplein"
  },
  "title": "Renovatie Speeltuin Waterlooplein",
  "description": "Vervanging van verouderd speeltoestel aan het Waterlooplein door een inclusieve speelplek voor kinderen van 2–14 jaar, conform NEN 1176.",
  "requestedAmount": 28500.00,
  "submitter": "Oudergroep Waterlooplein",
  "category": "Speelvoorzieningen",
  "status": "approved",
  "votesFor": 234,
  "votesAgainst": 18
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "BudgetProposal",
    "slug": "budgetproposal-deelauto-laadpalen-centrum"
  },
  "title": "Elektrische deelauto's en laadpalen Grachtengordel",
  "description": "Plaatsing van 5 elektrische deelauto's met laadpalen aan de Keizersgracht en Herengracht, beheerd door een bewonerscoöperatie.",
  "requestedAmount": 62000.00,
  "submitter": "Bewonersvereniging Grachtengordel",
  "category": "Mobiliteit en duurzaamheid",
  "status": "voting",
  "votesFor": 178,
  "votesAgainst": 42
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "BudgetProposal",
    "slug": "budgetproposal-buurtmoestuin-lombok-utrecht"
  },
  "title": "Buurtmoestuin Lombok Utrecht",
  "description": "Aanleg van een gedeelde moestuin van 400 m² in de wijk Lombok, inclusief werktuigenschuur en regenwateropvang.",
  "requestedAmount": 15800.00,
  "submitter": "Groene Buren Utrecht",
  "category": "Groen en duurzaamheid",
  "status": "approved",
  "votesFor": 312,
  "votesAgainst": 27
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "BudgetProposal",
    "slug": "budgetproposal-bankjes-stationsplein-maassluis"
  },
  "title": "Nieuwe bankjes en beplanting Stationsplein Maassluis",
  "description": "Plaatsing van 12 zitbanken en seizoensbeplanting op het Stationsplein (3144 AA Maassluis), ter bevordering van sociale verblijfskwaliteit.",
  "requestedAmount": 9200.00,
  "submitter": "J. van der Berg",
  "category": "Openbare ruimte",
  "status": "closed",
  "votesFor": 156,
  "votesAgainst": 11
}
```

---

### PublicConsultation — 3 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "PublicConsultation",
    "slug": "consultation-structuurvisie-woonbeleid-amsterdam-2026"
  },
  "title": "Inspraak Structuurvisie Woonbeleid Amsterdam 2026–2031",
  "description": "De gemeente Amsterdam vraagt inspraak op de concept Structuurvisie Woonbeleid 2026–2031. Geef uw reactie op de woningbouwopgave, betaalbare huur en verduurzaming van de bestaande voorraad.",
  "relatedDecision": "decision-woonbeleid-kaders-2026",
  "submissionDeadline": "2026-02-15T23:59:00+01:00",
  "feedbackRequired": false,
  "status": "closed",
  "submissionCount": 183
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "PublicConsultation",
    "slug": "consultation-omgevingsplan-rijnenburg-utrecht"
  },
  "title": "Inspraak Omgevingsplan Rijnenburg Utrecht",
  "description": "Utrecht nodigt bewoners en ondernemers uit te reageren op het ontwerp-omgevingsplan voor de wijk Rijnenburg. Onderwerpen: woningbouw, mobiliteit en groenstructuur.",
  "relatedDecision": null,
  "submissionDeadline": "2026-05-01T23:59:00+02:00",
  "feedbackRequired": true,
  "status": "open",
  "submissionCount": 47
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "PublicConsultation",
    "slug": "consultation-evenementenverordening-rotterdam-2026"
  },
  "title": "Inspraak Verordening Evenementen Rotterdam 2026",
  "description": "Rotterdam herziet de evenementenverordening. Reageer op maximale geluidsbelasting, curfewregels en procedures voor vergunningverlening.",
  "relatedDecision": null,
  "submissionDeadline": "2026-03-31T23:59:00+02:00",
  "feedbackRequired": false,
  "status": "open",
  "submissionCount": 29
}
```

---

### Deliberation — 3 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Deliberation",
    "slug": "deliberation-herinrichting-museumplein"
  },
  "title": "Discussie: Herinrichting Museumplein Amsterdam",
  "description": "Burgeroverleg over de plannen voor herinrichting van het Museumplein. Prioriteiten: meer groen, betere fietspaden, of uitbreiding terrassen?",
  "relatedMotion": "motion-museumplein-renovatie",
  "discussionStatus": "open",
  "moderator": "griffier@amsterdam.nl",
  "postsCount": 34
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Deliberation",
    "slug": "deliberation-co2-neutraliteit-2030-utrecht"
  },
  "title": "Forum: CO2-neutraliteit 2030 Utrecht",
  "description": "Discussieforum over de gemeentelijke klimaatstrategie richting CO2-neutraliteit in 2030. Ideeën, zorgen en concrete suggesties welkom.",
  "relatedMotion": "motion-klimaatakkoord-utrecht",
  "discussionStatus": "open",
  "moderator": "griffier@utrecht.nl",
  "postsCount": 67
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Deliberation",
    "slug": "deliberation-sporthal-eindhoven-noord"
  },
  "title": "Participatie: Nieuwe sporthal Eindhoven Noord",
  "description": "Meedenken over de locatie, inrichting en beheer van een nieuwe sporthal in de wijk Eindhoven Noord (5625 AA). Reactietermijn gesloten.",
  "relatedMotion": null,
  "discussionStatus": "closed",
  "moderator": "griffier@eindhoven.nl",
  "postsCount": 22
}
```

---

### Notification — 3 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Notification",
    "slug": "notification-vote-opened-windmolens-jansen"
  },
  "recipientId": "burger-marie-jansen",
  "type": "vote_opened",
  "subject": "Stemming gestart: Windmolens Haarlemmermeer",
  "content": "De burgerraadpleging over de motie 'Windmolens Haarlemmermeer' is geopend. U kunt uw stem uitbrengen via het burgerdashboard.",
  "channel": "email",
  "status": "delivered",
  "sentAt": "2026-03-10T09:00:00+01:00",
  "readAt": "2026-03-10T11:23:00+01:00"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Notification",
    "slug": "notification-panel-invitation-klimaatraad-bakker"
  },
  "recipientId": "burger-piet-bakker",
  "type": "panel_invitation",
  "subject": "Uitnodiging: Word lid van de Klimaatraad Utrecht",
  "content": "U bent uitgenodigd deel te nemen aan de Klimaatraad Utrecht (2025–2026). Reageer voor 1 februari 2025 via uw burgerdashboard.",
  "channel": "email",
  "status": "delivered",
  "sentAt": "2025-01-15T08:00:00+01:00",
  "readAt": null
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Notification",
    "slug": "notification-budget-open-wijkbudget-centrum-vries"
  },
  "recipientId": "burger-jan-de-vries",
  "type": "budget_submission_open",
  "subject": "Wijkbudget Centrum 2026 – projectvoorstellen indienen geopend",
  "content": "Vanaf vandaag kunt u projectvoorstellen indienen voor het Wijkbudget Centrum 2026 (€150.000). Indienen kan tot en met 28 februari 2026.",
  "channel": "inapp",
  "status": "read",
  "sentAt": "2026-01-05T09:00:00+01:00",
  "readAt": "2026-01-05T14:12:00+01:00"
}
```

---

## Reuse Analysis

Per company ADR-012 (deduplication check):

| Existing Service / Component | Reuse in p3-citizen-participation |
|---|---|
| `ObjectService::getObjects()` | All citizen API controllers fetch CitizenVote, CitizenPanel, ParticipatoryBudget, etc. |
| `ObjectService::saveObject()` | Casting citizen votes, submitting proposals, posting deliberation content |
| `ObjectService::searchObjects()` | Searching published decisions in TransparencyController; citizen panel browsing |
| `ConfigurationService::importFromApp()` | Repair step for new schema and seed data import; version-gated re-import |
| `CalDavService` (ADR-002) | Reading Meeting VEVENT data (DTSTART, SUMMARY, LOCATION) for public meeting calendar |
| Existing ORI endpoint layer (ADR-003) | Extended with `/api/ori/v1/decisions`; no rebuild of existing ORI endpoints |
| `@conduction/nextcloud-vue` CnDataTable, CnDetailPage, CnDetailCard | Reused for citizen portal list/detail views |
| `NcDialog`, `NcButton`, `NcEmptyState` (`@nextcloud/vue`) | Reused for voting confirmation dialogs and empty state pages |
| `IMailer` (Nextcloud OCP) | Used by NotificationService for email delivery |
| `IUserSession` (Nextcloud OCP) | Authentication check on mutation endpoints |

**No duplicate services found.** No existing CitizenVoteService, CitizenPanelService, or ParticipantoryBudgetService exists in OpenRegister or Decidesk. New services are net-new implementations required by this change.
