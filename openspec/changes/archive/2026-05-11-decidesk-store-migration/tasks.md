# Decidesk store migration — tasks

## 1. Spec authoring

- [x] 1.1 Write `proposal.md`
- [x] 1.2 Write `design.md`
- [x] 1.3 Write `specs/decidesk-store-migration/spec.md` with REQ-DSM-1..7

## 2. Store rewrite

- [x] 2.1 Replace `src/store/store.js` with a thin wrapper that
       re-exports the lib's `useObjectStore` (registered as
       `'decidesk-objects'` with `liveUpdatesPlugin`) and the local
       `useSettingsStore`.
- [x] 2.2 Move all `objectStore.registerObjectType(...)` calls into
       `initializeStores()` against the lib store, including the new
       `meeting`, `agenda-item`, `participant`, `motion`, `amendment`,
       and `voting-round` types.
- [x] 2.3 Delete `src/store/modules/object.js` (replaced by the lib).
- [x] 2.4 Delete `src/store/modules/meetings.js` (no consumers).
- [x] 2.5 Delete `src/store/modules/minutes.js` (no consumers).
- [x] 2.6 Delete `src/store/modules/decisions.js` (no consumers).
- [x] 2.7 Delete `src/store/modules/actionItems.js` (no consumers).
- [x] 2.8 Update `src/components/tabs/useRelationStore.js` so it imports
       the **same** Pinia store ID (`'decidesk-objects'`) that
       `initializeStores()` populates.

## 3. Consumer migration

- [x] 3.1 `src/views/LiveMeeting.vue` — replace 3 `fetchObjects` calls
       with `fetchCollection`; remove dead polling fallback now that
       `subscribe` is a real method.
- [x] 3.2 `src/components/AmendmentList.vue` — replace `fetchObjects`
       with `fetchCollection`.
- [x] 3.3 `src/components/AgendaBuilder.vue` — replace 1 `fetchObjects`
       call with `fetchCollection`.
- [x] 3.4 `src/components/VotingRoundPanel.vue` — replace 2
       `fetchObjects` calls with `fetchCollection`; collapse the
       discarded `.results` access since the lib resolves to an array.

## 4. Validation

- [x] 4.1 `npx eslint src/` (clean on touched files).
- [x] 4.2 `node tests/validate-manifest.js` (still passes).
- [x] 4.3 `npx webpack --config webpack.config.js --mode production`
       (succeeds).
