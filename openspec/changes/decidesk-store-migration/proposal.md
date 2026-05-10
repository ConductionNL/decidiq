# Decidesk store migration to @conduction/nextcloud-vue

## Why

Decidesk ships its own `src/store/store.js` (a Pinia store with a small custom
API: `configure`, `registerObjectType`, `fetchObjects`). The shared library
`@conduction/nextcloud-vue` exports a much richer object store
(`useObjectStore` / `createObjectStore`) with `fetchCollection`,
`fetchObject`, `saveObject(type, …)`, plugin-based sub-resources (files,
audit trails, relations, **live updates**), and the same data model.

Decidesk issue **#162** surfaces the cost of having two stores side by side:

* `LiveMeeting.vue` calls `objectStore.fetchObject('meeting', id)`, but
  the local store only exposes `fetchObjects` (plural). The result is
  silent runtime failure — the meeting page renders the i18n fallback
  ("Live meeting") because `meeting.title` is undefined.
* `LiveMeeting.vue` also calls `objectStore.subscribe(...)` /
  `objectStore.unsubscribe(...)` which only exist on the lib store
  through `liveUpdatesPlugin`. The current code wraps the call in
  `try/catch` and falls back to a 30-second polling timer — meaning the
  notify_push integration shipped in PR #161 has been dead-on-arrival
  for every consumer.
* `AmendmentList.vue` calls `objectStore.getSchema('amendment')`, also
  unavailable on the local store.
* `GlobalSearch.vue` already calls `objectStore.fetchCollection(type, …)`
  against the local store — which it doesn't define — meaning global
  search has been broken on `development`.

Project memory captures this as an explicit instruction:

> **Store pattern guidance** — Do not use custom stores; use Options API
> with `createObjectStore`.

The previous incremental fix (`src/components/tabs/useRelationStore.js`)
already migrated the relation tabs to the lib store; the rest of the
app must follow.

## What Changes

* **Replace** the custom `useObjectStore` (`src/store/modules/object.js`)
  with `createObjectStore('decidesk-objects', { plugins: [liveUpdatesPlugin()] })`.
* **Drop** `src/store/modules/meetings.js`, `minutes.js`, `decisions.js`,
  `actionItems.js` — all four are dead code (no consumer imports them).
* **Keep** the decidesk-specific `useSettingsStore` (`src/store/modules/settings.js`)
  as-is; it talks to `/apps/decidesk/api/settings`, exposes
  `hasOpenRegisters` and `isAdmin`, and has no lib equivalent.
* **Thin-wrap** `src/store/store.js` — preserve the existing
  `initializeStores()` boot hook and the `useObjectStore` /
  `useSettingsStore` re-exports so consumer imports continue working.
  All object-type registration happens in `initializeStores()` against
  the lib store.
* **Migrate consumer call-sites** that used the local-store-only
  `fetchObjects` to the lib's `fetchCollection`:
  * `LiveMeeting.vue`
  * `AmendmentList.vue`
  * `AgendaBuilder.vue`
  * `VotingRoundPanel.vue`
* **Remove the LiveMeeting polling fallback** — `subscribe()` is now a
  real method on the store, the catch branch becomes unreachable.

This aligns decidesk with the same approach openregister itself uses
(`openregister/src/store/modules/object.js`: `createObjectStore` plus
plugins).

## Impact

* **Affected specs:** `decidesk-store-migration` (new).
* **Affected code:**
  * `src/store/store.js` — rewritten as thin wrapper.
  * `src/store/modules/object.js` — replaced.
  * `src/store/modules/settings.js` — unchanged.
  * `src/store/modules/{meetings,minutes,decisions,actionItems}.js` — deleted.
  * Consumers: `App.vue`, `AdminRoot.vue`, `Settings.vue`, `LiveMeeting.vue`,
    `AmendmentList.vue`, `AgendaBuilder.vue`, `VotingRoundPanel.vue`,
    `GlobalSearch.vue`, and the `useRelationStore` helper that already
    pointed at the lib.
* **Issues closed:** decidesk #162.
