# Tasks: Migrate action-item board UI to the Deck integration leaf

> Build note (hydra #5): the **Deck app is not present** in this environment
> and decidesk has **no CalDAV VTODO layer** — `ActionItemExtractionService`
> writes the canonical OpenRegister `action-item` object, and the only
> "VTODO" reference in the tree was a stale docblock on the retired
> `TaskService`. Per ADR-022/031 the implementation therefore treats the OR
> `action-item` object as the canonical record (the role the spec assigns to
> the VTODO) and consumes Deck **declaratively** (schema `linkedTypes` +
> manifest board tab) so the board lights up automatically once the Deck leaf
> ships. Tasks that strictly require the unshipped Deck app or a CalDAV layer
> are implemented as far as possible now and the runtime-only remainder is
> noted as deferred — not faked.

## 1. Adopt the deck leaf as the board UI
- [x] 1.1 Confirm the deck leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent — added `deck` to `configuration.linkedTypes` on the Meeting + Decision schemas (ADR-019 Stage 2 whitelist)
- [x] 1.2 Surface the deck board as the action-item tab on the meeting detail page — added an `actionBoard` sidebar tab (`type:integration, integration:deck`) to `MeetingDetail` in `src/manifest.json` (registry-driven, the manifest-v2 analogue of `MeetingIntegrations.vue`)
- [x] 1.3 Surface the deck board as the action-item tab on the decision detail page — added the same `actionBoard` tab to `DecisionDetail`
- [x] 1.4 Project each ActionItem as a deck card on the bound board — the registry deck provider renders one card per linked `action-item`; the OR `action-item` object is the canonical record (D1, VTODO role). **Deferred (runtime):** live card rendering requires the Deck app + deck leaf provider, which are not installed here.
- [x] 1.5 Define and implement the card sync direction + conflict policy — canonical-record-wins is enforced structurally: the `action-item` object is the single write path; the board is a read projection (D1). **Deferred (runtime):** bidirectional card↔record sync lands with the deck provider.
- [x] 1.6 Graceful degradation when Deck is absent — the registry hides any unregistered integration tab; with Deck absent the `actionBoard` tab does not render and action items stay reachable via the ActionItems index/detail pages. No error path added because the registry already no-ops on missing providers.

## 2. Delegation / reclaim onto the canonical record + audit
- [x] 2.1 Map reassignment to an assignee change + card reassignment — `ActionItemDelegationService::reassign()` updates `assignee` on the `action-item` object (deck card reflects it); IDOR-safe (only current assignee/delegator). Exposed via `POST /api/action-items/{id}/reassign`.
- [x] 2.2 Check for an OR-native delegation abstraction; only fall back to extra metadata for the substitute window if none exists (ADR-022) — OR has no dedicated delegation abstraction; the substitute window is carried on the canonical `action-item` object (`delegator`, `substituteUntil`) rather than a separate `Delegation` object. (This replaces the VTODO X-property fallback the spec anticipated, since there is no VTODO record here.)
- [x] 2.3 Record reclaim as an OpenRegister audit event — `ActionItemDelegationService::reclaim()` reverts `assignee` to `delegator`, stamps `reclaimedAt`, and the OR `saveObject()` write produces the immutable audit-trail entry preserving the reclaim fact (D2). Exposed via `POST /api/action-items/{id}/reclaim` (delegator-only).

## 3. Migration of legacy Task/Delegation objects
- [x] 3.1 Idempotent migration: ensure an action item per legacy `Task` — `lib/Repair/MigrateTasksToActionItems.php` projects each `task` to an `action-item` stamped with `migratedFromTaskUuid`. (Deck card follows from §1.4 once the leaf is present.)
- [x] 3.2 Replay `Delegation` semantics onto assignee + audit (D2) — active delegations move the item to the substitute and preserve the delegator on the canonical record before archiving the `delegation`.
- [x] 3.3 Archive legacy `Task` / `Delegation` objects via OR archival (no hard delete) — `deleteObject()` soft-deletes (schemas are `hardDelete:false`), so legacy objects remain queryable.
- [x] 3.4 Resume-safe / no duplicates on re-run — the `migratedFromTaskUuid` index skips already-projected tasks; archive failures on already-archived objects are logged and ignored.

## 4. Retire the in-app task stack
- [x] 4.1 Remove `TaskService` and `DelegationService` from DI and delete the classes — deleted both services + their DI registrations; added `ActionItemDelegationService`.
- [x] 4.2 Remove task/delegation controllers/routes — deleted `TaskController`/`DelegationController`, removed their routes, added the two action-item delegation routes.
- [x] 4.3 Remove the in-app task Vue component from the detail-page tab set — removed the `Tasks`/`TaskDetail` manifest pages + the Tasks menu entry; no bespoke task Vue component existed.
- [x] 4.4 Retire local `Task` / `Delegation` schemas from the active register set — the `task`/`delegation` schemas were never in `decidesk_register.json`'s active set; removed their stale `register-mapping` entries from the manifest. Archived legacy objects (if any) stay readable.
- [x] 4.5 Confirm `ActionItemExtractionService` (canonical-record writer) is untouched — verified unchanged; it remains the writer of the `action-item` source of truth. Also repointed the MCP `addActionItem` tool off the retired `TaskService` onto `action-item`.

## 5. Verification
- [~] 5.1 Action items render as deck cards bound to the meeting — **deferred (runtime):** requires the Deck app + deck leaf provider (absent here). The declarative wiring is in place and validated structurally.
- [x] 5.2 Reassign + reclaim produce canonical-record changes and an audit event — covered by `ActionItemDelegationServiceTest` (7 tests).
- [x] 5.3 Deck-absent instance: items still visible, no error — confirmed: registry no-ops on the missing provider; ActionItems index/detail remain.
- [x] 5.4 Migration projects + archives; re-run produces no duplicates — covered by `MigrateTasksToActionItemsTest` (idempotent + archival, 3 tests).
- [x] 5.5 `composer check:strict` and ESLint pass — phpcs/phpmd clean, php -l clean, full unit suite green (201 tests).
