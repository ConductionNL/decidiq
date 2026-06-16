# p4-integration Specification

## Purpose
TBD - created by archiving change 2026-05-11-p4-integration. Update Purpose after archive.

## Requirements

### Requirement: REQ-API-001 — Public REST API foundation
The system SHALL expose a versioned public REST API at `/index.php/apps/decidesk/api/v1/` following the Dutch REST-API Design Rules. All endpoints SHALL support pagination via `_page` and `_limit` query parameters. Responses SHALL include `total`, `page`, `pages`, and `results` fields. Authentication SHALL use Nextcloud session tokens. Public endpoints SHALL be annotated `#[PublicPage]` + `#[NoCSRFRequired]` and SHALL register a CORS `OPTIONS` route.

**OCP interfaces used:** `OCP\AppFramework\Http\JSONResponse`, `OCP\IRequest`, `OCP\AppFramework\Controller`

#### Scenario: Paginated list response
- **GIVEN** an authenticated request to any list endpoint
- **WHEN** `?_page=2&_limit=10` is provided
- **THEN** the response SHALL include `{ "total": N, "page": 2, "pages": M, "results": [...] }` with exactly 10 items (or fewer on the last page)

#### Scenario: Unauthenticated request rejected
- **WHEN** a request is made to a protected endpoint without a valid Nextcloud session token
- **THEN** the response SHALL be HTTP 401 with `{ "message": "Unauthorized" }`

#### Scenario: CORS preflight handled
- **WHEN** an HTTP OPTIONS request is sent to any `/api/v1/` endpoint
- **THEN** the response SHALL include `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods`, and `Access-Control-Allow-Headers` headers with HTTP 200

#### Scenario: Invalid pagination parameter
- **WHEN** `_limit` exceeds 100 or is non-numeric
- **THEN** the response SHALL be HTTP 400 with `{ "message": "Invalid pagination parameters" }`

---

### Requirement: REQ-API-002 — Governance entity read endpoints
The system SHALL expose read-only GET endpoints for all primary governance entities. Entities SHALL be serialized using their Schema.org type annotations. Each endpoint SHALL support field-level filtering via query parameters matching entity property names.

**Entities and Schema.org types:**
- `GET /api/v1/governance-bodies` → `org:Organization` (filter: `domain`, `bodyType`)
- `GET /api/v1/governance-bodies/{id}` → single `org:Organization`
- `GET /api/v1/persons` → `foaf:Person` (filter: `email`)
- `GET /api/v1/persons/{id}` → single `foaf:Person` with `ContactDetail` relations
- `GET /api/v1/memberships` → `org:Membership` (filter: `role`, `party`)
- `GET /api/v1/meetings` → `meeting:Meeting` (filter: `lifecycle`, `meetingType`, `scheduledDate[gte]`, `scheduledDate[lte]`)
- `GET /api/v1/meetings/{id}` → single `meeting:Meeting`
- `GET /api/v1/motions` → `opengov:Motion` (filter: `lifecycle`, `motionType`)
- `GET /api/v1/voting-rounds` → `opengov:VoteEvent` (filter: `result`)
- `GET /api/v1/votes` → `opengov:Vote` (filter: `votingRoundId`)
- `GET /api/v1/agenda-items` → `meeting:AgendaItem` (filter: `itemType`)
- `GET /api/v1/minutes` → `meeting:Report` (filter: `lifecycle`)

#### Scenario: Filter meetings by lifecycle
- **WHEN** `GET /api/v1/meetings?lifecycle=scheduled` is requested with a valid session
- **THEN** the response SHALL contain only Meeting objects where `lifecycle = "scheduled"`

#### Scenario: Retrieve single governance body
- **WHEN** `GET /api/v1/governance-bodies/{id}` is requested with a valid UUID
- **THEN** the response SHALL return the GovernanceBody serialized as `org:Organization` with all properties defined in ADR-000

#### Scenario: Unknown resource returns 404
- **WHEN** `GET /api/v1/meetings/{nonexistent-uuid}` is requested
- **THEN** the response SHALL be HTTP 404 with `{ "message": "Not found" }`

#### Scenario: Date range filter on meetings
- **WHEN** `GET /api/v1/meetings?scheduledDate[gte]=2025-06-01&scheduledDate[lte]=2025-06-30` is requested
- **THEN** the response SHALL contain only meetings with `scheduledDate` within June 2025

---

### Requirement: REQ-API-003 — API error envelope
All API error responses SHALL use a consistent JSON envelope. HTTP 4xx responses SHALL include `{ "message": "<human-readable error>", "code": <HTTP status> }`. HTTP 5xx responses SHALL NOT include stack traces or internal exception details.

#### Scenario: Server error without stack trace
- **WHEN** an internal exception occurs during API request processing
- **THEN** the response SHALL be HTTP 500 with `{ "message": "Internal server error", "code": 500 }` and SHALL NOT expose PHP exception messages or stack traces

#### Scenario: Validation error with message
- **WHEN** a required filter parameter has an invalid format
- **THEN** the response SHALL be HTTP 400 with `{ "message": "Invalid value for parameter 'domain'", "code": 400 }`

---

### Requirement: REQ-API-004 — API health check
The system SHALL expose `GET /api/v1/health` as a public endpoint (no authentication required) returning the Nextcloud instance base URL, Decidesk app version, and OpenRegister connection status. This endpoint SHALL be used to verify reverse proxy base URL configuration.

#### Scenario: Health check response structure
- **WHEN** `GET /api/v1/health` is requested (no authentication)
- **THEN** the response SHALL be HTTP 200 with `{ "status": "ok", "baseUrl": "https://...", "version": "x.y.z", "openregister": "connected" }`

#### Scenario: Health check shows degraded state
- **WHEN** OpenRegister is unavailable
- **THEN** `GET /api/v1/health` SHALL return HTTP 200 with `{ "status": "degraded", "openregister": "unavailable" }`

---

### Capability: icalendar-sync

The system MUST provide the icalendar-sync capability as specified by the REQ-ICAL requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the icalendar-sync capability is exercised
- **THEN** the system MUST satisfy the REQ-ICAL requirements defined in this group

---

### Requirement: REQ-ICAL-001 — Per-governance-body CalDAV calendar
The system SHALL create one Nextcloud Calendar per GovernanceBody during the repair step, named `{GovernanceBody.name} — Decidesk`. Calendar creation SHALL be idempotent — re-running the repair step SHALL NOT create duplicate calendars. Calendars SHALL be scoped to the Nextcloud user account associated with the governance body's owning organization.

**OCP interfaces used:** `\OCA\DAV\CalDAV\CalDavBackend`, `OCP\BackgroundJob\IJobList`

#### Scenario: Calendar created on repair
- **WHEN** the Decidesk repair step runs after installation
- **THEN** one Nextcloud Calendar SHALL exist for each GovernanceBody with the name `{name} — Decidesk`

#### Scenario: Idempotent calendar creation
- **WHEN** the repair step runs a second time
- **THEN** no duplicate calendars SHALL be created

---

### Requirement: REQ-ICAL-002 — Meeting synchronization to VEVENT
The system SHALL synchronize each Meeting object to a CalDAV VEVENT. The VEVENT SHALL include all standard RFC 5545 fields and the following extended properties:

| iCal property | Source field | Notes |
|---|---|---|
| `SUMMARY` | `Meeting.title` | |
| `DTSTART` | `Meeting.scheduledDate` | With timezone |
| `DTEND` | `Meeting.endDate` | |
| `LOCATION` | `Meeting.location` | |
| `X-DECIDESK-LIFECYCLE` | `Meeting.lifecycle` | |
| `X-DECIDESK-MEETING-TYPE` | `Meeting.meetingType` | |
| `X-DECIDESK-MEETING-MODE` | `Meeting.meetingMode` | |
| `X-DECIDESK-BODY-UID` | GovernanceBody UUID | |
| `X-DECIDESK-QUORUM` | `Meeting.quorumRequired` | |
| `ATTENDEE` | Person/Membership | `CN=displayName;MAILTO=email` |
| `UID` | Meeting UUID | Stable identifier for sync |

VEVENT creation SHALL be triggered when a Meeting is created or its lifecycle changes. The `X-DECIDESK-*` properties SHALL be read-only — changes to these properties from CalDAV clients SHALL be ignored on inbound sync.

#### Scenario: Meeting creation creates VEVENT
- **WHEN** a new Meeting is created in Decidesk
- **THEN** a corresponding VEVENT SHALL appear in the GovernanceBody's Nextcloud Calendar within 5 seconds

#### Scenario: Meeting reschedule updates VEVENT
- **WHEN** `Meeting.scheduledDate` is updated
- **THEN** the VEVENT `DTSTART` SHALL be updated to match

#### Scenario: External VEVENT edit updates meeting scheduling fields
- **WHEN** an external CalDAV client updates `DTSTART`, `DTEND`, `LOCATION`, or `SUMMARY` on a Decidesk VEVENT
- **THEN** the corresponding Meeting object SHALL be updated with the new values
- **THEN** the change SHALL appear in the Meeting's OpenRegister audit trail

#### Scenario: External edit to X-DECIDESK-* fields is ignored
- **WHEN** an external CalDAV client attempts to modify `X-DECIDESK-LIFECYCLE`
- **THEN** the Meeting lifecycle SHALL NOT change

---

### Requirement: REQ-ICAL-003 — ActionItem synchronization to VTODO
The system SHALL synchronize each ActionItem object to a CalDAV VTODO. The VTODO SHALL include `SUMMARY` (ActionItem.title), `DESCRIPTION`, `DUE` (dueDate), `STATUS` (taskStatus mapped to RFC 5545 STATUS values), `ASSIGNEE` (X-DECIDESK-ASSIGNEE), and `UID` (ActionItem UUID). Status mapping: `open` → `NEEDS-ACTION`, `in-progress` → `IN-PROCESS`, `completed` → `COMPLETED`.

#### Scenario: ActionItem creates VTODO
- **WHEN** an ActionItem is created
- **THEN** a corresponding VTODO SHALL appear in the GovernanceBody's Nextcloud Calendar

#### Scenario: ActionItem completion syncs VTODO status
- **WHEN** `ActionItem.taskStatus` changes to `completed`
- **THEN** the VTODO `STATUS` SHALL be set to `COMPLETED` and `COMPLETED` timestamp SHALL be set

---

### Requirement: REQ-ICAL-004 — CalDAV backfill background job
The system SHALL provide a background job (`CalDavSyncJob`) that backfills VEVENT/VTODO entries for all existing Meeting and ActionItem objects that do not yet have a corresponding CalDAV entry. The job SHALL run once on initial deployment and SHALL be idempotent (matched by `UID` = object UUID).

#### Scenario: Backfill creates missing VEVENT entries
- **WHEN** `CalDavSyncJob` runs after deployment on an instance with existing Meeting objects
- **THEN** all Meetings without an existing VEVENT SHALL have one created
- **THEN** Meetings that already have a VEVENT SHALL NOT be duplicated

---

### Capability: nextcloud-files-integration

The system MUST provide the nextcloud-files-integration capability as specified by the REQ-FILES requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the nextcloud-files-integration capability is exercised
- **THEN** the system MUST satisfy the REQ-FILES requirements defined in this group

---

### Requirement: REQ-FILES-001 — Link Nextcloud Files to governance objects
The system SHALL allow users to link Nextcloud Files nodes (files or folders) to Meeting, Motion, and Minutes objects via a file picker modal. Links SHALL be stored as OpenRegister relations of type `document` with the Nextcloud Files node ID as the target reference. A governance object MAY have multiple linked files.

**OCP interfaces used:** `OCP\Files\IRootFolder`, `OCP\Files\Node`, `OCP\Files\Events\Node\NodeDeletedEvent`

#### Scenario: User links a file to a meeting
- **GIVEN** a Meeting detail page in Decidesk
- **WHEN** the user opens the "Add document" file picker and selects a file from Nextcloud Files
- **THEN** the file SHALL appear in the meeting's linked documents section
- **THEN** an OpenRegister relation of type `document` SHALL be created between the Meeting and the Nextcloud Files node

#### Scenario: Linked files displayed on detail page
- **WHEN** a user opens a Meeting detail page
- **THEN** all linked Nextcloud Files SHALL be listed with filename, file size, and a download link

#### Scenario: File picker respects Nextcloud permissions
- **WHEN** a user browses for files in the picker
- **THEN** only files the user has Nextcloud read permission to SHALL be selectable

---

### Requirement: REQ-FILES-002 — Automatic relation cleanup on file deletion
The system SHALL listen to `OCP\Files\Events\Node\NodeDeletedEvent` and remove all OpenRegister relations that reference the deleted Nextcloud Files node. This SHALL prevent dangling document references on governance objects.

#### Scenario: Deleted file link removed from meeting
- **WHEN** a Nextcloud file linked to a Meeting is deleted from Files
- **THEN** the corresponding relation SHALL be removed from the Meeting object
- **THEN** the file SHALL no longer appear in the Meeting's linked documents section

---

### Requirement: REQ-FILES-003 — File unlinking by user
The system SHALL allow users with edit permission on a governance object to manually remove a linked file from the document list without deleting the file from Nextcloud Files.

#### Scenario: User removes document link
- **WHEN** the user clicks "Remove" next to a linked file on a Meeting detail page
- **THEN** the OpenRegister relation SHALL be deleted
- **THEN** the file SHALL remain in Nextcloud Files unmodified

---

### Capability: nextcloud-talk-integration

The system MUST provide the nextcloud-talk-integration capability as specified by the REQ-TALK requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the nextcloud-talk-integration capability is exercised
- **THEN** the system MUST satisfy the REQ-TALK requirements defined in this group

---

### Requirement: REQ-TALK-001 — Launch video call from meeting UI
The system SHALL display a "Launch video call" button on Meeting detail pages where `Meeting.meetingMode = "digital"` or `"hybrid"`. Clicking the button SHALL create a Nextcloud Talk room scoped to the meeting and open the Talk deep-link URL in a new browser tab. The Talk room token SHALL be stored on the Meeting object as a metadata field for persistent reuse.

**OCP interfaces used:** `OCP\IAppManager` (capability check), Nextcloud Talk OCS API `POST /ocs/v2.php/apps/spreed/api/v4/room`

#### Scenario: Launch video call creates Talk room
- **GIVEN** a Meeting with `meetingMode = "digital"` and no existing Talk room
- **WHEN** the user clicks "Launch video call"
- **THEN** a Nextcloud Talk room SHALL be created named after the Meeting title
- **THEN** the room token SHALL be stored on the Meeting object
- **THEN** the user's browser SHALL open the Talk deep-link URL

#### Scenario: Subsequent launch reuses existing room
- **WHEN** the user clicks "Launch video call" on a Meeting that already has a Talk room token
- **THEN** no new Talk room SHALL be created
- **THEN** the browser SHALL open the existing Talk room URL

#### Scenario: Button hidden when Talk not installed
- **WHEN** Nextcloud Talk app is not installed or not enabled
- **THEN** the "Launch video call" button SHALL NOT be displayed
- **THEN** an administrator warning SHALL appear in Settings → Decidesk → Integration

---

### Requirement: REQ-TALK-002 — Talk room participant access
The system SHALL configure Talk room participants based on the meeting's ATTENDEEs (derived from Person/Membership relations). Talk room access SHALL be restricted to the listed participants when `Meeting.lifecycle` is `scheduled` or `opened`.

#### Scenario: Participants granted Talk room access
- **WHEN** a Talk room is created for a meeting with 5 linked Membership participants
- **THEN** the 5 participants SHALL be added as Talk room members
- **THEN** uninvited Nextcloud users SHALL NOT be able to join the room directly

---

### Requirement: REQ-TALK-003 — Talk room link in meeting notification
The system SHALL include the Talk room deep-link URL in meeting participation notifications sent to participants when a digital or hybrid meeting is scheduled.

#### Scenario: Talk link included in meeting notification
- **GIVEN** a digital Meeting with a Talk room token and linked participants
- **WHEN** the meeting invitation notification is sent
- **THEN** the notification SHALL contain the Talk deep-link URL

---

### Capability: n8n-webhook-events

The system MUST provide the n8n-webhook-events capability as specified by the REQ-N8N requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the n8n-webhook-events capability is exercised
- **THEN** the system MUST satisfy the REQ-N8N requirements defined in this group

---

### Requirement: REQ-N8N-001 — Governance event publication via CloudEvents
The system SHALL publish the following governance domain events using OpenRegister's `WebhookService` in CloudEvents RFC 9547 format. Each event SHALL include `source` (`/decidesk/{governanceBodyUuid}`), `type` (`nl.decidesk.{eventName}`), `specversion: "1.0"`, `id` (UUID), `time` (ISO 8601), and `data` containing the serialized entity.

**Governance events:**
- `nl.decidesk.meeting.scheduled` — Meeting created or `scheduledDate` changed
- `nl.decidesk.meeting.lifecycle-changed` — Meeting lifecycle state transition
- `nl.decidesk.motion.submitted` — Motion created
- `nl.decidesk.votinground.opened` — VotingRound `openedAt` set
- `nl.decidesk.votinground.closed` — VotingRound `closedAt` set
- `nl.decidesk.motion.adopted` — Decision outcome `adopted`
- `nl.decidesk.motion.rejected` — Decision outcome `rejected`
- `nl.decidesk.actionitem.created` — ActionItem created
- `nl.decidesk.actionitem.completed` — ActionItem `taskStatus` changed to `completed`

#### Scenario: Motion adopted event dispatched
- **WHEN** a Motion's lifecycle changes to `adopted`
- **THEN** a CloudEvent with `type = "nl.decidesk.motion.adopted"` SHALL be dispatched to all subscribed webhook endpoints
- **THEN** the event `data` SHALL include the Motion UUID, title, votingRound result, and adopting GovernanceBody UUID

#### Scenario: CloudEvents envelope structure
- **WHEN** any governance event is dispatched
- **THEN** the HTTP POST body SHALL include `specversion`, `id`, `source`, `type`, `time`, and `data` fields per RFC 9547

---

### Requirement: REQ-N8N-002 — n8n webhook endpoint configuration
The system SHALL provide an admin UI at Settings → Decidesk → Webhooks to configure n8n webhook trigger endpoints. Administrators SHALL be able to add, test, and remove n8n endpoint URLs. Each endpoint configuration SHALL specify: URL, event type filter (all events or specific types), and an optional HMAC signing secret.

#### Scenario: Admin adds n8n webhook endpoint
- **GIVEN** the Decidesk webhooks admin page
- **WHEN** the admin enters an n8n webhook URL and clicks "Save"
- **THEN** the endpoint SHALL be stored as a webhook subscription in OpenRegister
- **THEN** governance events SHALL be dispatched to the configured n8n URL

#### Scenario: Test delivery from admin UI
- **WHEN** the admin clicks "Test" on a configured n8n endpoint
- **THEN** a test CloudEvent SHALL be sent to the endpoint
- **THEN** the UI SHALL display "Delivery successful" (HTTP 2xx) or the error status code

#### Scenario: Failed delivery logged
- **WHEN** an n8n endpoint returns HTTP 5xx or is unreachable
- **THEN** the `WebhookService` SHALL retry with exponential backoff (3 attempts)
- **THEN** delivery failures SHALL be visible in the webhooks admin log

---

### Requirement: REQ-N8N-003 — Webhook HMAC signing
When an HMAC signing secret is configured for a webhook endpoint, the system SHALL include an `X-Decidesk-Signature-256` header with the HMAC-SHA256 signature of the request body. This allows the receiving system (n8n) to verify event authenticity.

#### Scenario: HMAC signature header present
- **WHEN** a governance event is dispatched to an endpoint with a configured HMAC secret
- **THEN** the HTTP request SHALL include `X-Decidesk-Signature-256: sha256={hex-digest}`

---

### Capability: openconnector-webhooks

The system MUST provide the openconnector-webhooks capability as specified by the REQ-CONN requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the openconnector-webhooks capability is exercised
- **THEN** the system MUST satisfy the REQ-CONN requirements defined in this group

---

### Requirement: REQ-CONN-001 — OpenConnector webhook subscriptions
The system SHALL support webhook subscriptions via the OpenConnector protocol for integration with third-party systems (ERP, CRM, case management). OpenConnector subscriptions SHALL use the same CloudEvents event types defined in REQ-N8N-001. Subscriptions SHALL be manageable from the same Webhooks admin UI as n8n endpoints, distinguished by type: `n8n` or `openconnector`.

#### Scenario: OpenConnector endpoint receives governance event
- **WHEN** a governance event is triggered (e.g., decision adopted)
- **THEN** all subscribed OpenConnector endpoints SHALL receive the CloudEvent via HTTP POST
- **THEN** the request SHALL include the `Content-Type: application/cloudevents+json` header

#### Scenario: OpenConnector and n8n endpoints coexist
- **WHEN** both an n8n and an OpenConnector endpoint are subscribed to `nl.decidesk.motion.adopted`
- **THEN** both endpoints SHALL receive the event independently

---

### Requirement: REQ-CONN-002 — OpenConnector delivery status monitoring
The system SHALL display webhook delivery status (last attempt timestamp, HTTP status, retry count) for each OpenConnector subscription in the admin Webhooks log. Administrators SHALL be able to manually re-trigger failed deliveries.

#### Scenario: Admin retries failed delivery
- **GIVEN** a webhook delivery that failed with HTTP 503
- **WHEN** the admin clicks "Retry" in the webhooks log
- **THEN** the event SHALL be re-dispatched to the endpoint
- **THEN** the log SHALL be updated with the new attempt result

---

### Capability: oauth-applications

The system MUST provide the oauth-applications capability as specified by the REQ-OAUTH requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the oauth-applications capability is exercised
- **THEN** the system MUST satisfy the REQ-OAUTH requirements defined in this group

---

### Requirement: REQ-OAUTH-001 — OAuth 2.0 application registration
The system SHALL define Decidesk-specific OAuth 2.0 read scopes via Nextcloud's `oauth2` app. External applications SHALL register via the standard Nextcloud OAuth2 client registration UI. Decidesk SHALL add the following scope definitions to the Nextcloud scope registry:

| Scope | Access granted |
|---|---|
| `meetings:read` | Read Meeting, AgendaItem objects |
| `motions:read` | Read Motion, Amendment, VotingRound, Vote objects |
| `votes:read` | Read Vote, VotingRound objects (alias for transparency apps) |
| `governance-bodies:read` | Read GovernanceBody, Person, Membership, Post objects |

**OCP interfaces used:** `OCP\Security\OAuth\IClientMapper`, Nextcloud `oauth2` app

#### Scenario: External app requests meetings:read scope
- **GIVEN** an OAuth 2.0 client registered in Nextcloud with `meetings:read` scope
- **WHEN** a user authorizes the client
- **THEN** the client receives a token granting access to `GET /api/v1/meetings` and `GET /api/v1/agenda-items`
- **THEN** access to `GET /api/v1/motions` SHALL be denied (out of scope)

#### Scenario: Token-authenticated API request succeeds
- **WHEN** `GET /api/v1/meetings` is requested with a valid Bearer token with `meetings:read` scope
- **THEN** the response SHALL be HTTP 200 with the meetings list

#### Scenario: Token with insufficient scope rejected
- **WHEN** `GET /api/v1/governance-bodies` is requested with a token that only has `meetings:read` scope
- **THEN** the response SHALL be HTTP 403 with `{ "message": "Insufficient scope" }`

---

### Requirement: REQ-OAUTH-002 — OAuth token validation middleware
The system's API controllers SHALL validate Bearer tokens using Nextcloud's built-in OAuth2 token validation. Token expiry, scope enforcement, and revocation checks SHALL be delegated entirely to the `oauth2` app. Decidesk SHALL NOT implement custom token validation logic.

#### Scenario: Expired token rejected
- **WHEN** an API request is made with an expired Bearer token
- **THEN** the response SHALL be HTTP 401 with `{ "message": "Token expired" }`

#### Scenario: Revoked token rejected immediately
- **WHEN** an OAuth token is revoked via Nextcloud Settings
- **THEN** subsequent API requests with that token SHALL be rejected with HTTP 401

---

### Requirement: REQ-OAUTH-003 — Authorization code flow with PKCE
External applications SHALL authenticate using OAuth 2.0 Authorization Code flow with PKCE (RFC 7636) as provided by the Nextcloud `oauth2` app. The authorization endpoint, token endpoint, and redirect URI validation SHALL be handled entirely by the Nextcloud `oauth2` app. Decidesk SHALL document the flow in `docs/api.md`.

#### Scenario: Authorization code flow completes
- **GIVEN** an external application registered in Nextcloud with `governance-bodies:read` scope
- **WHEN** the application initiates Authorization Code + PKCE flow and the user approves
- **THEN** the application receives an access token valid for Decidesk API calls

---

### Requirement: REQ-OAUTH-004 — Scope documentation published
The system SHALL include `docs/api.md` documenting all available OAuth scopes, the authorization flow, example requests with Bearer token headers, and instructions for registering an OAuth client in Nextcloud.

#### Scenario: API documentation accessible
- **WHEN** a developer accesses `docs/api.md` in the Decidesk repository
- **THEN** the document SHALL list all scopes, describe the authorization flow, and include at least one complete cURL example per scope

---

### Capability: reverse-proxy-support

The system MUST provide the reverse-proxy-support capability as specified by the REQ-PROXY requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the reverse-proxy-support capability is exercised
- **THEN** the system MUST satisfy the REQ-PROXY requirements defined in this group

---

### Requirement: REQ-PROXY-001 — Reverse proxy base URL configuration
The system SHALL support deployment behind HTTP reverse proxies by respecting Nextcloud's `overwrite.cli.url`, `overwrite.protocol`, and `overwrite.webroot` configuration keys. All API URLs, CalDAV calendar URLs, and Talk deep-links generated by Decidesk SHALL use the configured base URL rather than auto-detected server hostname. An admin settings field SHALL allow overriding the effective base URL from the Decidesk admin panel.

#### Scenario: API URLs use configured base URL
- **GIVEN** Nextcloud is configured with `overwrite.cli.url = "https://gemeente.amsterdam.nl/intranet"`
- **WHEN** the health check endpoint is called
- **THEN** the `baseUrl` field SHALL return `"https://gemeente.amsterdam.nl/intranet"`

#### Scenario: CalDAV URLs use overridden base URL
- **WHEN** CalDAV VEVENT entries are created for a meeting
- **THEN** any URL references within the VEVENT SHALL use the configured `overwrite.cli.url`

#### Scenario: Reverse proxy with HTTPS terminates correctly
- **GIVEN** Nextcloud configured with `overwrite.protocol = "https"` and `overwrite.cli.url = "https://raad.gemeente.nl"`
- **WHEN** the REST API is accessed via HTTP internally (behind the proxy)
- **THEN** API response links SHALL use `https://raad.gemeente.nl/...`

---

### Requirement: REQ-PROXY-002 — CORS allowed origins include reverse proxy domain
The system SHALL configure CORS `Access-Control-Allow-Origin` to include the domain from `overwrite.cli.url` when set. Direct server hostname SHALL NOT be exposed in CORS headers when a proxy override is configured.

#### Scenario: CORS origin uses overridden domain
- **GIVEN** `overwrite.cli.url = "https://raad.gemeente.nl"`
- **WHEN** a browser CORS preflight is received from `https://raad.gemeente.nl`
- **THEN** `Access-Control-Allow-Origin: https://raad.gemeente.nl` SHALL be returned

---

### Capability: ori-compatibility

The system MUST provide the ori-compatibility capability as specified by the REQ-ORI requirements below.

#### Scenario: Capability requirements apply
- **WHEN** the ori-compatibility capability is exercised
- **THEN** the system MUST satisfy the REQ-ORI requirements defined in this group

---

### Requirement: REQ-ORI-001 — ORI API 1.4 organization endpoint
The system SHALL expose `GET /api/ori/v1/organizations` and `GET /api/ori/v1/organizations/{id}` serializing GovernanceBody as ORI Organization in JSON-LD format per ORI API 1.4 specification.

**ORI field mapping (GovernanceBody → ORI Organization):**

| ORI field | Decidesk field | Notes |
|---|---|---|
| `@type` | — | `"Organization"` |
| `@context` | — | ORI context URL |
| `id` | `GovernanceBody.uuid` | |
| `name` | `GovernanceBody.name` | |
| `classification` | `GovernanceBody.bodyType` | |
| `identifiers` | — | OIN if available |
| `memberships` | linked Membership objects | |

#### Scenario: ORI organizations endpoint returns JSON-LD
- **WHEN** `GET /api/ori/v1/organizations` is requested
- **THEN** the response SHALL include `@context`, `@type: "Organization"`, and ORI-specified fields for each GovernanceBody
- **THEN** the `Content-Type` header SHALL be `application/ld+json`

#### Scenario: ORI organization includes membership count
- **WHEN** `GET /api/ori/v1/organizations/{id}` is requested
- **THEN** the response SHALL include a `memberships` array with current active memberships

---

### Requirement: REQ-ORI-002 — ORI API 1.4 person and membership endpoints
The system SHALL expose `GET /api/ori/v1/persons` and `GET /api/ori/v1/memberships` serializing Person and Membership entities per ORI API 1.4. Person responses SHALL include linked ContactDetail records.

**ORI field mapping (Person → ORI Person):**

| ORI field | Decidesk field |
|---|---|
| `@type` | `"Person"` |
| `id` | `Person.uuid` |
| `name` | `Person.name` |
| `email` | `Person.email` |
| `memberships` | linked Membership UUIDs |

#### Scenario: ORI persons returns all active persons
- **WHEN** `GET /api/ori/v1/persons` is requested
- **THEN** the response SHALL contain all Person objects with ORI-compliant field mapping

---

### Requirement: REQ-ORI-003 — ORI API 1.4 event endpoints (meetings)
The system SHALL expose `GET /api/ori/v1/events` and `GET /api/ori/v1/events/{id}` serializing Meeting as ORI Event. Meetings SHALL be read from OpenRegister (CalDAV as sync target only). The endpoint SHALL support filtering by `organization_id` and `start_date`.

**ORI field mapping (Meeting → ORI Event):**

| ORI field | Decidesk field | Akoma Ntoso equivalent |
|---|---|---|
| `@type` | — | `"Event"` / `FRBRdate` |
| `id` | `Meeting.uuid` | |
| `name` | `Meeting.title` | `FRBRname` |
| `start_date` | `Meeting.scheduledDate` | `FRBRdate` |
| `end_date` | `Meeting.endDate` | |
| `location` | `Meeting.location` | |
| `classification` | `Meeting.meetingType` | |
| `status` | `Meeting.lifecycle` | |
| `organization` | linked GovernanceBody | `FRBRauthor` |
| `agenda` | linked AgendaItem objects | |

#### Scenario: ORI events endpoint filtered by organization
- **WHEN** `GET /api/ori/v1/events?organization_id={uuid}` is requested
- **THEN** only Meetings linked to that GovernanceBody SHALL be returned

#### Scenario: ORI event includes agenda items
- **WHEN** `GET /api/ori/v1/events/{id}` is requested
- **THEN** the `agenda` field SHALL contain an array of linked AgendaItem summaries

---

### Requirement: REQ-ORI-004 — ORI API 1.4 motion, vote, and minutes endpoints
The system SHALL expose ORI API 1.4 endpoints for legislative actions:
- `GET /api/ori/v1/motions` → Motion as ORI Motion (Akoma Ntoso: `<act>`)
- `GET /api/ori/v1/agendaitems` → AgendaItem as ORI AgendaItem
- `GET /api/ori/v1/voteevents` → VotingRound as ORI VoteEvent
- `GET /api/ori/v1/votes` → Vote as ORI Vote
- `GET /api/ori/v1/reports` → Minutes as ORI Report (Akoma Ntoso: `<debate>`)
- `GET /api/ori/v1/amendments` → Amendment as ORI Amendment

**Motion → ORI Motion field mapping (Akoma Ntoso `<act>` overlay):**

| ORI field | Decidesk field | Akoma Ntoso |
|---|---|---|
| `id` | `Motion.uuid` | `FRBRuri` |
| `name` | `Motion.title` | `FRBRname` |
| `text` | `Motion.text` | `<body>` |
| `classification` | `Motion.motionType` | |
| `creator` | `Motion.proposer` | `FRBRauthor` |
| `date_submitted` | `Motion.submittedAt` | `FRBRdate` |
| `vote_events` | linked VotingRound UUIDs | |

#### Scenario: ORI motions returns adopted motions
- **WHEN** `GET /api/ori/v1/motions?status=adopted` is requested
- **THEN** only Motions with `lifecycle = "adopted"` SHALL be returned in ORI format

#### Scenario: ORI voteevents returns vote totals
- **WHEN** `GET /api/ori/v1/voteevents/{id}` is requested
- **THEN** the response SHALL include `votes_for`, `votes_against`, `votes_abstain`, and `result` fields
