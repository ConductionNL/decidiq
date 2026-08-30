# Proposal: Integration

## Why

Decidesk manages mission-critical governance workflows (meetings, decisions, voting) but currently operates as a closed system. Governance bodies need to integrate with their existing IT ecosystems — calendar systems, workflow automation, file management, and external applications serving citizens and partners. Without open APIs and system integrations, Decidesk data remains siloed, preventing organizations from building comprehensive decision management solutions that span their entire digital infrastructure. This change establishes Decidesk as a hub for governance data by exposing standardized APIs, enabling bidirectional synchronization with ubiquitous systems (iCalendar, Nextcloud Files), and supporting external integrations via webhooks and OAuth for participant-facing applications.

## What Changes

- **REST API (public)**: Publish a complete, OpenAPI-documented REST API following REST-API Design Rules, enabling external applications to query and manage governance entities (meetings, decisions, motions, votes)
- **iCalendar sync (bidirectional)**: Synchronize meetings and action items bidirectionally with standard calendar clients (Thunderbird, iOS, Android, Google Calendar) via CalDAV
- **Nextcloud Files integration**: Link meeting documents and decision attachments to Nextcloud Files, enabling file management workflows without duplicating data
- **Nextcloud Talk integration**: Launch video calls directly from meeting management interface for digital meeting participation
- **n8n workflow triggers**: Expose webhook events (meeting scheduled, decision adopted, vote closed) to trigger n8n automation workflows for custom governance automation
- **OpenConnector webhooks**: Standardize event dispatch via OpenConnector protocol for integration with third-party systems (ERP, CRM, case management)
- **Extended OAuth application capabilities**: Enable external participant-facing applications to access governance data securely via OAuth 2.0 token grants
- **Reverse proxy support**: Enable Decidesk to operate behind reverse proxies in complex government network topologies (required for some municipalities)

## Capabilities

### New Capabilities

- `rest-api-public`: Complete REST API for governance entities with authentication, pagination, filtering, and error handling following Nextcloud/OpenRegister patterns
- `icalendar-sync`: Bidirectional iCalendar synchronization for meetings and action items via CalDAV protocol
- `nextcloud-files-integration`: Link governance documents to Nextcloud Files with automatic sync when documents are added/removed
- `nextcloud-talk-integration`: Launch Nextcloud Talk video calls from meeting UI for digital meeting sessions
- `n8n-webhook-events`: Publish governance events (meeting created, decision adopted, voting closed) via CloudEvents for n8n workflow triggers
- `openconnector-webhooks`: Standardized webhook dispatch for external system integration
- `oauth-applications`: OAuth 2.0 application registration and token management for participant-facing apps
- `reverse-proxy-support`: Configure Decidesk to work behind HTTP reverse proxies with correct URL generation and CORS

### Modified Capabilities

- `caldav-first-storage` (from p4 ADR-002): Implementation of CalDAV-first meeting/action-item storage is foundational for iCalendar sync — no new spec needed but design.md will detail the sync mechanism
- `ori-compatibility` (from p4 ADR-003): ORI API endpoint already defined; implementation details in design.md for filtering/serialization performance

## Impact

**Code & APIs:**
- New OpenRegister-based REST endpoints at `/api/v1/{resource}` (Person, GovernanceBody, Meeting, Motion, VotingRound, etc.)
- New CalDAV service layer for bidirectional iCalendar sync
- New Nextcloud Talk + Files service adapters for UI integration
- New WebhookService dispatcher for OpenConnector + n8n events
- New OAuth application controller and token management

**Dependencies:**
- `sabre/dav` (already in Nextcloud) for CalDAV operations
- `php-oauth2-server` or Nextcloud's built-in OAuth adapter
- `OpenConnector` library (VNG standard) for webhook dispatch
- External integration contracts: n8n, Nextcloud Talk, Nextcloud Files

**User-Facing Changes:**
- Public API documentation site (openapi.yml)
- Meeting detail UI: Launch Talk button for digital meetings
- Meeting detail UI: File picker to link Nextcloud Files documents
- Admin settings: OAuth application registration interface
- Admin settings: Webhook configuration and logs

**Governance Domain Impact:**
- All 5 domains (legislative, association, corporate, operational, citizen participation) benefit from API access and iCalendar sync
- External applications can now be built on top of Decidesk APIs
- Meeting workflows can be automated via n8n
- Meetings sync seamlessly to municipal calendars
