## 0. Deduplication Check (ADR-012)

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for existing notification dispatch, decision relation, and bulk ActionItem creation capabilities; document findings — confirm no overlap with `NotificationService`, `ObjectService::addRelation()`, or existing cascade patterns before writing any new code

## 1. Backend — Decision Notification Service

- [ ] 1.1 Create `lib/Service/DecisionNotificationService.php` — stateless service with `notify(string $decisionId, array $recipientUids): int` that fetches the Decision object via `ObjectService::findObject('decidesk', 'Decision', $decisionId)`, builds notification title and body (decision title + outcome + deep-link path `/apps/decidesk/decisions/{uuid}`), calls `NotificationService::sendNotification()` for each recipient UID, and returns the count of notifications dispatched; annotate with `@spec openspec/changes/p2-minutes-and-decisions-other-t2/tasks.md#task-1`
- [ ] 1.2 Create `lib/Controller/DecisionActionsController.php` — thin controller; `notifyStakeholders(string $id): JSONResponse` action (POST); reads `recipientUids` array from request body; calls `DecisionNotificationService::notify()`; returns `{ notified: <count> }`; verify the Decision exists and `isPublished === true` before dispatching — return 400 if not published; annotate with `@spec` tags
- [ ] 1.3 Register route in `appinfo/routes.php`: `POST /api/decisions/{id}/notify` → `DecisionActionsController::notifyStakeholders`; place this before any wildcard `{slug}` route
- [ ] 1.4 Register `DecisionNotificationService` and `DecisionActionsController` in DI container (`lib/AppInfo/Application.php`)

## 2. Backend — Decision Cascade Service

- [ ] 2.1 Create `lib/Service/DecisionCascadeService.php` — stateless service with `cascade(string $decisionId, array $departmentNames): array` that fetches the Decision via `ObjectService::findObject()`, loops over `$departmentNames`, calls `ObjectService::saveObject('decidesk', 'ActionItem', [...])` for each (title = "Uitvoering: {decision title}", description = substr(decision text, 0, 500), assignee = department name, taskStatus = 'open', dueDate = date('+30 days')), adds an OpenRegister relation from each ActionItem back to the Decision, and returns array of created ActionItem UUIDs; annotate with `@spec` tags
- [ ] 2.2 Add `cascadeToDepament(string $id): JSONResponse` action (POST) to `DecisionActionsController` — reads `departments` array from request body; calls `DecisionCascadeService::cascade()`; returns `{ created: <count>, actionItems: [<uuids>] }`; verify Decision `isPublished === true` before cascading — return 400 if not published; annotate with `@spec` tags
- [ ] 2.3 Register route in `appinfo/routes.php`: `POST /api/decisions/{id}/cascade` → `DecisionActionsController::cascadeToDepartments`; place before wildcard routes

## 3. Backend — Decision Relation Management

- [ ] 3.1 Add `linkDecision(string $id): JSONResponse` action (POST) to `DecisionActionsController` — reads `targetDecisionId` and `relationType` (amends | supersedes | replaces | is-superseded-by) from request body; uses `ObjectService::addRelation()` to create an OpenRegister relation from the current Decision to the target Decision with `label: $relationType`; returns `{ success: true }`; annotate with `@spec` tags
- [ ] 3.2 Add `unlinkDecision(string $id, string $relationId): JSONResponse` action (DELETE) to `DecisionActionsController` — calls `ObjectService::deleteRelation()`; returns `{ success: true }`; annotate with `@spec` tags
- [ ] 3.3 Register routes in `appinfo/routes.php`: `POST /api/decisions/{id}/link` → `linkDecision`; `DELETE /api/decisions/{id}/relations/{relationId}` → `unlinkDecision`

## 4. Frontend — Decision Detail View Extensions

- [ ] 4.1 Extend `src/views/DecisionDetail.vue` — add "Gerelateerde besluiten" `CnDetailCard` section below existing property cards; use `fetchUses` to retrieve decisions this one links to and `fetchUsed` to retrieve decisions that link to this one; display each with columns: title, decisionDate, outcome (`CnStatusBadge`), relation type badge; row click → `DecisionDetail` for that decision; add "Koppel besluit" button in `#header-actions` slot of the card
- [ ] 4.2 Extend `src/views/DecisionDetail.vue` — add "Actiepunten afdelingen" `CnDetailCard` section; use `fetchUsed` on ActionItem schema to retrieve ActionItems related to this Decision; display columns: title, assignee, dueDate, taskStatus (`CnStatusBadge`); row click → `ActionItemDetail`; section only visible when at least one related ActionItem exists
- [ ] 4.3 Add "Betrokkenen informeren" button to `DecisionDetail.vue` header actions — only rendered when `isPublished === true`; clicking opens `NcDialog` with participant search autocomplete (fetches from Participants/Person object store); on confirm, calls `POST /api/decisions/{id}/notify` with selected UIDs via `axios`; shows success toast with notification count; wrap in `try/catch` with user-facing error feedback
- [ ] 4.4 Add "Cascaderen naar afdelingen" button to `DecisionDetail.vue` header actions — only rendered when `isPublished === true`; clicking opens `NcDialog` with governance body search autocomplete (fetches from GovernanceBody object store); on confirm, calls `POST /api/decisions/{id}/cascade` with selected department names via `axios`; refreshes the "Actiepunten afdelingen" card after success; wrap in `try/catch` with user-facing error feedback
- [ ] 4.5 Create `src/components/LinkDecisionDialog.vue` — `NcDialog` wrapping a Decision search input (live search via the Decision object store) and an `NcSelect` for relation type (amends / supersedes / replaces / is-superseded-by); emits `confirm({ targetDecisionId, relationType })` on confirm; all labels via `t(appName, 'text')` with Dutch translations in `l10n/nl.json`

## 5. Frontend — Decision Index Extension

- [ ] 5.1 Extend `src/views/Decisions.vue` — add a "Relaties" column to the `CnDataTable` that displays a `CnStatusBadge` labelled "Gelinkt" for decisions that have at least one relation (check `relations` array length on each object); no badge for decisions with no relations; all label strings via `t()`

## 6. Frontend — Dashboard Extension

- [ ] 6.1 Extend `src/views/Dashboard.vue` — add one new `CnStatsBlock` KPI card: "Besluit-actiepunten open" showing count of ActionItems with `taskStatus: open` or `taskStatus: in-progress`; fetch this count in the existing `Promise.all` block in `created()`; label via `t(appName, 'text')` with Dutch translation in `l10n/nl.json`

## 7. Translations

- [ ] 7.1 Add all new user-visible English keys to `l10n/en.json`: "Related decisions", "Link decision", "Notify stakeholders", "Cascade to departments", "Department action items", "Linked", "Decision published — outcome:", "Click to view the decision.", "Relation type", "Amends", "Supersedes", "Replaces", "Is superseded by", "Open cascade action items"
- [ ] 7.2 Add Dutch translations for all new keys to `l10n/nl.json`: "Gerelateerde besluiten", "Koppel besluit", "Betrokkenen informeren", "Cascaderen naar afdelingen", "Actiepunten afdelingen", "Gelinkt", "Besluit gepubliceerd — uitkomst:", "Klik om het besluit te bekijken.", "Relatietype", "Wijzigt", "Vervangt", "Vervangt", "Wordt vervangen door", "Besluit-actiepunten open"
- [ ] 7.3 Verify both files contain exactly the same keys with zero gaps (`diff <(jq -r 'keys[]' l10n/en.json | sort) <(jq -r 'keys[]' l10n/nl.json | sort)` → no output)

## 8. Tests

- [ ] 8.1 Write `tests/Unit/Service/DecisionNotificationServiceTest.php` — PHPUnit tests covering: (a) notify dispatches one notification per recipient UID and returns correct count; (b) notify with unpublished decision throws exception; (c) notify with empty recipient array returns 0 without calling NotificationService; minimum 3 test methods; add `@spec openspec/changes/p2-minutes-and-decisions-other-t2/tasks.md#task-8` tag
- [ ] 8.2 Write `tests/Unit/Service/DecisionCascadeServiceTest.php` — PHPUnit tests covering: (a) cascade creates one ActionItem per department name with correct fields; (b) cascade with unpublished decision throws exception; (c) cascade with empty departments array returns empty array without creating objects; minimum 3 test methods; add `@spec` tag
- [ ] 8.3 Write `tests/Unit/Controller/DecisionActionsControllerTest.php` — PHPUnit tests covering: (a) notifyStakeholders returns 400 when decision is not published; (b) cascadeToDepartments returns 400 when decision is not published; (c) unauthenticated request to notify returns 401; (d) unauthenticated request to cascade returns 401; add `@spec` tag

## 9. Verification

- [ ] 9.1 Verify decision linking: open a Decision detail page, click "Koppel besluit", link to another decision with type "supersedes", confirm the Related Decisions card shows both directions; click the linked decision and confirm navigation works
- [ ] 9.2 Verify relation removal: click the remove icon on a relation row, confirm the dialog appears, confirm the relation is removed from the card after confirmation
- [ ] 9.3 Verify "Gelinkt" badge: navigate to the Decisions index, confirm decisions with relations show the badge and decisions without do not
- [ ] 9.4 Verify stakeholder notification: on a published Decision, click "Betrokkenen informeren", select at least one participant, confirm; log in as that participant and verify the Nextcloud notification bell shows the notification with correct title, body, and deep link
- [ ] 9.5 Verify notification button is absent for unpublished decisions: open a Decision with `isPublished: false` and confirm the "Betrokkenen informeren" button is not rendered
- [ ] 9.6 Verify cascade: on a published Decision, click "Cascaderen naar afdelingen", select two governance bodies, confirm; verify two new ActionItems appear in the "Actiepunten afdelingen" card and in the ActionItems index with `taskStatus: open` and correct `assignee` values
- [ ] 9.7 Verify cascade button is absent for unpublished decisions: open a Decision with `isPublished: false` and confirm the "Cascaderen naar afdelingen" button is not rendered
- [ ] 9.8 Verify Dashboard KPI: confirm "Besluit-actiepunten open" card is present and count matches ActionItems with `taskStatus: open` or `in-progress`; create a cascade to add more and verify count increases
- [ ] 9.9 Verify all new user-visible strings use `t(appName, 'text')` — grep for hardcoded Dutch strings in new Vue components
- [ ] 9.10 Verify SPDX headers present on all new PHP and Vue files: `grep -rL 'SPDX-License-Identifier' lib/Service/DecisionNotificationService.php lib/Service/DecisionCascadeService.php lib/Controller/DecisionActionsController.php src/components/LinkDecisionDialog.vue`
- [ ] 9.11 Verify all `@spec` PHPDoc tags are present on new PHP classes and public methods linking to `openspec/changes/p2-minutes-and-decisions-other-t2/tasks.md`
- [ ] 9.12 Verify error paths: call `POST /api/decisions/{id}/notify` with an unpublished decision ID — expect HTTP 400; call with no auth — expect HTTP 401; call cascade endpoint with unpublished decision — expect HTTP 400
