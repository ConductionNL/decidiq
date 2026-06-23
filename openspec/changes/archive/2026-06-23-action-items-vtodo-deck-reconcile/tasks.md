# Tasks: Reconcile action items with VTODO + Deck leaf

> SHIPPED 2026-06-23 (decidesk PR #91; OR object-source capability PRs #200/#202/#203/#205).
> Action items are now a read-only CalDAV-VTODO projection. Live-verified on :8080.

## 1. Write path → VTODO
- [x] 1.1 `ActionItemExtractionService::saveExtracted()` creates VTODO action items via
      `ActionItemWriter` (OR `TaskService`) instead of `saveObject(schema:'ActionItem')`; maps
      title/description/dueDate/status + non-core fields (assignee, taskStatus) via the field blob.
- [x] 1.2 Other creation paths routed to the same write path: `DecideskToolProvider` (MCP add-action-item);
      `MinutesController` uses `ActionItemExtractionService`. New `ActionItemController` + `/api/action-items`
      create/update/delete endpoints for the frontend (ActionItemCaptureModal, DecisionActionItemsTab).

## 2. Deck leaf
- [~] 2.1/2.2 Deck-leaf board carried over from the prior migrate-action-items-to-deck-leaf work; the
      reconcile focuses on the VTODO source-of-truth + read-only projection. Deck board rendering over the
      VTODO projection is a follow-up (the registry binding + tab exist; board parity unverified this pass).

## 3. Convert app-local store to read-only projection
- [~] 3.1 Legacy `task`/`delegation` → VTODO DATA migration DEFERRED (documented follow-up): a repair step
      has no user session and CalDAV writes need per-user context. `MigrateActionItemsToDeckLeaf` retired to
      a no-op so it cannot 403 against the read-only schema. (0 legacy action items in the dev env.)
- [x] 3.2 `action-item` schema is a **read-only projection** of VTODOs; app-side writes rejected (403)
      by the OR read-only-projection guard (REQ-AI-DECK-004). LIVE-VERIFIED.
- [x] 3.3 Bound to the OpenRegister object-source capability
      (`x-openregister-object-source: {provider: caldav-vtodo, readOnly: true}`); no bespoke copier
      (REQ-AI-DECK-006). The OR capability landed first (#200/#202/#203/#205).
- [x] 3.4 Delegation/reclaim map onto the VTODO assignee field (round-tripped via X-OPENREGISTER-DATA);
      full delegation audit semantics are part of the deferred data-migration follow-up.

## 4. Dashboard / lists
- [x] 4.1 No repoint needed: `fetchCollection('action-item')` now transparently returns the VTODO
      projection. Overdue is derived at read time from `dueDate` (widgetLogic.js); `OverdueActionItemsJob`
      retired to a no-op (no more persisted overdue write).

## 5. Verify
- [x] 5.1 `openspec validate action-items-vtodo-deck-reconcile --strict` passes.
- [x] 5.2 LIVE on :8080: create via endpoint → VTODO → appears in the projection (assignee/taskStatus
      faithful, scoped count=1); object-API write → 403; update preserves untouched fields; delete → gone;
      new bundle 0 console errors. (Deck-card parity + idempotent data-migration = deferred per 2/3.1.)
- [x] 5.3 Schema-declarative (object-source binding); route-auth on the new endpoints (NoAdminRequired +
      user-scoped CalDAV = inherent IDOR safety); no app-local write store. PHPCS/PHPStan clean.
