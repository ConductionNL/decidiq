## Why

Governance bodies produce decisions that must be tracked, versioned, approved, and shared with members and the public. T1 delivered the highest-demand document generation and statutory compliance workflows on top of the p2-minutes-and-decisions foundation. T2 addresses the next tier of market demand identified across 851 user stories and 2,000+ tender documents: stakeholder notifications on decision state changes (demand 399), embedded decision references via Smart Picker (demand 384), version-controlled meeting minutes with digital approval (demand 362 + 107), member portal publication of decisions (demand 117), and unified Nextcloud Search across all governance records (demand 99).

The Board Secretary / Company Secretary is the primary stakeholder: she receives no notification when decisions are published or when minutes move through the approval chain; there is no version history when two members edit the minutes simultaneously; she cannot share a direct link to a decision from within Nextcloud Mail or Talk without leaving the app. The Supervisory Board Member needs rich reference cards when citing decisions in board communications, and a unified search that spans decisions, minutes, and action items without switching to a separate governance portal. Without T2, these workflows rely on email forwarding and manual folder management — creating collaboration gaps and increasing the risk of missed approvals and outdated decision references.

This change delivers: Nextcloud notification subscriptions for Decision and Minutes lifecycle changes; version-controlled Minutes content with snapshot history and comparison; dual-sign digital approval workflow (chair + secretary) advancing Minutes from review to approved and through to published; member portal publication of Decisions with a copyable share link; a Nextcloud Search provider returning Decision, Minutes, and ActionItem results; and a Smart Picker reference provider for embedding decision links as rich cards in Mail, Text, and Talk.

## What Changes

- **New**: Decision and Minutes state-change notifications — when a Decision or Minutes transitions lifecycle state, all subscribed stakeholders receive a Nextcloud notification with a deep link to the object; `DecisionNotificationService` manages subscriptions and dispatch
- **New**: Minutes version control — every save that changes `content` increments the `version` integer; the prior version is stored as a JSON snapshot file via `FileService`; a "Versiegeschiedenis" panel in `MinutesDetail.vue` lists all snapshots with a diff comparison view
- **New**: Minutes digital approval workflow — chair and secretary each click "Goedkeuren" to add their userId to the `signedBy` array; when both have approved, the lifecycle automatically transitions from `review` to `approved`; a separate "Ondertekenen" action by the secretary advances to `signed`; a final "Publiceren" action advances to `published`
- **New**: Decision portal publication — a "Publiceren op ledenportaal" action sets `isPublished: true` and `publishedAt` on a Decision; a Nextcloud public share link is generated via `IShareManager`; the share URL is displayed on the Decision detail page with a copy button
- **New**: Nextcloud Search provider — `DecisionsSearchProvider` implementing `ISearchProvider` returns Decision, Minutes, and ActionItem results in the Nextcloud global search; results include title, lifecycle state, and a deep link to the detail page
- **New**: Smart Picker reference provider — `DecisionReferenceProvider` implementing `IReferenceProvider` enables users to embed Decision references as rich cards in Nextcloud Mail, Text, and Talk; cards show decision title, date, outcome, and a deep link

## Capabilities

### New Capabilities

- `decision-state-notifications`: Subscribe stakeholders to lifecycle state changes on Decision and Minutes objects; emit Nextcloud notifications via `NotificationService` when lifecycle transitions; `DecisionNotificationService::subscribe(objectId, objectType, userId)` and `::dispatch(objectId, objectType, oldState, newState)`; subscription state stored via `IAppConfig`
- `minutes-versioning`: On each Minutes `content` save where `content` differs from the stored value, increment `version` and write a JSON snapshot `{ version, content, savedAt, savedBy }` via `FileService::upload()`; `MinutesVersionService::createSnapshot()` and `::getVersionHistory(minutesId)`; "Versiegeschiedenis" panel shows snapshot list with diff comparison
- `minutes-digital-approval`: `MinutesApprovalService::addApproval(minutesId, userId, role)` validates caller has role `chair` or `secretary`; appends `{ userId, role, signedAt }` to `signedBy`; when both `chair` and `secretary` entries are present, auto-advances lifecycle to `approved`; `::advance(minutesId, userId, targetState)` handles `signed` and `published` transitions
- `decision-portal-publication`: `DecisionService::publishToPortal(decisionId, actorId)` sets `isPublished: true` and `publishedAt: now()`; creates a Nextcloud public share link via `IShareManager` pointing to a read-only public endpoint; stores share token in Decision `notes`; dispatches lifecycle notification; `GET /api/decisions/{id}/share-link` returns the public URL
- `nextcloud-search-provider`: `DecisionsSearchProvider` implementing `ISearchProvider`; calls `ObjectService.findAll()` for each registered schema (Decision, Minutes, ActionItem) with the query term via `IndexService`; returns `SearchResult` objects with title, subline (lifecycle + date), and deep-link URL in path format
- `decision-smart-picker`: `DecisionReferenceProvider` implementing `IReferenceProvider`; resolves URL patterns matching `/apps/decidesk/decisions/{uuid}` to a rich reference card with decision title, date, outcome, and legalBasis; searchable via `GET /api/decisions/search?q=...`

### Modified Capabilities

- `decision-publication` *(from p2-minutes-and-decisions, extended in p2-minutes-and-decisions-core-t1)*: Extended with portal publication via `publishToPortal()` and a public share link alongside the existing `isPublished` flag action and document generation from T1
- `action-item-tracking` *(from p2-minutes-and-decisions)*: Extended so ActionItem `taskStatus` changes to `overdue` or `completed` trigger a Nextcloud notification to the assigned user if they have subscribed via `decision-state-notifications`

## Impact

- Adds `DecisionNotificationService.php` and `MinutesVersionService.php` as the primary new PHP services; adds `MinutesApprovalService.php` and extends `DecisionService.php` with `publishToPortal()` and `getShareLink()`; adds `DecisionsSearchProvider.php` (implements `ISearchProvider`) and `DecisionReferenceProvider.php` (implements `IReferenceProvider`); adds `DecisionPublicController.php`, `NotificationSubscriptionController.php`, `MinutesVersionController.php`, `MinutesApprovalController.php`, `DecisionSearchController.php`
- No schema changes — Minutes (`signedBy: array`, `version: integer`) and Decision (`isPublished: boolean`, `publishedAt: string`) fields from ADR-000 are used as-is; no new entities; no ADR-000 update required
- Frontend: adds `MinutesVersionPanel.vue`; adds approval, sign-off, and publish buttons to `MinutesDetail.vue`; adds share link section to `DecisionDetail.vue`; adds notification toggle to both detail views; adds "Gepubliceerd" badge and filter to Decisions index
- Extends `appinfo/routes.php` with 13 new routes; search and reference providers are auto-discovered via `Application.php` registration
- Downstream: `decision-state-notifications` enables T3-tier automation hooks; `decision-portal-publication` share links are ready for p3-ori-publication to reference; `nextcloud-search-provider` surfaces all governance records in Nextcloud's unified search without additional integration
