# Tasks: Minutes and Decisions — Core T2

**Change:** p2-minutes-and-decisions-core-t2
**App:** Decidesk
**Entities:** Decision, Minutes, ActionItem

---

## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm notification dispatch reuses `NotificationService` from OpenRegister platform — no custom notification delivery code; subscriptions stored in `IAppConfig` (not OpenRegister objects) per ADR-001-data-layer; document findings
- [ ] 0.2 Confirm version snapshots reuse `FileService::upload()` and `CnObjectSidebar → CnFilesTab` — no new version entity; no custom file storage; `MinutesVersionService` is the only custom logic around snapshot serialisation and diff rendering
- [ ] 0.3 Confirm digital approval reuses `ObjectService.saveObject()` for lifecycle transitions — no dedicated signing entity; `MinutesApprovalService` is the only custom approval logic; `signedBy: array` and `version: integer` fields already exist in ADR-000
- [ ] 0.4 Confirm search integration reuses `ObjectService.findAll()` + `IndexService` — `DecisionsSearchProvider` implements `ISearchProvider` without custom Solr or vector search queries per deduplication rule
- [ ] 0.5 Confirm reference provider reuses `ObjectService.findObject()` — `DecisionReferenceProvider` implements `IReferenceProvider` without a custom Vue Smart Picker widget; Nextcloud renders the rich card
- [ ] 0.6 Confirm portal publication reuses `IShareManager::newShare()` for share token generation — no custom JWT or token system; share token stored in built-in `notes` field; no schema change to Decision required

---

## 1. Backend — DecisionNotificationService

- [ ] 1.1 Create `lib/Service/DecisionNotificationService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1` with the following public methods:
  - `subscribe(string $objectId, string $objectType, string $userId): void` — reads the current subscription array from `IAppConfig` under key `notification_subscriptions_{objectId}`; appends `{ userId, objectType, subscribedAt: now() }` if the user is not already subscribed; saves back via `IAppConfig::setValueArray()`
  - `unsubscribe(string $objectId, string $userId): void` — reads subscription array; removes the entry matching `userId`; saves back; is a no-op if the user is not subscribed
  - `isSubscribed(string $objectId, string $userId): bool` — reads subscription array; returns `true` if any entry has `userId` matching the given value
  - `dispatch(string $objectId, string $objectType, string $oldState, string $newState, string $objectTitle): void` — reads all subscriptions for `$objectId`; for each subscriber calls `NotificationService::notify()` with subject `decision_state_changed`, message including `$objectTitle`, `$oldState`, `$newState`; deep-link URL set to `/apps/decidesk/{objectType}s/{objectId}` in path format
- [ ] 1.2 Create `lib/Controller/NotificationSubscriptionController.php` — thin controller (< 10 lines/method) tagged `@spec` with:
  - `GET /api/notifications/{objectType}/{id}/subscriptions` → reads subscription state for current user; returns `{ subscribed: bool, subscribedAt: string|null }`
  - `POST /api/notifications/{objectType}/{id}/subscriptions` → calls `DecisionNotificationService::subscribe()` for the current user
  - `DELETE /api/notifications/{objectType}/{id}/subscriptions` → calls `DecisionNotificationService::unsubscribe()` for the current user
- [ ] 1.3 Register 3 new routes in `appinfo/routes.php`; specific routes before wildcard `{slug}` routes; annotate public endpoints with `#[NoCSRFRequired]` where applicable
- [ ] 1.4 Register `DecisionNotificationService` and `NotificationSubscriptionController` in `lib/AppInfo/Application.php`
- [ ] 1.5 Write PHPUnit tests in `tests/Unit/Service/DecisionNotificationServiceTest.php` tagged `@spec` covering: `subscribe` adds entry to IAppConfig; `subscribe` is idempotent (no duplicate if already subscribed); `unsubscribe` removes the correct entry; `isSubscribed` returns `true` after subscribe and `false` after unsubscribe; `dispatch` calls `NotificationService::notify()` for each subscriber with correct subject; minimum 5 test methods

---

## 2. Backend — MinutesVersionService

- [ ] 2.1 Create `lib/Service/MinutesVersionService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2` with:
  - `createSnapshot(string $minutesId, string $oldContent, string $actorId): void` — serialises `{ version: N, content: oldContent, savedAt: now(), savedBy: actorId }` to JSON; uploads via `FileService::upload()` with filename `minutes-v{N}.json` attached to the Minutes object; reads current `version` from Minutes object via `ObjectService`; after upload, increments `version` via `ObjectService.saveObject()`
  - `getVersionHistory(string $minutesId): array` — fetches all file attachments matching the pattern `minutes-v*.json` from the Minutes object via `FileService`; parses each JSON file; returns an array sorted by `version` descending with entries `{ version, savedAt, savedBy, filename }`
  - `getVersionContent(string $minutesId, int $version): ?array` — fetches and decodes the JSON snapshot file for `minutes-v{version}.json`; returns the decoded array or `null` if the file does not exist
  - `diffVersions(string $contentA, string $contentB): array` — computes a line-level diff between `$contentA` and `$contentB`; returns an array of `{ type: 'added'|'removed'|'unchanged', text: string }` entries; uses PHP `array_diff` on exploded lines without external libraries
- [ ] 2.2 Create `lib/Controller/MinutesVersionController.php` — thin controller tagged `@spec` with:
  - `GET /api/minutes/{id}/versions` → calls `MinutesVersionService::getVersionHistory()`; returns `{ versions: [...] }`
  - `GET /api/minutes/{id}/versions/{version}` → calls `getVersionContent()`; returns `{ version, content, savedAt, savedBy }` or 404 if not found
  - `GET /api/minutes/{id}/versions/{versionA}/diff/{versionB}` → loads both versions; calls `diffVersions()`; returns `{ diff: [...] }`
- [ ] 2.3 Register 3 new routes in `appinfo/routes.php`
- [ ] 2.4 Wire `MinutesVersionService::createSnapshot()` into the Minutes save path — extend the existing Minutes save handler (service or controller) to call `createSnapshot()` when the `content` field value differs from the stored value before saving; do NOT call `createSnapshot()` when only non-content fields change (e.g., `lifecycle`)
- [ ] 2.5 Register `MinutesVersionService` and `MinutesVersionController` in the DI container
- [ ] 2.6 Write PHPUnit tests in `tests/Unit/Service/MinutesVersionServiceTest.php` tagged `@spec` covering: `createSnapshot` uploads JSON file and increments version; `createSnapshot` with unchanged content is not called (guard condition tested at integration point); `getVersionHistory` returns snapshots in descending order; `getVersionContent` returns null for nonexistent version; `diffVersions` returns correct added/removed/unchanged entries; minimum 5 test methods

---

## 3. Backend — MinutesApprovalService

- [ ] 3.1 Create `lib/Service/MinutesApprovalService.php` — stateless service tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3` with:
  - `addApproval(string $minutesId, string $userId, string $role): void` — validates `$role` is `chair` or `secretary`; reads current Minutes via `ObjectService`; verifies Minutes lifecycle is `review` (throws `\InvalidArgumentException` if not); appends `{ userId, role, signedAt: now() }` to `signedBy` array; saves via `ObjectService.saveObject()`; if the updated `signedBy` contains both a `chair` and a `secretary` entry, calls `advance(minutesId, userId, 'approved')` automatically; dispatches notification via `DecisionNotificationService::dispatch()`
  - `advance(string $minutesId, string $userId, string $targetState): void` — validates `$targetState` is one of `approved`, `signed`, `published`; validates current lifecycle permits the transition (`review → approved`, `approved → signed`, `signed → published`); updates Minutes lifecycle via `ObjectService.saveObject()`; dispatches notification via `DecisionNotificationService::dispatch()`; records the actor's userId in the audit trail
  - `getApprovalStatus(string $minutesId): array` — reads Minutes `signedBy` array; returns `{ chairApproved: bool, chairUserId: ?string, chairSignedAt: ?string, secretaryApproved: bool, secretaryUserId: ?string, secretarySignedAt: ?string, approvals: array }`
- [ ] 3.2 Create `lib/Controller/MinutesApprovalController.php` — thin controller tagged `@spec` with:
  - `POST /api/minutes/{id}/approve` — body `{ role }` → `MinutesApprovalService::addApproval()` for current user
  - `POST /api/minutes/{id}/sign` → `MinutesApprovalService::advance(id, userId, 'signed')`; validates caller has role `secretary`
  - `POST /api/minutes/{id}/publish` → `MinutesApprovalService::advance(id, userId, 'published')`; validates caller has role `secretary`
  - `GET /api/minutes/{id}/approval-status` → `MinutesApprovalService::getApprovalStatus()`; public read-only
- [ ] 3.3 Register 4 new routes in `appinfo/routes.php`; all mutation endpoints require `IGroupManager` admin check OR governance role validation on backend
- [ ] 3.4 Register `MinutesApprovalService` and `MinutesApprovalController` in the DI container
- [ ] 3.5 Write PHPUnit tests in `tests/Unit/Service/MinutesApprovalServiceTest.php` tagged `@spec` covering: `addApproval` chair-only does not advance lifecycle; `addApproval` chair + secretary auto-advances to `approved` and dispatches notification; `addApproval` on non-review Minutes throws `InvalidArgumentException`; `advance` transitions `approved → signed` correctly; `advance` blocked on invalid state transition; `getApprovalStatus` returns correct bool flags; minimum 6 test methods

---

## 4. Backend — DecisionService Portal Publication

- [ ] 4.1 Extend `lib/Service/DecisionService.php` (from p2-minutes-and-decisions-core-t1) — add public method `publishToPortal(string $decisionId, string $actorId): string` tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4`:
  - Fetches Decision via `ObjectService`; verifies actor has role `chair` or `secretary` via `AuthorizationService`
  - Sets `isPublished: true` and `publishedAt: now()` via `ObjectService.saveObject()`
  - Calls `IShareManager::newShare()` to create a public link share pointing to `GET /api/decisions/{id}/public`; sets share type to `IShare::TYPE_LINK`; sets permissions to read-only
  - Stores the share token in Decision `notes` as JSON `{ shareToken: "..." }` via `ObjectService.saveObject()`
  - Dispatches notification via `DecisionNotificationService::dispatch()` with transition `published`
  - Returns the full public share URL as a string
- [ ] 4.2 Add `getShareLink(string $decisionId): ?string` method to `DecisionService` — reads `notes`, parses JSON, returns share URL or `null` if not published
- [ ] 4.3 Create `lib/Controller/DecisionPublicController.php` — annotated `#[PublicPage]` + `#[NoCSRFRequired]` tagged `@spec`:
  - `GET /api/decisions/{id}/public` — fetches Decision; returns HTTP 403 `{ message: "Decision is not publicly available" }` if `isPublished: false`; returns HTTP 200 with `{ title, text, decisionDate, outcome, legalBasis }` only — NEVER includes `notes`, `auditTrail`, `signedBy`, `tags`, or `relations`; registers OPTIONS route for CORS
- [ ] 4.4 Extend `lib/Controller/DecisionController.php` (from T1) with two new thin methods tagged `@spec`:
  - `POST /api/decisions/{id}/publish` → `DecisionService::publishToPortal()`; validates caller has chair or secretary role; returns `{ shareUrl: string }`
  - `GET /api/decisions/{id}/share-link` → `DecisionService::getShareLink()`; returns `{ shareUrl: string|null }`
- [ ] 4.5 Register 4 new routes (public endpoint + OPTIONS + publish + share-link) in `appinfo/routes.php`; public endpoint route registered BEFORE any authenticated wildcard routes
- [ ] 4.6 Register `DecisionPublicController` in the DI container
- [ ] 4.7 Write PHPUnit tests in `tests/Unit/Service/DecisionServicePublishTest.php` tagged `@spec` covering: `publishToPortal` sets isPublished and publishedAt correctly; `publishToPortal` creates share token stored in notes; `publishToPortal` blocked for member role; `getShareLink` returns null before publish and URL after publish; minimum 4 test methods

---

## 5. Backend — Nextcloud Search Provider

- [ ] 5.1 Create `lib/Search/DecisionsSearchProvider.php` implementing `OCP\Search\ISearchProvider` tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-5`:
  - `getId(): string` returns `'decidesk'`
  - `getName(): string` returns the translated string for "Besluiten en notulen" via `$this->l10n->t()`
  - `search(IUser $user, ISearchQuery $query): SearchResult` — calls `ObjectService.findAll()` for each of the three schemas (Decision, Minutes, ActionItem) with the query term as free-text filter; limits to 10 results per entity type; maps each result to `SearchResultEntry` with `title`, subline string (`lifecycle + " · " + date`), and deep-link URL in path format (`/apps/decidesk/{type}s/{uuid}`); returns `SearchResult::complete()` when all three sets are fetched; returns `SearchResult::paginated()` with cursor if total > 10 per type
- [ ] 5.2 Register `DecisionsSearchProvider` in `lib/AppInfo/Application.php` via `$context->registerSearchProvider(DecisionsSearchProvider::class)`
- [ ] 5.3 Write PHPUnit tests in `tests/Unit/Search/DecisionsSearchProviderTest.php` tagged `@spec` covering: `search()` returns Decision results with correct title and deep-link URL; `search()` returns Minutes results; `search()` returns ActionItem results; `search()` caps results at 10 per entity type; `search()` with no matches returns empty SearchResult; minimum 5 test methods

---

## 6. Backend — Smart Picker Reference Provider

- [ ] 6.1 Create `lib/Reference/DecisionReferenceProvider.php` implementing `OCP\Collaboration\Reference\IReferenceProvider` tagged `@spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6`:
  - `matchesUrl(string $url): bool` — returns `true` if URL contains `/apps/decidesk/decisions/` followed by a UUID
  - `resolveReference(string $url): ?IReference` — extracts UUID from URL; fetches Decision via `ObjectService.findObject()`; returns `null` if Decision not found; returns a `Reference` with `title` = Decision title, `description` = first 200 characters of `text`, `url` = the input URL; response cached for 3600 seconds with cache prefix `'decidesk-decision'`; if `isPublished: true`, appends "Gepubliceerd" to description; otherwise appends "Niet gepubliceerd"
  - `getCachePrefix(): string` returns `'decidesk-decision'`
  - `getCacheTtl(): int` returns `3600`
- [ ] 6.2 Create `lib/Controller/DecisionSearchController.php` — thin controller tagged `@spec`:
  - `GET /api/decisions/search?q={query}` — calls `ObjectService.findAll()` for Decision schema with the query; returns up to 20 results as `[{ id, title, decisionDate, outcome, url }]`; URL uses path format `/apps/decidesk/decisions/{uuid}`
- [ ] 6.3 Register 1 new route in `appinfo/routes.php`; register `DecisionReferenceProvider` in `lib/AppInfo/Application.php` via `$context->registerReferenceProvider(DecisionReferenceProvider::class)`
- [ ] 6.4 Register `DecisionSearchController` in the DI container
- [ ] 6.5 Write PHPUnit tests in `tests/Unit/Reference/DecisionReferenceProviderTest.php` tagged `@spec` covering: `matchesUrl` returns true for valid decidesk decision URL; `matchesUrl` returns false for non-decidesk URL; `resolveReference` returns null for unknown UUID; `resolveReference` returns IReference with correct title and truncated description for known Decision; published Decision includes "Gepubliceerd" in description; unpublished Decision includes "Niet gepubliceerd"; minimum 6 test methods

---

## 7. Frontend — Notification Subscription Toggle

- [ ] 7.1 Add notification subscription section to `src/views/DecisionDetail.vue`:
  - On mount, call `GET /api/notifications/decision/{id}/subscriptions` and set `isSubscribed` reactive boolean
  - Render a toggle labeled `t('decidesk', 'Enable notifications')` (nl: "Notificaties inschakelen") when `isSubscribed` is `false` and `t('decidesk', 'Notifications enabled')` (nl: "Notificaties ingeschakeld") when `true`
  - On toggle ON: `await axios.post('/api/notifications/decision/{id}/subscriptions')`; on toggle OFF: `await axios.delete('/api/notifications/decision/{id}/subscriptions')`; wrap both in `try/catch` with `NcDialog` error feedback
  - Place the toggle section inside a `CnDetailCard` titled `t('decidesk', 'Notifications')` below the decision meta section
- [ ] 7.2 Add identical notification subscription section to `src/views/MinutesDetail.vue` — same toggle pattern, uses `/api/notifications/minutes/{id}/subscriptions` endpoints
- [ ] 7.3 NEVER import from `@nextcloud/vue` — use `@conduction/nextcloud-vue` for all NcDialog, NcButton, CnDetailCard; import every component used in `<template>` in `components: {}`

---

## 8. Frontend — MinutesVersionPanel

- [ ] 8.1 Create `src/components/MinutesVersionPanel.vue` tagged `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line; title `t('decidesk', 'Version history')` (nl: "Versiegeschiedenis"):
  - On mount, call `GET /api/minutes/{id}/versions`; store result in `versions` array
  - Render list of version entries, each showing: `t('decidesk', 'Version') + N`, formatted `savedAt` timestamp via Nextcloud date formatting, `savedBy` display name
  - "Bekijken" button per entry opens a read-only content view dialog using `NcDialog`
  - Two `NcSelect` dropdowns (labelled `t('decidesk', 'Compare version A')` and `t('decidesk', 'Compare version B')`) for comparison selection; "Vergelijken" button calls `GET /api/minutes/{id}/versions/{vA}/diff/{vB}` and renders the diff in a dialog
  - Diff display: `type: 'added'` lines highlighted with `background-color: var(--color-success)`, `type: 'removed'` with `background-color: var(--color-error)`, `type: 'unchanged'` with no highlight; NO hardcoded hex or rgba colors
  - Empty state when `versions.length === 0`: `CnEmptyState` with message `t('decidesk', 'No version history available')` (nl: "Geen versiegeschiedenis beschikbaar")
  - All awaited store/API calls wrapped in `try/catch` with user-facing error feedback
- [ ] 8.2 Inject `MinutesVersionPanel.vue` into `src/views/MinutesDetail.vue` as a `CnDetailCard` section below the minutes content field; import and register in `components: {}`

---

## 9. Frontend — Minutes Digital Approval UI

- [ ] 9.1 Add "Goedkeuringen" (nl: Approvals) `CnDetailCard` section to `src/views/MinutesDetail.vue`:
  - On mount, call `GET /api/minutes/{id}/approval-status`; populate `chairApproved`, `chairSignedAt`, `secretaryApproved`, `secretarySignedAt`
  - Display chair status: name + timestamp if approved, `t('decidesk', 'Waiting for chair approval')` (nl: "Wacht op goedkeuring voorzitter") if not
  - Display secretary status: analogously for secretary
  - "Goedkeuren" button visible ONLY when lifecycle is `review` AND current user role is `chair` or `secretary`; on click: `await axios.post('/api/minutes/{id}/approve', { role: currentUserRole })`; refresh approval status on success
  - Include a `CnTimelineStages` component inside a `CnDetailCard` titled `t('decidesk', 'Lifecycle')` showing stages: draft, review, approved, signed, published with `currentStage` bound to Minutes lifecycle; do NOT wrap `CnTimelineStages` in an additional `CnDetailCard` (per ADR-017 — `CnTimelineStages` is NOT self-contained)
- [ ] 9.2 Add sign-off and publish action buttons to `src/views/MinutesDetail.vue`:
  - "Ondertekenen" button: visible ONLY when lifecycle is `approved` AND current user role is `secretary`; on click: `await axios.post('/api/minutes/{id}/sign')`; refresh page data on success
  - "Publiceren" button: visible ONLY when lifecycle is `signed` AND current user role is `secretary`; on click: open confirmation `NcDialog` (`t('decidesk', 'Confirm publication')`); on confirm: `await axios.post('/api/minutes/{id}/publish')`; refresh and show `CnStatusBadge` "Gepubliceerd"
  - Both buttons MUST NOT use `window.confirm()`; use `NcDialog` per ADR-015

---

## 10. Frontend — Decision Portal Publication UI

- [ ] 10.1 Add "Publiceren op ledenportaal" section to `src/views/DecisionDetail.vue`:
  - Visible ONLY when `isPublished: false` AND current user role is `chair` or `secretary`
  - "Publiceren op ledenportaal" button: on click, opens confirmation `NcDialog`; on confirm: `await axios.post('/api/decisions/{id}/publish')`; on success, reload Decision data and display share URL section
- [ ] 10.2 Add "Gedeeld via ledenportaal" (nl: Shared via member portal) `CnDetailCard` to `src/views/DecisionDetail.vue`:
  - Visible ONLY when `isPublished: true`
  - On mount, call `GET /api/decisions/{id}/share-link`; populate `shareUrl`
  - Display share URL in a read-only `NcTextField`
  - "Kopieer" button calls `navigator.clipboard.writeText(shareUrl)`; on success shows a toast message `t('decidesk', 'Link copied')` (nl: "Link gekopieerd") via `window.dispatchEvent` or Nextcloud OC.msg; NEVER use `window.alert()`
  - "Bekijk publieke pagina" anchor opens `shareUrl` in `target="_blank"`
- [ ] 10.3 Add published Decision badge to `src/views/Decisions.vue`:
  - In the Decision list row, show a `CnStatusBadge` with label `t('decidesk', 'Published')` (nl: "Gepubliceerd") when `isPublished: true`
  - Add a "Published" filter option to `CnFilterBar` that filters by `isPublished: true`; filter label: `t('decidesk', 'Published')` (nl: "Gepubliceerd")

---

## 11. Store and Route Updates

- [ ] 11.1 Verify `Decision`, `Minutes`, and `ActionItem` object types are registered in `src/store/store.js` via `createObjectStore` with `relationsPlugin`, `filesPlugin`, `auditTrailsPlugin`, and `lifecyclePlugin`; add any missing registrations using kebab-case type slugs (`decision`, `minutes`, `action-item`)
- [ ] 11.2 Verify `DecisionDetail`, `MinutesDetail`, and `ActionItemDetail` named routes exist in `src/router/`; add any missing routes; all detail routes use path format (`/decisions/:id`, `/minutes/:id`) NOT hash format; route props use arrow function for `id` param
- [ ] 11.3 Add `MinutesVersionPanel` import and registration to `src/views/MinutesDetail.vue`; add `NotificationSubscriptionController` API calls to both detail views; confirm every new `<NcFoo>` or `<CnFoo>` component in templates is imported AND registered in `components: {}`

---

## 12. Translations (ADR-007)

- [ ] 12.1 Add Dutch (nl) translation keys in `l10n/nl.json` and `l10n/nl.js` for all new user-visible strings including:
  - Notification: "Notificaties inschakelen", "Notificaties ingeschakeld", "Notificaties", "Abonnement verwijderd"
  - Versioning: "Versiegeschiedenis", "Geen versiegeschiedenis beschikbaar", "Versie", "Bekijken", "Vergelijken", "Compare version A", "Compare version B"
  - Approval: "Goedkeuringen", "Goedkeuren", "Wacht op goedkeuring voorzitter", "Wacht op goedkeuring secretaris", "Ondertekenen", "Publiceren", "Bevestig publicatie", "Goedgekeurd", "Ondertekend", "Gepubliceerd"
  - Publication: "Publiceren op ledenportaal", "Gedeeld via ledenportaal", "Link gekopieerd", "Kopieer", "Bekijk publieke pagina", "Niet gepubliceerd"
  - Search: "Besluiten en notulen"
- [ ] 12.2 Add English (en) translation keys in `l10n/en.json` and `l10n/en.js` — keys must be English equivalents of all Dutch strings above; English keys MUST match the `t('decidesk', 'key')` calls in Vue components; zero gaps between en.json and nl.json

---

## 13. Testing (ADR-008)

- [ ] 13.1 Write PHPUnit tests for `DecisionNotificationServiceTest.php` (task 1.5): subscribe idempotency; unsubscribe removes only the correct user; `dispatch` notifies all subscribers; `dispatch` does not notify after unsubscribe; minimum 5 test methods
- [ ] 13.2 Write PHPUnit tests for `MinutesVersionServiceTest.php` (task 2.6): createSnapshot writes correct JSON file name; version counter increments; getVersionHistory returns items newest-first; diffVersions identifies added/removed lines correctly; minimum 5 test methods
- [ ] 13.3 Write PHPUnit tests for `MinutesApprovalServiceTest.php` (task 3.5): dual-approval auto-advance; single approval does not advance; invalid state transition throws exception; advance through full chain (review → approved → signed → published); minimum 6 test methods
- [ ] 13.4 Write PHPUnit tests for `DecisionServicePublishTest.php` (task 4.7): publishToPortal sets fields and creates share token; getShareLink returns null before publication; publishToPortal blocked for member role; public endpoint returns 403 when isPublished is false; minimum 4 test methods
- [ ] 13.5 Write PHPUnit tests for `DecisionsSearchProviderTest.php` (task 5.3): Decision results include correct URL format; Minutes results include approvedAt subline; results capped at 10 per type; minimum 5 test methods
- [ ] 13.6 Write PHPUnit tests for `DecisionReferenceProviderTest.php` (task 6.5): matchesUrl true/false; resolveReference returns null for missing Decision; rich card includes correct title and description; isPublished flag reflected in description; minimum 6 test methods
- [ ] 13.7 Write Newman/Postman integration tests in `tests/integration/minutes-decisions-t2.json` covering all 13 new API endpoints: subscription CRUD (3), version endpoints (3), approval endpoints (4), publish/share endpoints (2), public decision endpoint (1); include at least one 403/404 error path per endpoint group; use env variable placeholders for credentials — NEVER hardcode defaults
- [ ] 13.8 Write Playwright browser tests for: REQ-DSN-001 (subscription toggle on Decision detail), REQ-DSN-003 (notification received on lifecycle change), REQ-MVC-001 (version increments on content save), REQ-MVC-002 (version history panel lists snapshots), REQ-MDA-001 (chair Goedkeuren button visible, hidden for member), REQ-MDA-002 (dual-approve auto-advances lifecycle), REQ-MDA-004 (Publiceren button advances to published), REQ-DPP-001 (Publiceren op ledenportaal sets isPublished and shows share URL), REQ-DPP-002 (copy share link copies to clipboard), REQ-DPP-003 (unauthenticated public endpoint returns 200 for published, 403 for unpublished), REQ-DPP-004 (Gepubliceerd badge in list), REQ-NSP-001 (Decision appears in Nextcloud global search), REQ-SMP-001 (Decision URL renders as rich card in Nextcloud Text)

---

## 14. Seed Data

- [ ] 14.1 No new seed objects are required — this change introduces no new OpenRegister schemas; all entities (Minutes, Decision, ActionItem) with their fields (`version`, `signedBy`, `isPublished`, `publishedAt`) are registered from prior specs; seed data from p2-minutes-and-decisions and p2-minutes-and-decisions-core-t1 provides baseline test data; document this finding in a comment in `lib/Settings/decidesk_register.json` for traceability

---

## 15. Verification

- [ ] 15.1 Verify notification subscriptions: subscribe to a Decision as user A; trigger a lifecycle change; confirm user A receives a Nextcloud notification with decision title, old state, new state, and a deep-link URL in path format; unsubscribe; trigger another change; confirm NO notification is received
- [ ] 15.2 Verify version control: open a Minutes in draft; edit and save the `content` field; confirm `version` increments to 2; open "Versiegeschiedenis" panel; confirm the prior version appears; click "Bekijken"; confirm prior content is shown read-only; save without changing content; confirm version does NOT increment
- [ ] 15.3 Verify version diff: with two versions present, select both in comparison dropdowns; click "Vergelijken"; confirm diff dialog shows added lines in `--color-success` and removed lines in `--color-error`; confirm NO hardcoded hex colours in rendered HTML
- [ ] 15.4 Verify dual-sign approval: log in as chair; open a Minutes in `review`; click "Goedkeuren"; confirm `signedBy` contains chair entry and lifecycle remains `review`; log in as secretary; click "Goedkeuren"; confirm lifecycle auto-advances to `approved`; confirm both approvers' names and timestamps show in "Goedkeuringen" section; confirm subscribers receive notification
- [ ] 15.5 Verify sign and publish lifecycle: log in as secretary on an `approved` Minutes; click "Ondertekenen"; confirm lifecycle becomes `signed`; click "Publiceren"; confirm dialog appears; confirm; confirm lifecycle becomes `published`; confirm "Gepubliceerd" badge appears; confirm button is NOT visible for a member role user
- [ ] 15.6 Verify portal publication: log in as secretary; open a Decision; click "Publiceren op ledenportaal"; confirm `isPublished: true` and `publishedAt` are saved; confirm share URL appears in "Gedeeld via ledenportaal" card; click "Kopieer"; confirm clipboard contains the URL; open the URL in an incognito window; confirm 200 response with `title`, `text`, `decisionDate`, `outcome`, `legalBasis`; confirm `notes`, `auditTrail`, `signedBy` are NOT in the response
- [ ] 15.7 Verify public endpoint 403: call `GET /api/decisions/{id}/public` for a Decision with `isPublished: false`; confirm HTTP 403 and `{ message: "Decision is not publicly available" }` is returned
- [ ] 15.8 Verify Nextcloud search: open Nextcloud global search; type a word appearing in a Decision `title`; confirm the Decision appears under "Besluiten en notulen"; click the result; confirm navigation to the Decision detail page using a path-format URL; repeat for Minutes and ActionItem
- [ ] 15.9 Verify Smart Picker: in Nextcloud Text, paste a full Decision detail URL; confirm a rich reference card renders with the decision title, text excerpt, and "Gepubliceerd" or "Niet gepubliceerd" indicator; confirm an unknown UUID URL renders as plain text with no card
- [ ] 15.10 Verify `@spec` PHPDoc tags: confirm ALL new PHP services, controllers, and public methods include `@spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-N` tags; check with `grep -rn '@spec' lib/ --include='*.php'` for coverage
- [ ] 15.11 Verify SPDX headers: `grep -rL 'SPDX-License-Identifier' src/ lib/ --include='*.php' --include='*.vue' --include='*.js'` → zero missing files; fix ALL instances before committing
- [ ] 15.12 Verify no hardcoded colours: `grep -rn '#[0-9a-fA-F]\{3,6\}\|rgb(' src/ --include='*.vue'` → zero matches; all colours must be Nextcloud CSS variables
- [ ] 15.13 Verify no `@nextcloud/vue` imports: `grep -rn "from '@nextcloud/vue'" src/` → zero matches; use `@conduction/nextcloud-vue` exclusively
- [ ] 15.14 Verify all translation keys are English: `grep -rn "t('decidesk'" src/ --include='*.vue' --include='*.js'` → confirm all keys are English; Dutch translations are ONLY in `l10n/nl.json`, never as t() keys
- [ ] 15.15 Verify Decision, Minutes, and ActionItem schemas in OpenRegister still match ADR-000 exactly after implementation — no extra properties added; `version`, `signedBy`, `isPublished`, `publishedAt` used as-is from ADR-000
