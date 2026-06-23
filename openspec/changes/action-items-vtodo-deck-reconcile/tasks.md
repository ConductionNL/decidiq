# Tasks: Reconcile action items with VTODO + Deck leaf

## 1. Write path → VTODO
- [ ] 1.1 `ActionItemExtractionService::saveExtracted()` — create CalDAV VTODO ActionItems instead of
      `saveObject(schema: 'ActionItem')`; map title/assignee(ATTENDEE)/dueDate/status (ADR-002).
- [ ] 1.2 Audit other creation paths (`MinutesController`, `DecideskToolProvider`, any agenda flow)
      and route them to the same VTODO write path.

## 2. Deck leaf
- [ ] 2.1 Register the Deck integration (`leaf: 'deck'`, `boundSchemas: ['meeting','decision']`) in
      decidesk's bootstrap so the registry renders the board; graceful degradation when Deck absent.
- [ ] 2.2 Ensure the meeting + decision detail action-items tab mounts the registry deck board
      (`DecisionActionItemsTab` / meeting equivalent).

## 3. Convert app-local store to read-only projection
- [ ] 3.1 Run/verify `MigrateActionItemsToDeckLeaf` (idempotent): project existing app-local
      `ActionItem` + legacy `task`/`delegation` onto VTODOs, archive legacy (no hard delete).
- [ ] 3.2 Convert `ActionItem` schema to a **read-only projection** of VTODOs (decision resolved —
      not a hard retire); reject app-side writes (REQ-AI-DECK-004).
- [ ] 3.3 Bind the projection to the OpenRegister virtual-schema-over-leaf capability; do NOT build a
      bespoke CalDAV→OR copier. If the OR capability is absent, file/track the dependent OpenRegister
      change first (REQ-AI-DECK-006).
- [ ] 3.4 Map delegation/reclaim onto VTODO assignee + OR audit (REQ-AI-DECK-002).

## 4. Dashboard / lists
- [ ] 4.1 Repoint the "Open action items" KPI + any action-item list/filter to the VTODO-backed
      source (or its projection).

## 5. Verify
- [ ] 5.1 `openspec validate action-items-vtodo-deck-reconcile --strict`.
- [ ] 5.2 Live on :8080: extract action items → appear as VTODOs in Tasks app + as deck cards on the
      meeting detail; KPI matches; Deck-absent degrades gracefully; migration idempotent.
- [ ] 5.3 Hydra gates (no app-local write store, route-auth on any endpoint, schema-declarative).
