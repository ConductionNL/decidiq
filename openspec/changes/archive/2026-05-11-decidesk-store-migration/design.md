# Decidesk store migration — design

## Migration pattern: hybrid (thin-wrap + dead-code removal)

Three patterns were considered (per the task brief):

1. **Drop the local store entirely** — every consumer imports directly
   from `@conduction/nextcloud-vue`.
2. **Thin-wrap** — `src/store/store.js` re-exports the lib's
   `useObjectStore` plus the local `useSettingsStore`.
3. **Hybrid** — thin-wrap for shared code, plus drop modules that have
   no consumers.

**Choice: hybrid (option 3).**

Reasoning:

* Eight Vue files import from `'../store/store.js'` (`useObjectStore`,
  `useSettingsStore`) or call `initializeStores()`. Keeping the wrapper
  means the diff stays surgical — consumers don't move to a new import
  path; they just start getting the lib store underneath.
* `useSettingsStore` is genuinely decidesk-specific (talks to
  `/apps/decidesk/api/settings`, returns `{ register, openregisters,
  isAdmin, … }`). It's the channel for the **register slug** that
  `initializeStores()` feeds into the lib store's `registerObjectType`
  calls. Replacing it with the lib's register/schema CRUD store would
  conflate two different concerns.
* Four sibling modules (`meetings.js`, `minutes.js`, `decisions.js`,
  `actionItems.js`) have **zero consumers** outside the
  `useObjectStore` re-export inside `store.js` itself. Keeping them
  would be net negative: ongoing maintenance for code that no Vue file
  imports.
* `liveUpdatesPlugin` must be wired in at `createObjectStore` definition
  time — it cannot be added per-consumer. Centralising the lib-store
  factory call in `store.js` is the right home for that wiring.

## Local-store features preserved by the lib store

| Feature                    | Local store                            | Lib store                                  |
|----------------------------|----------------------------------------|--------------------------------------------|
| `configure({ baseUrl })`   | sets state                             | `configure({ baseUrl })` — equivalent      |
| `registerObjectType(t,s,r)`| stores `{schema, register}`            | same signature, plus optional slug hints   |
| `fetchObjects(t, params)`  | returns `{ results, total }`-like body | replaced by `fetchCollection(t, params)`   |
| `loading[type]`            | boolean                                | `isLoading(type)` getter; same data        |
| `objectTypes`              | object map                             | `objectTypeRegistry` (used by `useRelationStore.js`) |
| `subscribe / unsubscribe`  | NOT provided (try/catch fallback)      | provided by `liveUpdatesPlugin`            |
| `fetchObject(type, id)`    | NOT provided                           | provided                                   |
| `saveObject(type, data)`   | NOT provided                           | provided                                   |
| `getSchema(type)`          | NOT provided                           | provided                                   |
| `fetchCollection(type)`    | NOT provided                           | provided                                   |

No local-store feature needs a custom plugin — every state field and
action consumed by current call-sites is satisfied by the lib's defaults
plus `liveUpdatesPlugin`.

## Per-file consumer migration

| File                                                        | Action                                                                                                                                       |
|-------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `src/store/store.js`                                        | Rewrite as thin wrapper around the lib store; keep `initializeStores()` and named re-exports.                                               |
| `src/store/modules/object.js`                               | **Delete.**                                                                                                                                  |
| `src/store/modules/meetings.js`                             | **Delete** — no consumers.                                                                                                                   |
| `src/store/modules/minutes.js`                              | **Delete** — no consumers.                                                                                                                   |
| `src/store/modules/decisions.js`                            | **Delete** — no consumers.                                                                                                                   |
| `src/store/modules/actionItems.js`                          | **Delete** — no consumers.                                                                                                                   |
| `src/store/modules/settings.js`                             | Unchanged.                                                                                                                                   |
| `src/App.vue`                                               | No change — calls `initializeStores()`.                                                                                                      |
| `src/views/settings/AdminRoot.vue`                          | No change — calls `initializeStores()`.                                                                                                      |
| `src/views/settings/Settings.vue`                           | No change — already imports `useSettingsStore` from the modules path.                                                                        |
| `src/views/LiveMeeting.vue`                                 | Replace `fetchObjects` (3 sites) with `fetchCollection`. Drop the dead `try/catch` polling fallback now that `subscribe` is real.            |
| `src/components/AmendmentList.vue`                          | Replace `fetchObjects` with `fetchCollection`; the call already destructures `.results` so the shape matches.                                 |
| `src/components/AgendaBuilder.vue`                          | Replace `fetchObjects` with `fetchCollection` (1 site); other calls (`saveObject`) already use lib semantics.                                |
| `src/components/VotingRoundPanel.vue`                       | Replace `fetchObjects` (2 sites) with `fetchCollection`. The current code awaits a `.results` property on the return value; the lib's `fetchCollection` resolves to the array directly, so the call-site simplifies. |
| `src/components/GlobalSearch.vue`                           | No change — already uses `fetchCollection`.                                                                                                  |
| `src/components/tabs/useRelationStore.js`                   | No change — already imports the lib's `useObjectStore`.                                                                                      |
| `src/components/tabs/*.vue` (Meeting/Motion/GovernanceBody/Decision sub-tabs)| No change — they go through `ensureRelationType()` which already routes to the lib.                                                          |

## Object-type registration map

`initializeStores()` keeps the existing register/schema mapping but
calls the lib store's `registerObjectType`. The schema slug source of
truth remains the decidesk settings response, so dynamic schema renaming
(e.g. `settings.meetingSchema = 'meetings-v2'`) continues to work.

```js
objectStore.registerObjectType('minutes',     settings.minutesSchema     || 'minutes',     register)
objectStore.registerObjectType('decision',    settings.decisionSchema    || 'decision',    register)
objectStore.registerObjectType('action-item', settings.actionItemSchema  || 'action-item', register)
objectStore.registerObjectType('meeting',     settings.meetingSchema     || 'meeting',     register)
objectStore.registerObjectType('agenda-item', settings.agendaItemSchema  || 'agenda-item', register)
objectStore.registerObjectType('participant', settings.participantSchema || 'participant', register)
objectStore.registerObjectType('motion',      settings.motionSchema      || 'motion',      register)
objectStore.registerObjectType('amendment',   settings.amendmentSchema   || 'amendment',   register)
objectStore.registerObjectType('voting-round',settings.votingRoundSchema || 'voting-round',register)
```

The pre-migration code only registered `minutes`, `decision`, and
`action-item`. The new init also registers the meeting/agenda-item/
participant/motion/amendment/voting-round types that LiveMeeting,
AgendaBuilder, GlobalSearch, AmendmentList and VotingRoundPanel actually
use — eliminating the silent "type not registered" failure that was
hiding behind the custom store's "warn-and-return-empty" path.

## Live updates

`createObjectStore('decidesk-objects', { plugins: [liveUpdatesPlugin()] })`
is the only difference from openregister's setup that matters for
LiveMeeting; decidesk does not need files / audit-trails / relations /
search plugins yet, so we leave them off and add later when the
detail-page sub-resource tabs need them.

The store ID `'decidesk-objects'` is distinct from the lib's default
(`'conduction-objects'`) so that a future change which mounts both
stores in the same Pinia tree (e.g. an embedded openregister sidebar)
can't accidentally collide.

## Risk

* Low — most consumers already expected the lib semantics.
* `useRelationStore.js` already pointed at the lib's default
  `useObjectStore` (the `'conduction-objects'` Pinia store ID). After
  this change, `initializeStores()` will register the same object types
  on a **different** store (`'decidesk-objects'`). To keep
  `useRelationStore.js` working, this change updates the import there
  too so all callers share one Pinia store.
