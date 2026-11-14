# Design: simple-decision-page

## Where the shape lives

Both pages get their simple shape from an overlay in `src/menu-layout.simple.json`. `src/manifest.json` is not edited. The full structure has no overlay, so its pages cannot drift, and `tests/vitest/simpleDecisionPage.spec.js` asserts they equal the manifest.

## D1. The transitions are `api-call` header actions

Each transition is a declarative action:

```json
{ "id": "decision-open-voting", "type": "api-call", "method": "POST",
  "url": "/apps/decidiq/api/decisions/@objectId/transition",
  "payload": { "action": "openVoting" }, "confirm": true,
  "visibleWhen": { "field": "lifecycle", "op": "eq", "value": "deliberating" } }
```

`primaryActionByStage` names one per state. The library shows the server's own message when a call is refused, and refreshes the page when it succeeds.

A registered handler was considered and rejected. The library calls a `handler` action with its declared arguments only, without the record, so a handler could not know which decision it was on.

The spec reads `DecisionTransitionGuard::TRANSITIONS` and fails when an action is offered in a state the server does not allow it from, or when the server has a transition the header does not offer.

## D2. The step bar is display only

`DecisionStepBar.vue` draws the seven states with `buildTimeline`, the model the Lifecycle block already uses. The library's `stages` widget was not used: it moves a record through OpenRegister's own lifecycle when a stage is clicked, and a decision moves through decidiq's guarded endpoint.

## D3. Tabs, and tabs that hold more than one block

The library's `tabs` widget binds one widget per tab. Four tabs hold several blocks, so a container is needed. `DetailSectionsWidget.vue` is the component the dossiq pilot built (`CaseSectionsWidget`), registered as `detail-sections` in the shared widget catalog with `container: true`.

A section names its widget by `widgetId` in the profile file. `structureProfile.js#inlineSectionWidgets` writes the definition in at build time, because a container inside a tab is not given the list of sibling widgets.

## D4. The six custom blocks

The manifest declares six blocks as `type: "custom"`, resolved through the page's slot map. A tab resolves a widget by type and does not read the slot map. The overlay patches the type of each to the registry component its slot names (`DecisionRouteTab` and so on). The spec asserts the patched type equals the slot's component, so both structures render the same one.

## D5. Checklists

Every checklist item reads a field of the decision schema (base register plus fragments). The spec fails on a field that does not exist.

| State | Checklist |
| --- | --- |
| draft | text, proposer, legal basis |
| proposed | meeting, agenda item |
| deliberating | vote type, vote threshold |
| voting | outcome |
| decided | decision date, effective date |
| enacted | published, publication date |
