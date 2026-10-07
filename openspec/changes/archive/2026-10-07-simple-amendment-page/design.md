# Design: simple-amendment-page

## Where the shape lives

Both pages get their simple shape from an overlay in `src/menu-layout.simple.json`. `src/manifest.json` is not edited. `tests/vitest/simpleAmendmentPage.spec.js` asserts the full structure equals the manifest for both.

## D1. Two `api-call` header actions

```json
{ "id": "amendment-debate", "type": "api-call", "method": "POST",
  "url": "/apps/decidiq/api/amendments/@objectId/transition",
  "payload": { "newState": "deliberating" },
  "visibleWhen": { "field": "lifecycle", "op": "eq", "value": "proposed" } }
```

The spec reads `MotionLifecycleTransitioner::AMENDMENT_TRANSITIONS` and fails when a button names a state the server does not allow from the state it shows in. It also fails when a header action sends `voting`, `decided` or an `outcome`.

## D2. The step bar

`AmendmentStepBar.vue` draws the five states of the server's road. One sentence under it, chosen by `amendmentNote`:

| State | Sentence |
| --- | --- |
| draft, proposed | Only the chair or the secretary can take the next step. |
| deliberating, voting | The vote on this amendment opens and closes under Voting round. |
| decided, adopted | This amendment was adopted. |
| decided, rejected | This amendment was rejected. |

The first sentence stands for every reader. The meeting page asks the server which steps it offers the reader. An amendment has no such endpoint, and adding one is server work this change does not do.

## D3. Tabs

| Tab | Blocks |
| --- | --- |
| Text changes | Text changes |
| Amendment | Amendment, Parent motion |
| Voting round | Voting round |

Four blocks, each in one tab. The three blocks the manifest declares as `custom` are patched to their registry component, as on the decision and the meeting page.

## D4. My actions

```json
{ "id": "ActionItems", "config": { "quickFilters": [
  { "label": "Mine", "filter": { "assignee": "@me" }, "default": true, "showCount": true },
  { "label": "Everyone", "filter": {}, "showCount": true } ] } }
```

`@me` is the library's token for the reader. It is filled in when the list is fetched and when a view is counted, by the same call (`resolveFilterMap`), so a count and its list ask the same question. The spec builds both addresses with the library's own functions and compares them.
