# Tasks: Integration

## 1. REST API Foundation

- [ ] 1.1 Create OpenAPI 3.0 specification (`lib/Resources/openapi.yml`) defining all public endpoints, request/response schemas, and authentication
- [ ] 1.2 Implement base API controller with pagination (`_page`, `_limit`), filtering, and standardized error responses
- [ ] 1.3 Add API authentication using Nextcloud session tokens (no custom login required)
- [ ] 1.4 Implement CORS support with proper `OPTIONS` routes for browser clients
- [ ] 1.5 Create comprehensive API documentation site linking to OpenAPI spec

## 2. REST API — Core Endpoints

- [ ] 2.1 Implement `GET /api/v1/governance-bodies` and `GET /api/v1/governance-bodies/{id}` with filtering by domain/type
- [ ] 2.2 Implement `GET /api/v1/persons` and `GET /api/v1/persons/{id}` with contact detail relations
- [ ] 2.3 Implement `GET /api/v1/memberships` and `GET /api/v1/memberships/{id}` with person/body relations
- [ ] 2.4 Implement `GET /api/v1/meetings` and `GET /api/v1/meetings/{id}` (read-only, CalDAV source)
- [ ] 2.5 Implement `GET /api/v1/motions` and `GET /api/v1/motions/{id}` with amendment/vote relations
- [ ] 2.6 Implement `GET /api/v1/voting-rounds` and `GET /api/v1/voting-rounds/{id}` with individual votes
- [ ] 2.7 Implement `GET /api/v1/votes` filtering by voting-round
- [ ] 2.8 Implement `GET /api/v1/agenda-items` and `GET /api/v1/agenda-items/{id}` with motion relations
- [ ] 2.9 Implement `GET /api/v1/minutes` and `GET /api/v1/minutes/{id}` with meeting relations
- [ ] 2.10 Test all endpoints with OpenRegister schema validation (verify ORI API output format per specification)

## 3. iCalendar Sync — CalDAV Integration

- [ ] 3.1 Implement `CalDavService` for reading/writing VEVENT and VTODO via Nextcloud `CalDavBackend`
- [ ] 3.2 Create dedicated "DecideDesk" calendar per governance body in Nextcloud Calendar
- [ ] 3.3 Implement CalDAV VEVENT creation/update for meetings with X-DECIDESK-* extended properties (lifecycle, meeting-type, meeting-mode, quorum, series, body-uid)
- [ ] 3.4 Implement CalDAV VTODO creation/update for action items with X-DECIDESK-MOTION-UID and X-DECIDESK-MEETING-UID references
- [ ] 3.5 Implement bidirectional sync: Nextcloud Calendar changes → DecideDesk via webhook listener
- [ ] 3.6 Implement ATTENDEE property mapping from Person/Membership entities to CalDAV
- [ ] 3.7 Test iCalendar sync with standard clients (Thunderbird, iOS, Android, Google Calendar)

## 4. Nextcloud Files Integration

- [ ] 4.1 Create service adapter `FilesIntegrationService` to link governance documents to Nextcloud Files
- [ ] 4.2 Add UI component in Meeting detail page: file picker modal for selecting Nextcloud Files
- [ ] 4.3 Implement file relation storage: link File object to Meeting/Motion/Minutes via OpenRegister relations
- [ ] 4.4 Add file list section to Meeting/Motion/Minutes detail pages showing linked Files
- [ ] 4.5 Implement sync handler: when file is deleted from Nextcloud Files, remove relation from governance object
- [ ] 4.6 Test file picker integration and sync behavior

## 5. Nextcloud Talk Integration

- [ ] 5.1 Create service adapter `TalkIntegrationService` for creating/managing Talk rooms linked to meetings
- [ ] 5.2 Add UI button in Meeting detail page: "Launch video call" that creates Talk room on-demand
- [ ] 5.3 Generate Talk room access link scoped to meeting participants (attendees from CalDAV ATTENDEE)
- [ ] 5.4 Store Talk room ID on Meeting object for persistent link across sessions
- [ ] 5.5 Add Talk room link to meeting notifications sent to participants
- [ ] 5.6 Test Talk integration: room creation, participant access control, persistent links

## 6. Webhook & Event System — Foundation

- [ ] 6.1 Create `WebhookService` for managing webhook subscriptions and dispatch (already provided by OpenRegister, but integrate app-specific events)
- [ ] 6.2 Implement CloudEvents format for all governance events (RFC 9547 compatible)
- [ ] 6.3 Create event dispatcher for governance domain events:
  - [ ] 6.3a MeetingScheduled (when meeting created/date changed)
  - [ ] 6.3b MeetingLifecycleChanged (state transitions: draft → scheduled → opened → closed)
  - [ ] 6.3c MotionSubmitted (when motion created)
  - [ ] 6.3d VotingRoundOpened / VotingRoundClosed
  - [ ] 6.3e MotionAdopted / MotionRejected (decision outcome)
  - [ ] 6.3f ActionItemCreated / ActionItemCompleted

## 7. n8n Workflow Triggers

- [ ] 7.1 Implement n8n webhook trigger integration: dispatch events from section 6 to n8n webhook endpoints
- [ ] 7.2 Create admin UI for n8n webhook URL configuration
- [ ] 7.3 Test event delivery to n8n: verify CloudEvents format, retry logic on failure
- [ ] 7.4 Document n8n integration pattern: which events trigger, what data is sent

## 8. OpenConnector Webhooks

- [ ] 8.1 Implement `OpenConnectorService` dispatching governance events via OpenConnector protocol
- [ ] 8.2 Create admin UI for OpenConnector webhook subscriptions (endpoints, event types, filters)
- [ ] 8.3 Test webhook dispatch to external systems with error handling and logging
- [ ] 8.4 Document OpenConnector integration: supported event types, schema

## 9. OAuth 2.0 Application Management

- [ ] 9.1 Create `OAuthApplicationController` for registering external applications
- [ ] 9.2 Implement OAuth token flow: authorization code grant for participant-facing apps
- [ ] 9.3 Add admin UI: OAuth application registration form (app name, redirect URIs, scopes)
- [ ] 9.4 Implement token generation with configurable TTL and refresh token support
- [ ] 9.5 Create OAuth token validation middleware for API endpoints
- [ ] 9.6 Define scopes: `meetings:read`, `motions:read`, `votes:read`, `governance-bodies:read`
- [ ] 9.7 Test OAuth flow: authorization, token generation, API access with token

## 10. Reverse Proxy Support

- [ ] 10.1 Configure Nextcloud `overwrite.cli.url` and `overwrite.protocol` for reverse proxy environments
- [ ] 10.2 Add settings: Reverse proxy base URL configuration in admin panel
- [ ] 10.3 Update router URL generation to use configured base URL (not auto-detected)
- [ ] 10.4 Update CORS allowed origins to include reverse proxy domain
- [ ] 10.5 Test behind reverse proxy: URL generation, API calls, file downloads

## 11. ORI API Endpoint

- [ ] 11.1 Implement `/api/ori/v1/organizations` serializing GovernanceBody as ORI Organization
- [ ] 11.2 Implement `/api/ori/v1/persons` serializing Person as ORI Person
- [ ] 11.3 Implement `/api/ori/v1/memberships` as ORI Membership
- [ ] 11.4 Implement `/api/ori/v1/events` reading Meetings from CalDAV, serializing as ORI Event
- [ ] 11.5 Implement `/api/ori/v1/agendaitems`, `/api/ori/v1/motions`, `/api/ori/v1/amendments`
- [ ] 11.6 Implement `/api/ori/v1/voteevents`, `/api/ori/v1/votes`, `/api/ori/v1/reports` (Minutes)
- [ ] 11.7 Test ORI API output against ORI specification schema
- [ ] 11.8 Validate ORI API output format against specification (Test ORI API output format against specification)

## 12. Integration Testing

- [ ] 12.1 Create Postman/Newman collection for REST API with happy path + error cases for each endpoint
- [ ] 12.2 Test API pagination: verify `_page`, `_limit`, `total`, `pages` response structure
- [ ] 12.3 Test API filtering: verify filtering by required fields on each endpoint (e.g., `?domain=legislative`)
- [ ] 12.4 Test authentication: verify 401/403 responses for unauthenticated/unauthorized requests
- [ ] 12.5 Test CORS: verify browser requests with `OPTIONS` and proper `Access-Control-*` headers
- [ ] 12.6 Test iCalendar sync with bidirectional changes: meeting created in Decidesk → appears in Calendar; event in Calendar → meeting updated in Decidesk
- [ ] 12.7 Test file linking: add Nextcloud File to meeting → appears in UI and relations; remove → relation removed
- [ ] 12.8 Test Talk integration: launch video call → room created with attendees → persistent link on meeting
- [ ] 12.9 Test webhook dispatch: create motion → n8n webhook triggered → verify CloudEvents format
- [ ] 12.10 Test OAuth: register app → authorize → generate token → access API with token (Test workflow transitions for each governance domain)

## 13. Documentation

- [ ] 13.1 Write API documentation in `docs/api.md`: endpoint list, authentication, example requests
- [ ] 13.2 Document OpenAPI spec (`lib/Resources/openapi.yml`) with full schema definitions
- [ ] 13.3 Write integration guides: iCalendar sync, n8n webhooks, OAuth applications (Validate ORI API output format against specification)
- [ ] 13.4 Write reverse proxy setup guide
- [ ] 13.5 Document all governance domain workflows: how each domain interacts with APIs

## 14. Quality & Compliance

- [ ] 14.1 Run `composer check:strict` and fix all violations (PHPCS, PHPStan)
- [ ] 14.2 Add `@spec openspec/changes/p4-integration/tasks.md#task-N` docblock tags to all new classes/methods
- [ ] 14.3 Verify WCAG 2.1 AA compliance for all new UI components (OAuth app UI, file picker, Talk launcher)
- [ ] 14.4 Test accessibility: keyboard navigation, ARIA labels, screen reader compatibility
- [ ] 14.5 Smoke test: curl each API endpoint, verify response shape and status code
- [ ] 14.6 Test error paths: missing param, invalid auth, invalid input for each endpoint
