---
status: done
---

# Decidesk store migration — spec

## Purpose

Decidesk MUST share the OpenRegister object store provided by
`@conduction/nextcloud-vue` (`useObjectStore` /
`createObjectStore`) for all Pinia-backed CRUD against OpenRegister
objects. Decidesk MUST NOT ship a parallel custom store with a
divergent API.

## Requirements

### REQ-DSM-1 Single shared object store

Decidesk MUST instantiate exactly one object store via
`createObjectStore(id, { plugins })` from `@conduction/nextcloud-vue`,
with Pinia store id `'decidesk-objects'`. All Vue components and
helpers MUST reach the same store instance through `useObjectStore()`
(re-exported from `src/store/store.js`).

#### Scenario: One store id

- **GIVEN** the decidesk frontend bundle
- **WHEN** the bundle is loaded in the browser
- **THEN** Pinia MUST have exactly one store registered with id
  `'decidesk-objects'`
- **AND** there MUST NOT be a Pinia store registered with id `'object'`
  (the previous local-store id).

### REQ-DSM-2 Live updates plugin enabled

The shared store MUST be created with `liveUpdatesPlugin()` so that
`subscribe(type, id?, opts?)` and `unsubscribe(handle)` are callable
on the store without `try/catch` fallbacks.

#### Scenario: Subscribe is a real method

- **GIVEN** a Vue component obtains the store via `useObjectStore()`
- **WHEN** it calls `store.subscribe('meeting', meetingId)`
- **THEN** the call MUST NOT throw "is not a function"
- **AND** the returned handle MUST be acceptable to
  `store.unsubscribe(handle)`.

### REQ-DSM-3 Object types registered at boot

`initializeStores()` MUST call `registerObjectType(slug, schema,
register)` on the lib store for **every** logical type that consumer
components subscribe to or fetch, populating slug values from the
decidesk settings response. The required minimum set is:

`minutes`, `decision`, `action-item`, `meeting`, `agenda-item`,
`participant`, `motion`, `amendment`, `voting-round`.

#### Scenario: Type registration covers consumers

- **GIVEN** `initializeStores()` has resolved
- **WHEN** `objectStore.objectTypes` is read
- **THEN** the array MUST contain each of the 9 logical types listed
  above.

### REQ-DSM-4 Consumers use lib API

All Vue files that fetch collections MUST call
`objectStore.fetchCollection(type, params)`, NOT a non-existent
`fetchObjects` shim.

#### Scenario: No fetchObjects calls

- **GIVEN** the decidesk source tree under `src/`
- **WHEN** searched for the substring `objectStore.fetchObjects`
  or `this.objectStore.fetchObjects`
- **THEN** no matches MUST be found.

### REQ-DSM-5 Settings store preserved

The decidesk-specific `useSettingsStore` (`src/store/modules/settings.js`) MUST remain in place, since it talks
to `/apps/decidesk/api/settings` and exposes
`{ register, openregisters, isAdmin, … }` used to wire the lib store
and to gate admin-only UI.

#### Scenario: Settings store still importable

- **GIVEN** `src/store/store.js`
- **WHEN** a component imports `{ useSettingsStore } from '../store/store.js'`
- **THEN** the import MUST resolve and return the same Pinia store as
  `import { useSettingsStore } from '../store/modules/settings.js'`.

### REQ-DSM-6 Dead modules removed

The four orphan store modules `src/store/modules/{meetings,minutes,decisions,actionItems}.js` MUST be removed from the source tree, since
no Vue file imports them and the canonical CRUD now goes through the
lib store.

#### Scenario: No orphan store modules

- **GIVEN** the decidesk source tree
- **WHEN** the directory `src/store/modules/` is listed
- **THEN** it MUST contain only `settings.js`.

### REQ-DSM-7 LiveMeeting page works

The LiveMeeting view MUST render the meeting title (not the i18n
fallback "Live meeting") for any meeting whose `title` is set in
OpenRegister, and MUST register live-update subscriptions without
falling back to polling.

#### Scenario: Title rendered

- **GIVEN** an OpenRegister meeting with `title = "Q3 Council"`
- **WHEN** `LiveMeeting.vue` mounts for that meeting id
- **THEN** the rendered `<h2>` MUST contain `"Q3 Council"`.

#### Scenario: Subscribe path taken

- **GIVEN** `LiveMeeting.vue` is mounted
- **WHEN** the `created()` hook completes
- **THEN** `liveSubs` MUST contain three subscription handles
- **AND** the `refreshInterval` polling fallback MUST NOT have been
  started.
