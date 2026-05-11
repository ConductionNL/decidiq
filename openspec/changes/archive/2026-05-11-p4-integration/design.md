# Design: Integration

## Context

Decidesk manages mission-critical governance workflows (meetings, decisions, voting) but currently operates as a closed system. Data created in Decidesk cannot be consumed by external systems without manual export. This creates silos that prevent governance bodies from integrating with their broader IT ecosystems — municipal calendars, workflow automation tools, case management systems, and citizen-facing portals.

The integration change establishes Decidesk as a governance data hub by:

1. Exposing a public REST API (Dutch REST-API Design Rules + ORI API 1.4 compatibility)
2. Enabling bidirectional CalDAV synchronization for meetings and action items
3. Linking governance documents to Nextcloud Files without duplicating data
4. Launching digital meeting rooms via Nextcloud Talk
5. Dispatching governance events as CloudEvents webhooks (n8n + OpenConnector)
6. Supporting OAuth 2.0 for external participant-facing applications
7. Enabling reverse proxy deployment in complex municipal network topologies

**Architecture constraints:**
- Thin client: Decidesk owns no database tables; all data flows through OpenRegister `ObjectService` (ADR-001)
- No custom auth: Nextcloud's built-in session/OAuth2 app must be used, not a custom auth stack (ADR-005-security)
- Platform services: Nextcloud ships `sabre/dav`, Files API, Talk OCS API, and the `oauth2` app — reuse these rather than rebuild
- Standards: Dutch REST-API Design Rules, ORI API 1.4, CalDAV RFC 4791, CloudEvents RFC 9547, WCAG 2.1 AA

**Current state (p1–p3 complete):**
- All 23 entities defined in ADR-000 are implemented with full CRUD via OpenRegister
- No public API endpoints; no calendar sync; no webhook/event system beyond OpenRegister's internal audit trail
- Governance body, meeting, motion, voting, minutes, document management all functional

**Stakeholders:**
- Integration Developer — consumes REST API, builds n8n and ORI connectors
- IAM / System Administrator — configures OAuth apps, webhooks, reverse proxy
- API Integration Specialist — validates ORI API output for public transparency portals
- Governance body secretary — benefits from calendar sync and file attachment UX

---

## Goals / Non-Goals

**Goals:**
- Publish a complete versioned REST API (`/api/v1/`) for all Decidesk governance entities with authentication, pagination (`_page`, `_limit`), filtering, and standardized error envelopes
- Implement ORI API 1.4 compatible endpoints (`/api/ori/v1/`) for organization, person, event, motion, vote, and minutes data — required for Dutch municipal transparency obligations
- Bidirectional iCalendar sync via Nextcloud CalDAV backend: VEVENT for meetings, VTODO for action items
- Nextcloud Files file picker integration — link existing Files nodes to Meeting/Motion/Minutes objects via OpenRegister relations
- Nextcloud Talk integration — create a Talk room from the meeting detail UI; store room token on Meeting object
- CloudEvents-formatted webhook dispatch for governance domain events (meeting lifecycle, motion adopted, voting closed) — subscribable by n8n and OpenConnector endpoints
- OAuth 2.0 application registration UI and scope definitions (`meetings:read`, `motions:read`, `votes:read`, `governance-bodies:read`) via Nextcloud's built-in `oauth2` app
- Reverse proxy base URL override via Nextcloud `overwrite.*` config keys, with admin health check endpoint

**Non-Goals:**
- Custom identity provider implementation (SAML/OIDC is a Nextcloud-level concern)
- Email-to-decision linking via `_mail` metadata (scoped to p1-schemas-and-data-model)
- Building a document storage system (Nextcloud Files handles storage; Decidesk only links)
- Real-time push notifications beyond HTTP webhooks (SSE, WebSocket, long-polling)
- OAuth write scopes (`meetings:write`) — deferred to a future phase; read-only initially
- Multi-tenant OAuth isolation beyond what Nextcloud's `oauth2` app provides natively

---

## Decisions

### Decision 1: REST API — Thin Controller over ObjectService

**Chosen:** Create `ApiController` classes that wrap `ObjectService::findAll()` / `findObject()` with versioned, public-facing JSON response serialization. No separate ORM or query layer. URL pattern: `/index.php/apps/decidesk/api/v1/{resource}` following ADR-002-api.

**Why:** Decidesk already delegates all data access to OpenRegister. A thin serialization + authentication layer is all that is needed. Introducing a custom query builder would duplicate ObjectService and violate ADR-001.

**Alternatives considered:**
- Expose OpenRegister's own API directly → Rejected: OpenRegister's internal API returns raw objects without domain-specific field mapping or stable public contracts; ORI format requires specific JSON-LD serialization.
- Use OpenRegister's GraphQL controller → Rejected: ORI API 1.4 specifies REST/JSON-LD; mixing GraphQL adds operational complexity for API consumers who expect REST.

---

### Decision 2: iCalendar Sync — Wrap Nextcloud CalDavBackend

**Chosen:** `CalDavService` uses Nextcloud's built-in `sabre/dav` via `\OCA\DAV\CalDAV\CalDavBackend`. One Nextcloud Calendar per GovernanceBody (e.g., "Gemeenteraad Amsterdam — Decidesk"). Meetings → VEVENT; ActionItems → VTODO. Extended properties carry Decidesk metadata (`X-DECIDESK-LIFECYCLE`, `X-DECIDESK-MEETING-TYPE`, `X-DECIDESK-BODY-UID`, `X-DECIDESK-MOTION-UID`). Sync triggered on Meeting lifecycle state change events.

**Why:** `sabre/dav` ships with Nextcloud core and is battle-tested with all major calendar clients. Bidirectional sync requires write access — CalDAV provides this natively. CalDAV is the single source of distribution; OpenRegister remains the single source of truth.

**Alternatives considered:**
- Store meetings natively in CalDAV as primary storage → Rejected: violates ADR-001 (all domain data in OpenRegister). CalDAV is a sync target, not primary store.
- Scheduled-only sync (background job, no real-time) → Considered for MVP; rejected in favour of event-triggered sync for governance responsiveness — a meeting rescheduled in Decidesk must appear in the calendar within seconds, not minutes.
- Custom iCal file generator → Rejected: `sabre/dav` already handles RFC 5545 compliance, timezone normalization, and client compatibility edge cases.

---

### Decision 3: Nextcloud Files Integration — OpenRegister Relations + NodeDeletedEvent Listener

**Chosen:** Store file links as OpenRegister relations on Meeting/Motion/Minutes objects (relation type: `document`, target: Nextcloud Files node ID). `FilesIntegrationService` provides a file picker modal and registers a listener on `OCP\Files\Events\Node\NodeDeletedEvent` to prune stale relations when files are deleted.

**Why:** OpenRegister's relation system handles cross-object references with full audit trail. Using node IDs (not paths) makes links survive file renames or moves. No custom storage table needed.

**Alternatives considered:**
- Store file paths directly on governance object schema field → Rejected: brittle; file moves/renames break links. Node IDs are stable.
- Use OpenRegister's native file attachment (`CnFilesTab`) → This mechanism uploads files directly to the object. Files integration links to existing files anywhere in the Files hierarchy. Both coexist and serve different needs.

---

### Decision 4: Nextcloud Talk Integration — OCS Internal API

**Chosen:** `TalkIntegrationService` calls Talk's OCS REST API (`POST /ocs/v2.php/apps/spreed/api/v4/room`) to create a named meeting room. Room token stored as a metadata field on the Meeting OpenRegister object. A "Launch video call" button generates the Talk deep-link URL (`/call/{token}`). Service performs a capability check at startup and gracefully disables the feature if Talk is not installed.

**Why:** Talk exposes a stable, documented OCS API. Storing the room token in OpenRegister metadata keeps it audit-trailed and accessible via the REST API. Deep-link approach is simpler and more reliable than iframe embedding.

**Alternatives considered:**
- Embed Talk via iframe → Rejected: CORS and Nextcloud CSP policies block cross-context iframe embedding; authentication context is shared only via deep-link redirect.
- Generate public share links for Talk rooms → Rejected: governance meetings require authenticated participation; public links bypass access control.

---

### Decision 5: Webhook Dispatch — Reuse OpenRegister WebhookService

**Chosen:** Register Decidesk-specific governance event types with OpenRegister's `WebhookService`. Events dispatched as CloudEvents (RFC 9547). n8n and OpenConnector endpoints registered as webhook subscriptions through the admin UI. No custom dispatcher built.

**Why:** OpenRegister `WebhookService` already provides CloudEvents format, HMAC signing, exponential backoff retry, subscription management, and delivery logs. Building a parallel system would violate ADR-012 (deduplication rule) and introduce maintenance overhead.

**Alternatives considered:**
- Symfony EventDispatcher → Rejected: internal PHP process only; no HTTP delivery to external n8n/OpenConnector endpoints.
- Direct HTTP POST in service layer → Rejected: no retry, no subscription management, no delivery audit trail.

---

### Decision 6: OAuth 2.0 — Nextcloud Built-in `oauth2` App

**Chosen:** External applications register via Nextcloud's built-in `oauth2` app. Decidesk adds OAuth scope definitions (`meetings:read`, `motions:read`, `votes:read`, `governance-bodies:read`) via `OCP\Security\Bruteforce\IThrottler` and scope registry. All token issuance, refresh, and revocation handled by the built-in OAuth2 server.

**Why:** Nextcloud's OAuth2 implementation is production-grade (PKCE, refresh tokens, configurable TTL, revocation). Rolling a custom server introduces unacceptable security risk per ADR-005. Scope definitions are the only Decidesk-specific concern.

**Alternatives considered:**
- `php-league/oauth2-server` library → Rejected: duplicates Nextcloud's built-in capability; adds a new external dependency for no gain.
- API key authentication → Rejected: stakeholder requirement specifies OAuth 2.0 for participant-facing app compatibility with standard authorization frameworks.

---

### Decision 7: ORI API — Separate Serialization Layer

**Chosen:** `OriController` classes at `/api/ori/v1/{resource}` read OpenRegister objects via ObjectService and serialize them per ORI API 1.4 JSON-LD specification. Maintained separately from the general REST API to allow independent versioning as ORI evolves.

**Why:** ORI API 1.4 specifies field names (`@context`, `@type`, `identifier`, `name`), JSON-LD contexts, and response envelopes that differ structurally from Decidesk's internal REST API. Mixing them in one controller class creates tight coupling between two independently versioned API contracts.

**Alternatives considered:**
- Content negotiation on same endpoints → Rejected: ORI uses different URL conventions (`/ori/v1/`), different response envelopes, and different pagination parameters.
- External adapter/proxy → Rejected: adds operational complexity and moves error handling outside the app boundary.

---

## Risks / Trade-offs

- **[CalDAV sync conflicts]** Calendar event edited externally (e.g., time changed in iOS Calendar) simultaneously with a Decidesk UI edit → **Mitigation:** Last-write-wins for scheduling fields (DTSTART, DTEND, LOCATION, SUMMARY). Governance lifecycle fields (`X-DECIDESK-LIFECYCLE`, `X-DECIDESK-*`) are write-protected for CalDAV clients — ignored on inbound sync. All external edits logged in OpenRegister audit trail.

- **[Talk app not installed]** Municipality may not have Nextcloud Talk activated → **Mitigation:** `TalkIntegrationService::isAvailable()` checks `IAppManager` at runtime. "Launch video call" button hidden if Talk unavailable; admin sees a warning in integration settings.

- **[OAuth scope creep]** Too many fine-grained scopes confuse external developers → **Mitigation:** Start with 4 coarse read-only scopes. Write scopes deferred to a future phase. Scope documentation published in `docs/api.md`.

- **[ORI API version drift]** VNG ORI API 1.4 may introduce breaking field changes → **Mitigation:** `/api/ori/v1/` prefix allows independent versioning. Monitor the VNG API standards repository; ORI changelog maintained in `docs/ori-changelog.md`.

- **[Webhook delivery failures]** Network errors to n8n or OpenConnector endpoints cause lost governance events → **Mitigation:** OpenRegister `WebhookService` retries with exponential backoff (3 attempts). Admin UI shows delivery status and allows manual re-trigger. Dead-letter queue logged in Nextcloud background job system.

- **[Reverse proxy misconfiguration]** Incorrect `overwrite.cli.url` breaks all generated API URLs → **Mitigation:** `GET /api/v1/health` endpoint returns the effective base URL. Admin setup guide includes URL verification step.

- **[sabre/dav API churn]** `CalDavBackend` interface may change between Nextcloud major versions → **Mitigation:** `CalDavService` wraps the backend behind a stable internal interface. CI tests run against the minimum supported Nextcloud version (NC 28+). Version compatibility matrix documented in `docs/compatibility.md`.

- **[OpenRegister WebhookService coupling]** If OpenRegister's WebhookService API changes, all governance event dispatch breaks → **Mitigation:** Dependency pinned to minimum OpenRegister version in `appinfo/info.xml`. Integration tests verify event delivery on every CI run.

---

## Migration Plan

This change adds new integration layers over existing p1–p3 data. **No schema migrations are required** — no new OpenRegister schemas introduced; all new data (Talk room token, CalDAV calendar ID) stored as OpenRegister metadata fields on existing objects.

**Deployment sequence:**

1. Deploy Decidesk update — new controllers, services, and admin UI included
2. Repair step runs `CalDavService::ensureCalendarsExist()` — creates per-GovernanceBody calendar in Nextcloud Calendar (idempotent; skips if already exists)
3. Background job `CalDavSyncJob` runs once on deploy — backfills VEVENT/VTODO for all existing Meeting and ActionItem objects
4. Admin configures reverse proxy base URL if applicable: **Settings → Decidesk → Integration → Base URL**
5. Admin registers n8n webhook URLs via **Settings → Decidesk → Webhooks**
6. Admin registers OpenConnector endpoints via **Settings → Decidesk → Webhooks → OpenConnector**
7. External application developers register OAuth clients via Nextcloud **Settings → Security → OAuth 2.0 clients**

**Rollback strategy:**
- API controllers are read-only pass-throughs; removal causes no data loss
- CalDAV calendars scoped to the `decidesk-{body-uuid}` principal; removing the app removes the calendars cleanly
- Talk room tokens stored as OpenRegister metadata are inert if Talk is disabled
- Webhook subscriptions stored in OpenRegister — persist across rollback; admin can delete manually

---

## Open Questions

1. **ORI API public vs authenticated access:** Should ORI endpoints (`/api/ori/v1/`) be publicly accessible (no Nextcloud session required) or gated behind OAuth? VNG specification allows both. Proposal: default authenticated, with a per-GovernanceBody opt-in to public mode for transparency portals.

2. **CalDAV write-back scope:** Which VEVENT fields trigger a Meeting update in Decidesk when changed externally? Current proposal: only `DTSTART`, `DTEND`, `LOCATION`, `SUMMARY`. Governance fields (`X-DECIDESK-LIFECYCLE`) are read-only from CalDAV side. Needs confirmation with UX team.

3. **n8n vs OpenConnector consolidation:** Both receive the same CloudEvents. Should the admin UI have separate configuration panels or one unified webhook subscription manager? A single subscription UI with a "type" selector may reduce complexity.

4. **OAuth write scopes timing:** Should `meetings:write` and `motions:write` scopes be included in this phase to unblock external app developers, or deferred to p5 to avoid premature API surface commitments?

---

## Reuse Analysis

Per ADR-012 (deduplication), the following platform services are directly leveraged:

| Capability | Service reused | Source |
|---|---|---|
| Data access (all entities) | `ObjectService::findAll()`, `findObject()` | OpenRegister |
| File management (governance docs) | `FileService`, `CnFilesTab` | OpenRegister / nextcloud-vue |
| Webhook dispatch + CloudEvents | `WebhookService` | OpenRegister |
| OAuth token management | Nextcloud `oauth2` app | Nextcloud core |
| CalDAV operations | `sabre/dav` via `CalDavBackend` | Nextcloud core |
| Audit trail for API access | `AuditTrailService` (automatic) | OpenRegister |
| RBAC for API endpoints | `AuthorizationService`, `PropertyRbacHandler` | OpenRegister |
| Background job scheduling | Nextcloud `IJobList` | Nextcloud core |
| Notifications to participants | `NotificationService` | OpenRegister |

**No overlap found** with existing Decidesk capabilities from p1–p3. `ApiController`, `OriController`, `CalDavService`, `TalkIntegrationService`, `FilesIntegrationService`, and `OAuthApplicationService` are new domain-specific layers with no functional duplication of `ObjectService` or any existing service.

---

## Seed Data

This change introduces no new OpenRegister schemas. Seed data for entities used by integration features (`Meeting`, `ActionItem`, `GovernanceBody`, `Person`) MUST be present in `lib/Settings/decidesk_register.json` from prior phases (p1–p3). The following examples define the realistic Dutch seed objects required:

### GovernanceBody — 2 seed objects

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "GovernanceBody",
    "slug": "gemeenteraad-amsterdam"
  },
  "name": "Gemeenteraad Amsterdam",
  "bodyType": "gemeenteraad",
  "domain": "legislative",
  "workflowTemplate": "dutch-municipal-council",
  "quorumRule": "majority",
  "votingDefault": "show-of-hands",
  "termStart": "2022-03-30",
  "termEnd": "2026-03-29"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "GovernanceBody",
    "slug": "commissie-ruimtelijke-ordening-amsterdam"
  },
  "name": "Commissie Ruimtelijke Ordening Amsterdam",
  "bodyType": "raadscommissie",
  "domain": "legislative",
  "workflowTemplate": "dutch-municipal-committee",
  "quorumRule": "majority",
  "votingDefault": "unanimous-unless-objection",
  "termStart": "2022-03-30",
  "termEnd": "2026-03-29"
}
```

### Meeting — 3 seed objects (used for CalDAV VEVENT sync + REST API)

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Meeting",
    "slug": "raadsvergadering-amsterdam-2025-06-04"
  },
  "title": "Raadsvergadering Gemeente Amsterdam — 4 juni 2025",
  "meetingType": "raadsvergadering",
  "scheduledDate": "2025-06-04T19:30:00+02:00",
  "endDate": "2025-06-04T23:00:00+02:00",
  "location": "Stadhuis Amsterdam, Amstel 1, 1011 PN Amsterdam",
  "meetingMode": "physical",
  "lifecycle": "scheduled",
  "quorumRequired": 23,
  "series": "raadsvergadering-2025"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Meeting",
    "slug": "commissievergadering-ro-2025-05-21"
  },
  "title": "Vergadering Commissie Ruimtelijke Ordening — 21 mei 2025",
  "meetingType": "commissievergadering",
  "scheduledDate": "2025-05-21T10:00:00+02:00",
  "endDate": "2025-05-21T12:30:00+02:00",
  "location": "Commissiekamer 3, Stadhuis Amsterdam, Amstel 1, 1011 PN Amsterdam",
  "meetingMode": "hybrid",
  "lifecycle": "draft",
  "quorumRequired": 5,
  "series": "commissie-ro-2025"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Meeting",
    "slug": "raadsvergadering-amsterdam-2025-04-09"
  },
  "title": "Raadsvergadering Gemeente Amsterdam — 9 april 2025",
  "meetingType": "raadsvergadering",
  "scheduledDate": "2025-04-09T19:30:00+02:00",
  "endDate": "2025-04-09T22:45:00+02:00",
  "location": "Stadhuis Amsterdam, Amstel 1, 1011 PN Amsterdam",
  "meetingMode": "physical",
  "lifecycle": "closed",
  "quorumRequired": 23,
  "series": "raadsvergadering-2025"
}
```

### ActionItem — 3 seed objects (used for CalDAV VTODO sync)

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "ActionItem",
    "slug": "actie-subsidieaanvraag-woningbouw-noord-2025-06"
  },
  "title": "Subsidieaanvraag woningbouwproject Amsterdam-Noord opstellen",
  "description": "College verzoekt wethouder Wonen de Rijkssubsidieaanvraag voor het woningbouwproject Amsterdam-Noord voor te bereiden en te verzenden vóór 1 juli 2025.",
  "assignee": "Wethouder Wonen",
  "dueDate": "2025-07-01",
  "taskStatus": "open"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "ActionItem",
    "slug": "actie-terugkoppeling-verkeersplan-zuidoost-2025-06"
  },
  "title": "Terugkoppeling verkeersplan Zuidoost",
  "description": "Portefeuillehouder Mobiliteit rapporteert over voortgang verkeersplan Amsterdam Zuidoost in de commissievergadering van juni 2025.",
  "assignee": "Wethouder Mobiliteit",
  "dueDate": "2025-06-18",
  "taskStatus": "in-progress"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "ActionItem",
    "slug": "actie-duurzaamheidsrapportage-2025-q2"
  },
  "title": "Duurzaamheidsrapportage Q2 2025 aanleveren",
  "description": "Gemeente Amsterdam levert de kwartaalrapportage duurzaamheidsdoelstellingen aan voor behandeling in de raadsvergadering van september 2025.",
  "assignee": "Wethouder Duurzaamheid",
  "dueDate": "2025-08-15",
  "taskStatus": "open"
}
```

### Person — 3 seed objects (used for ORI API + OAuth ATTENDEE mapping)

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Person",
    "slug": "person-femke-halsema"
  },
  "name": "F.Z. Halsema",
  "familyName": "Halsema",
  "givenName": "Femke",
  "gender": "female",
  "email": "burgemeester@amsterdam.nl",
  "biography": "Burgemeester van Amsterdam (2018–heden), voorzitter gemeenteraad"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Person",
    "slug": "person-jaap-de-vries"
  },
  "name": "J.H. de Vries",
  "familyName": "de Vries",
  "givenName": "Jaap",
  "gender": "male",
  "email": "j.devries@amsterdam.nl",
  "biography": "Raadslid D66, woordvoerder Ruimtelijke Ordening en Wonen"
}
```

```json
{
  "@self": {
    "register": "decidesk",
    "schema": "Person",
    "slug": "person-nora-el-moumni"
  },
  "name": "N. El Moumni",
  "familyName": "El Moumni",
  "givenName": "Nora",
  "gender": "female",
  "email": "n.elmoumni@amsterdam.nl",
  "biography": "Raadsgriffier Gemeente Amsterdam, verantwoordelijk voor vergaderadministratie en besluitvorming"
}
```
