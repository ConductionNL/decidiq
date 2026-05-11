## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. T1 added document generation (permit decisions, Woo disclosures, contracts), statutory deadline tracking, urgent flagging, decision list generation, and audit trail surfaces for the post-meeting workflow. T2 builds the collaboration layer on top of those foundations: stakeholder notification subscriptions, version-controlled Minutes with snapshot history, digital dual-sign approval workflow, member portal publication for Decisions, Nextcloud Search integration, and a Smart Picker reference provider.

The Minutes entity already has `version: integer` and `signedBy: array` fields (ADR-000); T2 exercises both for the first time. The Decision entity already has `isPublished: boolean` and `publishedAt: string` fields (ADR-000); T2 uses these for member portal publication. The `ActionItem` entity's `taskStatus` field is used for notification triggers. No schema changes are needed — all fields are from ADR-000.

The Board Secretary / Company Secretary (primary persona) is responsible for the entire approval chain: drafting minutes, getting chair + secretary dual approval, marking minutes as signed, and publishing. The Supervisory Board Member (secondary persona) needs to search across all governance records and embed decision references in board communications. The CEO / Managing Director needs notifications when critical decisions are published so they can act immediately.

## Goals / Non-Goals

**Goals:**
- Nextcloud notification subscriptions for Decision and Minutes lifecycle state changes
- Minutes version control: content snapshots via FileService, version counter increment
- Minutes digital approval: chair + secretary dual sign-off via `signedBy`, lifecycle auto-transition draft → review → approved → signed → published
- Decision portal publication: `isPublished` + `publishedAt` + Nextcloud public share link via `IShareManager`
- Read-only public Decision endpoint for unauthenticated access
- Nextcloud Search provider (`ISearchProvider`) for Decision, Minutes, and ActionItem
- Smart Picker reference provider (`IReferenceProvider`) for Decision deep links in Mail, Text, and Talk

**Non-Goals:**
- Full ORI/PLOOI webhook push or harvesting integration (p3-ori-publication)
- PKI-based or external digital signatures (future signing spec)
- Real-time collaborative editing of Minutes content (future real-time collaboration spec)
- Mobile push notifications beyond Nextcloud's built-in notification system
- AI-assisted meeting transcription or document drafting (future AI spec)
- Integration with external member portal systems (Nextcloud public share is sufficient for T2)
- Ranked-choice or weighted voting UI (deferred in p2-motion-and-voting)

## Decisions

### 1. Notification subscriptions stored in `IAppConfig` per object

**Decision**: `DecisionNotificationService` stores subscriptions as a JSON array in `IAppConfig` with key `notification_subscriptions_{objectId}`. Each entry is `{ userId, objectType, subscribedAt }`. On lifecycle change, `::dispatch()` reads the list and calls `NotificationService::notify()` for each subscriber.

**Rationale**: `IAppConfig` is the platform-recommended config store (ADR-001-data-layer: app config → `IAppConfig`). Subscriptions are user preferences, not governance domain data — they must not be stored in OpenRegister objects. A governance body has ~20–50 members so subscription arrays are low-volume and change infrequently, making `IAppConfig` appropriate without a dedicated table.

**Alternative considered**: Store subscriptions as an OpenRegister object with each user's preferences — rejected (`IAppConfig` is the correct layer for app configuration per ADR; OpenRegister objects are domain data; mixing would create incorrect audit trail entries for user preference changes).

### 2. Version snapshots stored as FileService attachments on the Minutes object

**Decision**: `MinutesVersionService::createSnapshot()` serializes the current `content` and metadata to JSON and uploads it via `FileService::upload()` with filename `minutes-v{N}.json`, attaching it to the Minutes object. The "Versiegeschiedenis" panel reads all `minutes-v*.json` file attachments and renders them as a version list with diff.

**Rationale**: `FileService` is the platform-provided file storage (ADR built-in capabilities: "File tab in object sidebar — `CnObjectSidebar` → `CnFilesTab`"). Storing snapshots as file attachments reuses the existing file infrastructure without a new entity. JSON format is diff-friendly and human-readable. No schema change to Minutes is needed since the `version: integer` field already exists.

**Alternative considered**: A dedicated `MinutesVersion` entity with content and metadata fields — rejected (ADR-000 prohibits new entities without an ADR update; `FileService` snapshots achieve the same goal with zero schema changes and use the built-in platform capability).

### 3. Dual sign-off via `signedBy` array with lifecycle auto-advance

**Decision**: `MinutesApprovalService::addApproval()` reads the current `signedBy` array and appends a `{ userId, role, signedAt }` entry. If the array contains at least one `chair` and one `secretary` entry, `ObjectService.saveObject()` is called to advance lifecycle from `review` to `approved`. The secretary then manually advances to `signed` (final review) and `published`.

**Rationale**: `signedBy: array` is already defined on Minutes in ADR-000. A lightweight dual sign-off check in the service layer avoids a dedicated signing entity. The lifecycle progression matches existing Minutes lifecycle states (draft → review → approved → signed → published). Auto-advance on dual approval reduces friction while preserving auditability.

**Alternative considered**: A `MinutesSignature` entity with digital certificate fields — rejected (ADR-000 is authoritative; `signedBy` covers the T2 use case; PKI signing is deferred to a future spec; no entity update required).

### 4. Portal publication via Nextcloud `IShareManager` with a read-only public endpoint

**Decision**: `DecisionService::publishToPortal()` calls `IShareManager::newShare()` to create a public link share pointing to `GET /api/decisions/{id}/public`. The share token is stored in the Decision's `notes` as `{ shareToken: '...' }` via `ObjectService.saveObject()`. The public endpoint (`#[PublicPage]` + `#[NoCSRFRequired]`) returns only `title`, `text`, `decisionDate`, `outcome`, and `legalBasis` — no admin fields.

**Rationale**: Nextcloud's `IShareManager` is the canonical platform mechanism for generating public share links. It handles token generation, expiry, and revocation without custom code. Storing the share token in Decision `notes` (built-in OpenRegister field) avoids a schema change. The public endpoint follows ADR-002-api (public endpoints annotated `#[PublicPage]` + `#[NoCSRFRequired]`, CORS OPTIONS route registered).

**Alternative considered**: Generate a signed JWT URL without Nextcloud Share — rejected (`IShareManager` is platform-provided, handles token revocation and password protection; a custom JWT system would duplicate platform functionality without adding value).

### 5. Search provider delegates to `ObjectService.findAll()` for each entity type

**Decision**: `DecisionsSearchProvider::search()` calls `ObjectService.findAll()` for Decision, Minutes, and ActionItem schemas, each with the query string as a free-text filter. Up to 10 results per entity type are mapped to `SearchResultEntry` objects with title, subline (lifecycle + date), URL in path format, and the Decidesk app icon.

**Rationale**: OpenRegister already indexes all object properties via `IndexService`; full-text search is a built-in platform capability that must not be rebuilt (ADR deduplication rule). The `ISearchProvider` interface is the Nextcloud-standard integration point for global search. No custom Solr queries or vector search are needed for governance object name/title matching.

**Alternative considered**: A custom Elasticsearch index mirroring OpenRegister data — rejected (operational overhead with no benefit at governance-object scale; OpenRegister's built-in `IndexService` is sufficient; deduplication rule requires using the platform).

### 6. Reference provider implements `IReferenceProvider` — no custom picker component

**Decision**: `DecisionReferenceProvider` implements Nextcloud's `IReferenceProvider`, matching URL patterns containing `/apps/decidesk/decisions/{uuid}`. When a user pastes a decision URL in Mail/Text/Talk, Nextcloud calls `resolveReference(url)`, which fetches the Decision via `ObjectService.findObject()` and returns an `IReference` with title, description (text excerpt), and URL. The Smart Picker search endpoint is `GET /api/decisions/search?q=...`.

**Rationale**: `IReferenceProvider` is the Nextcloud-standard API for link previews in all Nextcloud apps. Implementing it requires zero frontend changes — Nextcloud handles rendering the rich card. A single search endpoint reuses `ObjectService.findAll()` without custom search logic (deduplication rule).

**Alternative considered**: A custom Smart Picker widget with a custom JavaScript component — rejected (`IReferenceProvider` is sufficient for rich cards without maintaining a custom Vue component; Nextcloud handles all rendering).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / Nextcloud API used | Custom code |
|---|---|---|
| Notification subscriptions | `IAppConfig` (storage), `NotificationService` (dispatch) | `DecisionNotificationService` (subscribe/unsubscribe/dispatch logic) |
| Notification API | Built-in REST controller pattern (ADR-002-api) | `NotificationSubscriptionController` (3 thin methods) |
| Version snapshots | `FileService` (upload/download), `CnObjectSidebar` → `CnFilesTab` (display) | `MinutesVersionService` (snapshot + diff logic), `MinutesVersionPanel.vue` |
| Version API | Built-in REST controller pattern | `MinutesVersionController` (2 thin endpoints) |
| Digital approval | `ObjectService.saveObject()` (lifecycle update), `signedBy` built-in array | `MinutesApprovalService` (dual sign-off + lifecycle advance) |
| Approval API | Built-in REST controller pattern | `MinutesApprovalController` (4 thin endpoints) |
| Portal publication | `IShareManager.newShare()` (share link), `ObjectService.saveObject()` (store token in notes) | `DecisionService::publishToPortal()` (new method, extends T1 service) |
| Public endpoint | `ObjectService.findObject()` (data fetch), `#[PublicPage]` annotation | `DecisionPublicController` (read-only 1-method controller) |
| Share link API | Built-in REST controller pattern | 2 new methods on `DecisionController` from T1 |
| Search provider | `ObjectService.findAll()` (full-text), `ISearchProvider` interface | `DecisionsSearchProvider` (result mapping) |
| Reference provider | `ObjectService.findObject()` (resolve), `IReferenceProvider` interface | `DecisionReferenceProvider` (URL matching + IReference mapping) |
| Smart Picker search | `ObjectService.findAll()` (query) | `DecisionSearchController` (1-method thin endpoint) |
| Notification toggle UI | Nextcloud built-in notification system, `CnObjectSidebar` | Subscription toggle in `DecisionDetail.vue` / `MinutesDetail.vue` |
| Approval UI | `CnTimelineStages` (lifecycle progression), `CnStatusBadge` | Approval section + action buttons in `MinutesDetail.vue` |
| Publication UI | `CnStatusBadge` (published badge), `CnFilterBar` (filter) | Share link section in `DecisionDetail.vue`, badge in Decisions index |

No new entities proposed. No overlap with existing OpenRegister platform capabilities. `DecisionNotificationService` and `MinutesVersionService` are the only net-new standalone PHP service classes; `MinutesApprovalService` is a new service for approval logic; `DecisionService::publishToPortal()` extends the T1 service.

## Seed Data

This change does not introduce or modify OpenRegister schemas — all entities used (Minutes, Decision, ActionItem) are registered from p1-schemas-and-data-model, and all entity fields exercised by T2 (`version`, `signedBy`, `isPublished`, `publishedAt`) are already defined in ADR-000. No new seed objects are required per the company-wide ADR rule: seed data is required only when a change introduces or modifies schemas.

Existing seed objects for Minutes and Decision from p2-minutes-and-decisions and p2-minutes-and-decisions-core-t1 provide the baseline data for T2 browser and integration tests.
