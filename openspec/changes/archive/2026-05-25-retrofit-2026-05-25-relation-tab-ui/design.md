# Design — relation-tab-ui (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new
implementation work — the nine relation-tab components already ship in
`src/components/tabs/`.

## Context

The `CnObjectSidebar` from `@conduction/nextcloud-vue` renders a configurable
set of tabs for a detail object. Decidesk supplies its own custom tab
components (registered in `src/customComponents.js`) so a parent object can
display its related child objects. All tabs receive a single `objectId` prop
(the parent's id) and resolve the child object type's OpenRegister schema slug
through `src/components/tabs/useRelationStore.js::ensureRelationType`, which
reads the slug from the settings store (`settings.<x>Schema`) and lazily
registers the type on the shared `decidesk-objects` store.

## Observed structure (per tab)

- `columns` / `excludedFields` — table definition + form field hiding
- `*Colors` computed — domain value → badge colour map (status semantics)
- `rowActions` / `rowActionsFor` — per-row action menu
- `refresh` — relation-scoped fetch, short-circuits on empty `objectId`
- `openCreate` / `openEdit` / `onConfirm` / `confirmDelete` — CRUD dialogs
- `loadCandidates` / `candidateLabel` / `linkParticipant` — add-existing linking
- `hydrateCasters` / `casterDisplayName` — vote caster name resolution
- `parentMotionId` / `propertyItems` / `openParent` — parent-object viewer
- `signersWithName` / `rawSigners` / `canSignNow` / `addSigner` / `signNow` — minutes signing
- `handler` (watch on `objectId`) — re-runs `refresh()` when the parent changes
- `addDialogOpen` (watch) — loads candidates when the add dialog opens
- `hasMeeting` — membership predicate for the meeting-participants tab

## REQ grouping rationale

Nine components, ~83 methods, collapsed to 5 REQs by posture rather than one
REQ per file: the four full-CRUD tabs share REQ-001; colour/action semantics
are cross-cutting (REQ-002); the two participant-linking tabs share REQ-003;
the two read-only viewers share REQ-004; the minutes signer tab is distinct
(lifecycle transition) so it gets REQ-005.

## Notes / observed-but-flagged

- `MinutesSignersTab.refresh` fetches participants with `_limit: 200` and
  indexes client-side because server-side `id IN (...)` filtering varies across
  OpenRegister versions. Observed, not changed.
- `hydrateCasters` swallows fetch errors and falls back to the raw caster value
  — non-fatal by design.
