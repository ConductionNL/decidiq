---
status: done
---

# Specs: Minutes and Decisions — Core T2

**Change:** p2-minutes-and-decisions-core-t2
**App:** Decidesk
**Entities:** Decision, Minutes, ActionItem

---

## Purpose

This spec defines decision and minutes state notifications, minutes version control and digital approval, decision portal publication, unified search, and Smart Picker integration for Decidesk.

# Requirements

## REQ-DSN: Decision and Minutes State Notifications

The system SHALL satisfy the REQ-DSN (Decision and Minutes State Notifications) requirements specified below.

### REQ-DSN-001 — Subscribe to lifecycle state changes on a Decision
A user can subscribe to receive Nextcloud notifications when a Decision changes lifecycle state.

**GIVEN** a Decision detail page is open
**WHEN** the user clicks the "Notificaties inschakelen" toggle in the notification section
**THEN** `DecisionNotificationService::subscribe(decisionId, 'decision', userId)` is called
**AND** the subscription is stored in `IAppConfig` under key `notification_subscriptions_{decisionId}`
**AND** the toggle updates to "Notificaties ingeschakeld" state
**AND** the user can click the toggle again to unsubscribe

### REQ-DSN-002 — Subscribe to lifecycle state changes on a Minutes object
A user can subscribe to receive Nextcloud notifications when a Minutes object changes lifecycle state.

**GIVEN** a Minutes detail page is open
**WHEN** the user clicks the "Notificaties inschakelen" toggle
**THEN** `DecisionNotificationService::subscribe(minutesId, 'minutes', userId)` is called
**AND** the subscription is persisted in `IAppConfig`
**AND** the toggle reflects the active subscription state on next page load

### REQ-DSN-003 — Receive Nextcloud notification on Decision lifecycle change
A subscribed user receives a notification when a Decision transitions to a new lifecycle state.

**GIVEN** a user is subscribed to a Decision
**WHEN** the Decision lifecycle transitions to any new state (e.g., draft → review, or isPublished becomes true)
**THEN** `DecisionNotificationService::dispatch(decisionId, 'decision', oldState, newState)` is called
**AND** a Nextcloud notification is created via `NotificationService` for every user subscribed to that Decision
**AND** the notification subject is `decision_state_changed`
**AND** the notification message includes the Decision title and the new lifecycle state
**AND** the notification contains a deep link to the Decision detail page in path format (`/apps/decidesk/decisions/{uuid}`)

### REQ-DSN-004 — Receive notification on Minutes lifecycle change
A subscribed user receives a notification when a Minutes object transitions lifecycle state.

**GIVEN** a user is subscribed to a Minutes object
**WHEN** the Minutes lifecycle state changes (e.g., from review to approved)
**THEN** `DecisionNotificationService::dispatch(minutesId, 'minutes', oldState, newState)` is called for all subscribers
**AND** the notification message includes the Minutes title, old state, and new state
**AND** the deep link points to the Minutes detail page

### REQ-DSN-005 — Unsubscribe from object notifications
A subscribed user can stop receiving notifications for a specific object.

**GIVEN** a user has an active subscription to a Decision or Minutes object
**WHEN** the user clicks the notification toggle to disable it
**THEN** `DecisionNotificationService::unsubscribe(objectId, userId)` removes the entry from `IAppConfig`
**AND** the toggle reverts to "Notificaties inschakelen" state
**AND** subsequent lifecycle changes on that object do NOT generate a notification for this user

---

## REQ-MVC: Minutes Version Control

The system SHALL satisfy the REQ-MVC (Minutes Version Control) requirements specified below.

### REQ-MVC-001 — Version counter increments on content change
The Minutes version counter is incremented each time the `content` field is changed and saved.

**GIVEN** a Minutes object exists with `version: N` in any lifecycle state
**WHEN** the user saves a change that modifies the `content` field
**THEN** `MinutesVersionService::createSnapshot(minutesId, newContent, actorId)` is called before the save
**AND** the existing `content` is serialized as `{ version: N, content: <old_content>, savedAt: now(), savedBy: actorId }` and uploaded via `FileService` as `minutes-v{N}.json` attached to the Minutes object
**AND** the Minutes `version` field is incremented to `N+1` via `ObjectService.saveObject()`
**AND** the updated version number is visible on the Minutes detail page

**GIVEN** a Minutes object is saved but the `content` field is unchanged
**WHEN** only non-content fields (e.g., `lifecycle`) are updated
**THEN** `createSnapshot()` is NOT called and `version` is NOT incremented

### REQ-MVC-002 — View version history
A user can view the full version history of a Minutes object.

**GIVEN** a Minutes detail page is open
**WHEN** the user opens the "Versiegeschiedenis" panel
**THEN** `GET /api/minutes/{id}/versions` is called
**AND** all `minutes-v*.json` file attachments are listed in reverse chronological order (newest first)
**AND** each entry shows: version number, `savedAt` formatted timestamp, and `savedBy` display name
**AND** each entry has a "Bekijken" action that loads that version's content in a read-only view
**AND** when no versions exist, the panel shows "Geen versiegeschiedenis beschikbaar"

### REQ-MVC-003 — Compare two versions of Minutes
A user can compare the content between any two historical versions.

**GIVEN** the "Versiegeschiedenis" panel shows at least two version entries
**WHEN** the user selects two versions using the comparison dropdowns and clicks "Vergelijken"
**THEN** `GET /api/minutes/{id}/versions/{versionA}/diff/{versionB}` is called
**AND** a diff dialog renders two version contents side by side
**AND** lines present only in version B (added) are highlighted using `--color-success` CSS variable
**AND** lines present only in version A (removed) are highlighted using `--color-error` CSS variable
**AND** unchanged lines are displayed without highlighting
**AND** no hardcoded colors are used (only Nextcloud CSS variables)

---

## REQ-MDA: Minutes Digital Approval

The system SHALL satisfy the REQ-MDA (Minutes Digital Approval) requirements specified below.

### REQ-MDA-001 — Chair adds approval to Minutes
A user with role `chair` can add their approval to a Minutes object in `review` state.

**GIVEN** a Minutes object has lifecycle `review`
**AND** the current user has role `chair` in the linked GovernanceBody
**WHEN** the user clicks "Goedkeuren" in the "Goedkeuringen" section
**THEN** `POST /api/minutes/{id}/approve` is called with body `{ role: 'chair' }`
**AND** `MinutesApprovalService::addApproval(minutesId, userId, 'chair')` appends `{ userId, role: 'chair', signedAt: now() }` to the `signedBy` array
**AND** the "Goedkeuringen" section shows the chair's display name and approval timestamp
**AND** if only the chair has approved (secretary has not yet), the lifecycle remains `review`

**GIVEN** a user with role `member`, `observer`, or `guest` opens a Minutes in `review`
**WHEN** the page loads
**THEN** the "Goedkeuren" button is NOT visible for that user (approval restricted to `chair` and `secretary`)

### REQ-MDA-002 — Secretary adds approval and lifecycle auto-advances
When both chair and secretary have approved, the Minutes lifecycle automatically transitions from `review` to `approved`.

**GIVEN** a Minutes object has lifecycle `review` and `signedBy` contains a `chair` approval
**AND** the current user has role `secretary`
**WHEN** the user clicks "Goedkeuren"
**THEN** `MinutesApprovalService::addApproval(minutesId, userId, 'secretary')` is called
**AND** the `signedBy` array contains both a `chair` and a `secretary` entry
**AND** `MinutesApprovalService` calls `ObjectService.saveObject()` to transition lifecycle to `approved`
**AND** `DecisionNotificationService::dispatch()` sends notifications to all subscribed users
**AND** the Minutes detail page shows lifecycle `approved` and both approvers' names

### REQ-MDA-003 — Secretary advances Minutes from approved to signed
A secretary can mark Minutes as signed after dual approval to confirm the document is finalized.

**GIVEN** a Minutes object has lifecycle `approved`
**AND** the current user has role `secretary`
**WHEN** the user clicks "Ondertekenen"
**THEN** `POST /api/minutes/{id}/sign` is called
**AND** `MinutesApprovalService::advance(minutesId, userId, 'signed')` transitions lifecycle to `signed`
**AND** the audit trail records the signed timestamp and actor userId
**AND** subscribed stakeholders receive a notification that Minutes are signed

**GIVEN** a user with role `chair`, `member`, or `guest` opens Minutes with lifecycle `approved`
**WHEN** the page loads
**THEN** the "Ondertekenen" button is NOT visible (only secretary can sign)

### REQ-MDA-004 — Secretary publishes Minutes
A secretary can publish Minutes to make them officially available.

**GIVEN** a Minutes object has lifecycle `signed`
**AND** the current user has role `secretary`
**WHEN** the user clicks "Publiceren" and confirms in the dialog
**THEN** `POST /api/minutes/{id}/publish` is called
**AND** `MinutesApprovalService::advance(minutesId, userId, 'published')` transitions lifecycle to `published`
**AND** all subscribed stakeholders receive a notification that Minutes have been published
**AND** the Minutes list and detail page show lifecycle `published` with a "Gepubliceerd" badge

### REQ-MDA-005 — Approval status visible on Minutes detail page
A user can see the current approval status of a Minutes object at a glance.

**GIVEN** a Minutes detail page is open
**WHEN** the "Goedkeuringen" section renders
**THEN** `GET /api/minutes/{id}/approval-status` returns `{ chairApproved: bool, secretaryApproved: bool, approvals: [...] }`
**AND** the section shows: chair approval status (name + timestamp if approved, "Wacht op goedkeuring voorzitter" if not) and secretary approval status (name + timestamp if approved, "Wacht op goedkeuring secretaris" if not)
**AND** a `CnTimelineStages` component in the lifecycle section shows all five stages (draft, review, approved, signed, published) with the current stage highlighted

---

## REQ-DPP: Decision Portal Publication

The system SHALL satisfy the REQ-DPP (Decision Portal Publication) requirements specified below.

### REQ-DPP-001 — Publish a Decision to the member portal
A user with role `chair` or `secretary` can publish a Decision to generate a shareable public link.

**GIVEN** a Decision detail page is open and `isPublished` is `false`
**AND** the current user has role `chair` or `secretary`
**WHEN** the user clicks "Publiceren op ledenportaal"
**THEN** `POST /api/decisions/{id}/publish` is called
**AND** `DecisionService::publishToPortal(decisionId, actorId)` sets `isPublished: true` and `publishedAt: now()`
**AND** a Nextcloud public share link is created via `IShareManager::newShare()` pointing to `GET /api/decisions/{id}/public`
**AND** the share token is stored in the Decision `notes` as JSON `{ shareToken: "..." }`
**AND** the Decision detail page shows the share URL with a "Kopieer link" button
**AND** the change is recorded in the audit trail with actor and timestamp

**GIVEN** a user with role `member`, `observer`, or `guest`
**WHEN** the Decision detail page loads
**THEN** the "Publiceren op ledenportaal" button is NOT visible

### REQ-DPP-002 — Copy share URL from Decision detail page
A user can copy the public share URL of a published Decision to their clipboard.

**GIVEN** a Decision has `isPublished: true` and a share token in `notes`
**WHEN** the user opens the Decision detail page and "Gedeeld via ledenportaal" section renders
**THEN** `GET /api/decisions/{id}/share-link` returns `{ shareUrl: "..." }`
**AND** the section displays the full share URL in a read-only text field
**AND** clicking "Kopieer" copies the URL to the clipboard via `navigator.clipboard.writeText()`
**AND** a confirmation toast "Link gekopieerd" is shown
**AND** a "Bekijk publieke pagina" button opens the public URL in a new tab

### REQ-DPP-003 — Unauthenticated user views a published Decision
An unauthenticated user can view the public fields of a published Decision via the share link.

**GIVEN** a Decision has `isPublished: true` and a valid share token
**WHEN** an unauthenticated user calls `GET /api/decisions/{id}/public`
**THEN** the `DecisionPublicController` returns HTTP 200 with JSON: `{ title, text, decisionDate, outcome, legalBasis }`
**AND** the response does NOT include admin-only fields: `notes`, `auditTrail`, `signedBy`, `tags`, `relations`
**AND** the response uses `Content-Type: application/json` with a CORS header for the origin

**GIVEN** a Decision has `isPublished: false`
**WHEN** any user calls `GET /api/decisions/{id}/public`
**THEN** the controller returns HTTP 403 with `{ message: "Decision is not publicly available" }`

### REQ-DPP-004 — Published Decision badge and filter on index
A user can quickly identify published Decisions in the list view and filter by publication status.

**GIVEN** the Decisions index page is open
**WHEN** the page renders the Decision list
**THEN** Decisions with `isPublished: true` show a "Gepubliceerd" `CnStatusBadge` in the list row
**AND** the `CnFilterBar` contains a "Gepubliceerd" filter option that filters the list to show only `isPublished: true` Decisions
**AND** the filter count shows the number of currently published Decisions

---

## REQ-NSP: Nextcloud Search Provider

The system SHALL satisfy the REQ-NSP (Nextcloud Search Provider) requirements specified below.

### REQ-NSP-001 — Decisions appear in Nextcloud unified search
A user can find Decision objects by searching in the Nextcloud global search.

**GIVEN** a user opens the Nextcloud global search
**WHEN** the user types a search query matching a Decision `title` or `text` field
**THEN** `DecisionsSearchProvider::search()` is called with the query
**AND** matching Decision objects are returned via `ObjectService.findAll()` with the query as a full-text filter
**AND** results appear in the search results under a "Besluiten en notulen" provider section
**AND** each Decision result shows: title, lifecycle state, and `decisionDate`
**AND** clicking a result navigates to the Decision detail page using a path-format deep link (`/apps/decidesk/decisions/{uuid}`)

### REQ-NSP-002 — Minutes appear in Nextcloud unified search
A user can find Minutes objects by searching in the Nextcloud global search.

**GIVEN** a user opens the Nextcloud global search
**WHEN** the user types a search query matching a Minutes `title` or `content` field
**THEN** matching Minutes objects are returned via `ObjectService.findAll()`
**AND** results appear under the "Besluiten en notulen" provider section
**AND** each Minutes result shows: title, lifecycle state, and `approvedAt` date (or empty if not yet approved)
**AND** clicking a result navigates to the Minutes detail page via path-format deep link

### REQ-NSP-003 — ActionItems appear in Nextcloud unified search
A user can find ActionItem objects by searching in the Nextcloud global search.

**GIVEN** a user opens the Nextcloud global search
**WHEN** the user types a search query matching an ActionItem `title` or `description`
**THEN** matching ActionItem objects are returned via `ObjectService.findAll()`
**AND** results appear under the "Besluiten en notulen" provider section
**AND** each ActionItem result shows: title, `taskStatus`, and `dueDate` (if set)
**AND** clicking a result navigates to the ActionItem detail page via path-format deep link

### REQ-NSP-004 — Search provider returns at most 10 results per entity type
The search provider limits results to prevent overwhelming the Nextcloud search UI.

**GIVEN** a search query matches more than 10 Decision objects
**WHEN** `DecisionsSearchProvider::search()` is called
**THEN** at most 10 Decision results are returned
**AND** at most 10 Minutes results are returned in the same call
**AND** at most 10 ActionItem results are returned
**AND** no error is thrown — excess results are silently dropped after the limit

---

## REQ-SMP: Smart Picker Reference Provider

The system SHALL satisfy the REQ-SMP (Smart Picker Reference Provider) requirements specified below.

### REQ-SMP-001 — Decision URL renders as a rich reference card in Nextcloud apps
When a user pastes a Decision URL in Nextcloud Mail, Text, or Talk, it renders as a rich reference card.

**GIVEN** a user is composing a message in Nextcloud Mail, Text, or Talk
**WHEN** the user pastes a URL matching the pattern `{baseUrl}/apps/decidesk/decisions/{uuid}`
**THEN** `DecisionReferenceProvider::matchesUrl(url)` returns `true`
**AND** `DecisionReferenceProvider::resolveReference(url)` is called
**AND** the Decision is fetched via `ObjectService.findObject()`
**AND** a rich reference card is rendered by Nextcloud showing: decision `title`, first 200 characters of `text` as description, and a "Bekijk besluit" link back to the decision URL
**AND** the card uses Nextcloud's standard reference card styling — no custom CSS is added

**GIVEN** the UUID in the URL does not match any Decision
**WHEN** `resolveReference(url)` is called
**THEN** the method returns `null` and no card is rendered (URL treated as plain text)

### REQ-SMP-002 — Search for Decisions via Smart Picker type-ahead
A user can search for and insert a Decision reference using the Smart Picker type-ahead in a Nextcloud app.

**GIVEN** a user opens the Smart Picker in Nextcloud Mail, Text, or Talk and selects the Decidesk provider
**WHEN** the user types a search query
**THEN** `GET /api/decisions/search?q={query}` is called
**AND** the endpoint returns up to 20 Decision objects with `{ id, title, decisionDate, outcome, url }` matching the query
**AND** results are displayed in the Smart Picker picker interface with title and `decisionDate`
**AND** selecting a result inserts the Decision URL (`/apps/decidesk/decisions/{uuid}`) into the editor

### REQ-SMP-003 — Reference card shows accurate and current Decision data
The decision reference card reflects the current state of the Decision at resolution time.

**GIVEN** a Decision reference card is rendered in a Nextcloud app
**WHEN** the card data is loaded by `resolveReference()`
**THEN** the card shows the current `title` and `text` excerpt from the Decision object
**AND** if `isPublished: true`, the card shows a "Gepubliceerd" indicator
**AND** if `isPublished: false`, the card shows "Niet gepubliceerd" as a status note
**AND** the reference is cached for `3600` seconds (1 hour) to reduce repeated OpenRegister lookups
**AND** the cache prefix is `'decidesk-decision'` for proper cache key isolation
