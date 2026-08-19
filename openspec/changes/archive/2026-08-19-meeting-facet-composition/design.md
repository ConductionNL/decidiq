# Design: meeting-facet-composition

## Architecture Overview

`MeetingDetail` (`src/manifest.json`, page id `MeetingDetail`) is rendered
by the shared `CnPageRenderer`/`CnDetailPage` (from `@conduction/nextcloud-vue`)
from a declarative `widgets[]` + `layout[]` config, with `slots{}` mapping
any `type: "custom"` widget id to a registry component name resolved via
`src/registry.js`. Five new widgets are appended to that existing page:

| # | Facet | Schema | Ref used | Widget type | Why |
|---|---|---|---|---|---|
| 1 | Oral questions | `mondelinge-vraag` | `targetMeeting` (required) | `object-list` | single-hop, direct FK |
| 2 | Interpellations | `interpellatieverzoek` | `behandeldIn` (optional) | `object-list` | single-hop, direct FK |
| 3 | Proxy authorizations | `proxyAuthorization` | `meeting` (required) | `object-list` | single-hop, direct FK |
| 4 | Kascommissie verklaringen | `kascommissie-verklaring` | `governanceBody` (required) | custom (`MeetingKascommissieTab`) | single-hop FK, but needs a runtime mode gate the declarative widget can't express |
| 5 | Routed incoming documents | `raadsinformatiebrief` + `ingekomen-stuk` | `agendaItem` / `targetAgendaItem` / `listAgendaItem` | custom (`MeetingRoutedDocumentsTab`) | two-hop join (Meeting → its AgendaItems → documents referencing those AgendaItems); no single scalar filter can express it |

Three of five facets need nothing beyond JSON. Two need a small Vue file
each — both justified below, per the "only propose a registry component
where the manifest widget genuinely cannot express it" rule.

## Goals / Non-Goals

**Goals**
- Compose all five wave-1-relocated meeting-scoped surfaces onto
  `MeetingDetail`, matching ADR-004 Rule 3.
- Reuse every ref field exactly as declared on its schema — no schema
  edits.
- Prefer the declarative `object-list` manifest primitive wherever the
  data shape allows it (ADR-031 default path).

**Non-Goals**
- Building a generic widget-visibility/mode-gating primitive into
  `CnDetailPage` (the shared library) — out of scope for a decidesk-only
  change; see Decision 3 and DEFERRED_QUESTIONS.
- Changing how oral questions, interpellations, proxy authorizations,
  kascommissie verklaringen, or incoming documents are created, admitted,
  or transitioned — this change only adds meeting-scoped *views* (plus
  pre-filled create, where the schema's own `required` list already
  implies the meeting at creation time).
- Adding a menu/nav entry — the nav-ceiling gate blocks unplaced entries
  and none of these facets are nav items; they are tabs on an existing
  page.

## Decisions

### Decision 1: Edit `src/manifest.json` directly — do not add a `manifest.d` fragment for `MeetingDetail`

**Chosen:** All five widgets (three declarative, two custom) are added by
editing `MeetingDetail`'s `widgets[]`, `layout[]`, and `slots{}` arrays
directly inside `src/manifest.json`.

**Why, with evidence:** `buildManifest()`
(`node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js`,
function `mergePages`) merges a fragment's `pages[]` into the base
manifest **by id, wholesale — "a later declaration REPLACES an earlier one
wholesale"**. It is not a deep merge and does not concatenate
`widgets`/`layout` arrays across fragment and base. If this change added a
new `src/manifest.d/meeting-facet-composition.json` fragment declaring
`{"id": "MeetingDetail", "widgets": [...5 new...], ...}`, `mergePages`
would silently **replace** the live page — which today has 12 widgets
(planning, outcome, two stats-blocks, participants, agenda, decisions,
minutes, files, votes, publication, series, transcription) — with a page
that has only the 5 new ones. Every existing widget on the meeting
workspace would disappear at runtime with no error, no lint failure, and
no test catching it unless something asserts on the full widget count.

This is a real, previously-undocumented risk in the fragment convention:
`manifest.d` fragments are safe for **new** pages (their id doesn't
collide with anything in the base) but unsafe for **extending** a page
that already lives directly in `manifest.json`. `MeetingDetail`'s own
`_note` field already documents two prior rounds of in-place edits
("AUDIT FIX" annotations) — direct editing is the established, safe
pattern for this specific page, not an exception.

**Alternative considered:** Create a fragment and fully re-declare
`MeetingDetail`'s existing 12 widgets plus the 5 new ones. Rejected: it
duplicates ~150 lines of unrelated widget config into an unrelated
fragment, and if any *other* future change also needs a fragment
targeting `MeetingDetail`, the two fragments race — `fragmentCtx.keys().sort()`
determines load order, and `mergePages`'s last-declaration-wins semantics
mean whichever fragment's filename sorts last silently drops the other's
widgets. Editing the single source of truth avoids the race entirely.

### Decision 2: Interpellations facet is browse-only; oral questions and proxy authorizations get add-in-context

**Chosen:** `mondelinge-vraag.targetMeeting` and `proxyAuthorization.meeting`
are both in their schema's `required` array — an object of either type
cannot exist without a target meeting, so pre-filling the current meeting
when creating one from `MeetingDetail` is not just convenient, it mirrors
how the object is actually authored. `interpellatieverzoek.behandeldIn` is
**not** required — only `governanceBody` is — meaning an interpellation
request is filed against the governance body first (by the requester,
before any meeting is chosen) and only gets `behandeldIn` set later, when
the griffie schedules it. Offering a "create interpellation" button
pre-filled with `behandeldIn = <this meeting>` on `MeetingDetail` would
imply the meeting is chosen at submission time, which contradicts the
schema and the domain (Gemeentewet art. 155 interpellatie procedure: a
raadslid files the request; the council later decides admission and
scheduling). The Interpellations facet on `MeetingDetail` is therefore
browse/review only: it shows requests already scheduled at this meeting.
Creating a new interpellation request stays on the top-level `Interpellaties`
register page.

**Alternative considered — and deferred to the user:** always offer
add-in-context on every relocated facet, for consistency with oral
questions/proxy authorizations. See DEFERRED_QUESTIONS #1.

### Decision 3: Kascommissie mode gate is a local wrapper component, not a shared-library capability

**Chosen:** `MeetingKascommissieTab.vue` is a ~40-line component that
reads `organisatie_modus` from the settings store (the same pattern
`src/App.vue`'s `organisatieModus` computed already uses) and renders the
shared `CnObjectListWidget` (imported from `@conduction/nextcloud-vue`,
already used internally by every declarative `object-list` widget) only
when the mode is `assoc`; otherwise it renders nothing.

**Why a component is genuinely needed here (not a declarative widget):**
searched `CnDetailPage.vue` and `CnPageRenderer` for a widget-level
visibility gate — the shared library supports `visibleWhen` on
`headerActions` and on form-field rules, but **not** on entries in a
page's `widgets[]`/`layout[]` array. There is no `hiddenWhen`/
`requiresMode`/`featureGate` concept for a grid widget anywhere in
`node_modules/@conduction/nextcloud-vue/src/`. Confirmed by search — zero
hits. So "hide, not delete" cannot be expressed as `{"visibleWhen": ...}`
in the manifest today; it needs a component that owns the conditional.

**Why the join itself doesn't need the component:** `KascommissieVerklaring.governanceBody`
is a direct, single-hop `$ref` to `GovernanceBody`, and `Meeting.governanceBody`
is the same field — a plain `filter: {"governanceBody": "@object.governanceBody"}`
(the `@object.<field>` token `CnObjectListWidget` already resolves,
identical to the existing `x-relation-filter": {"governanceBody":
"@object.governanceBody"}` pattern already used on `Post` in
`lib/Settings/decidesk_register.json:1817`) is all the query needs. The
component exists solely to wrap that widget in a mode check, not to
reimplement fetch/create/list logic.

**Alternative considered:** extend `CnDetailPage`/`CnPageRenderer` in
`@conduction/nextcloud-vue` with a generic `visibleWhen` on grid widgets,
mirroring the existing `headerActions` mechanism. Rejected for *this*
change only because it lives in a different repo, out of this change's
"work only in decidesk" scope — not rejected as a bad idea. See
DEFERRED_QUESTIONS #2.

**No create affordance:** `KascommissieVerklaring`'s `required` list is
`['financialYear', 'verdict', 'governanceBody']` — no `agendaItem`, no
meeting-shaped field at all. A verklaring is prepared by the kascommissie
independently of any specific meeting and only later, optionally, gets an
`agendaItem` set when it is placed on an ALV agenda. `allowCreate: false`
on the inner `CnObjectListWidget` content.

### Decision 4: Routed-documents facet does the two-hop join client-side, combined into one table

**Chosen:** `MeetingRoutedDocumentsTab.vue` follows the same
fetch-by-filter pattern already used by `MeetingDecisionsTab.vue`
(`store.fetchCollection(schema, filter)`, via `ensureRelationType`):

1. Fetch this meeting's own `agenda-item` objects: `{meeting: objectId}`.
2. Collect their ids into `agendaItemIds`.
3. Fetch `raadsinformatiebrief` objects whose `agendaItem` is in
   `agendaItemIds`.
4. Fetch `ingekomen-stuk` objects whose `targetAgendaItem` **or**
   `listAgendaItem` is in `agendaItemIds` (`ingekomen-stuk` uses
   `listAgendaItem` for the "en bloc" ingekomen-stukken-lijst hamerstuk
   placement and `targetAgendaItem` when a stuk is merged into a
   substantive agenda item's discussion — both are legitimate "routed to
   this meeting" signals; verified against the seeded data, see Seed Data
   below).
5. Render both result sets in one `CnDataTable`, with a `Type` column
   distinguishing "Raadsinformatiebrief" from "Ingekomen stuk" and each
   row's `rowRoute` pointing at the correct detail page per its own type.

No `filter` on a single OpenRegister query can express "agendaItem is one
of these N ids resolved from a prior query" — that is why this facet
cannot be a declarative `object-list` widget; the primitive's `filter`
only resolves `@objectId`/`@object.<field>`/`@workspace.<key>` tokens
against the *current* page's object, not against a second query's result
set.

**Alternative considered:** two separate single-hop `object-list` widgets
filtered by `directedTo: "@object.governanceBody"` (documents directed to
this meeting's governance body, ignoring agenda routing entirely).
Rejected — `directedTo` scopes to the whole governance body, not this
specific meeting's agenda, which is broader than "routed here" and would
show every incoming document ever sent to the body on every one of its
meetings, defeating the point of a per-meeting facet. The task's own
framing ("routed to this meeting's agenda") requires the agenda-item hop.

Read-only: no create button on this facet — both schemas' documents are
authored via their own top-level register pages (`Raadsinformatiebrieven`,
`IngekomenStukken`), which already own the intake workflow.

## Widget Configurations

### `meeting-vragenuur` (object-list)
```json
{
  "id": "meeting-vragenuur",
  "type": "object-list",
  "title": "Oral questions",
  "icon": "HelpCircleOutline",
  "content": {
    "register": "decidesk",
    "schema": "mondelinge-vraag",
    "filter": { "targetMeeting": "@objectId" },
    "sort": { "field": "sortOrder", "dir": "asc" },
    "columns": [
      { "key": "questionNumber", "label": "Number" },
      { "key": "subject", "label": "Subject" },
      { "key": "politicalGroup", "label": "Fraction" },
      { "key": "lifecycle", "label": "Status", "widget": "badge" }
    ],
    "limit": 25,
    "allowCreate": true,
    "viewAllRoute": "MondelingeVragen",
    "viewAllQuery": { "targetMeeting": "@objectId" },
    "rowRoute": "MondelingeVraagDetail",
    "emptyText": "No oral questions submitted for this meeting yet."
  }
}
```

### `meeting-interpellations` (object-list)
```json
{
  "id": "meeting-interpellations",
  "type": "object-list",
  "title": "Interpellations",
  "icon": "MessageAlertOutline",
  "content": {
    "register": "decidesk",
    "schema": "interpellatieverzoek",
    "filter": { "behandeldIn": "@objectId" },
    "sort": { "field": "requestNumber", "dir": "asc" },
    "columns": [
      { "key": "requestNumber", "label": "Number" },
      { "key": "subject", "label": "Subject" },
      { "key": "politicalGroup", "label": "Fraction" },
      { "key": "lifecycle", "label": "Status", "widget": "badge" }
    ],
    "limit": 25,
    "allowCreate": false,
    "viewAllRoute": "Interpellaties",
    "viewAllQuery": { "behandeldIn": "@objectId" },
    "rowRoute": "InterpellatieverzoekDetail",
    "emptyText": "No interpellation requests scheduled for this meeting."
  }
}
```

### `meeting-proxy-authorizations` (object-list)
```json
{
  "id": "meeting-proxy-authorizations",
  "type": "object-list",
  "title": "Proxy authorizations",
  "icon": "AccountSwitchOutline",
  "content": {
    "register": "decidesk",
    "schema": "proxyAuthorization",
    "filter": { "meeting": "@objectId" },
    "sort": { "field": "signedAt", "dir": "desc" },
    "columns": [
      { "key": "signatureStatus", "label": "Signature", "widget": "badge" },
      { "key": "countersignStatus", "label": "Countersign", "widget": "badge" },
      { "key": "signedAt", "label": "Signed" }
    ],
    "limit": 25,
    "allowCreate": true,
    "viewAllRoute": "ProxyAuthorizations",
    "viewAllQuery": { "meeting": "@objectId" },
    "rowRoute": "ProxyAuthorizationDetail",
    "emptyText": "No proxy authorizations registered for this meeting yet."
  }
}
```

### `meeting-kascommissie` (custom → `MeetingKascommissieTab`)
Manifest entry:
```json
{ "id": "meeting-kascommissie", "type": "custom", "component": "MeetingKascommissieTab", "title": "Kascommissie", "icon": "ClipboardCheckOutline" }
```
Inner `CnObjectListWidget` content (passed by the wrapper, not the manifest):
```json
{
  "register": "decidesk",
  "schema": "kascommissie-verklaring",
  "filter": { "governanceBody": "@object.governanceBody" },
  "sort": { "field": "financialYear", "dir": "desc" },
  "columns": [
    { "key": "financialYear", "label": "Financial year" },
    { "key": "verdict", "label": "Verdict", "widget": "badge" }
  ],
  "limit": 10,
  "allowCreate": false,
  "viewAllRoute": "KascommissieVerklaringen",
  "viewAllQuery": { "governanceBody": "@object.governanceBody" },
  "rowRoute": "KascommissieVerklaringDetail",
  "emptyText": "No kascommissie statements for this association yet."
}
```

### `meeting-routed-documents` (custom → `MeetingRoutedDocumentsTab`)
Manifest entry:
```json
{ "id": "meeting-routed-documents", "type": "custom", "component": "MeetingRoutedDocumentsTab", "title": "Incoming documents", "icon": "EmailArrowRightOutline" }
```
Read-only `CnDataTable` fed by the two-hop fetch described in Decision 4.
Columns: Type (Raadsinformatiebrief / Ingekomen stuk), title/subject,
category, lifecycle (badge). Row click routes to
`RaadsinformatiebriefDetail` or `IngekomenStukDetail` per row type.

## Layout

Appended below the existing grid (which ends at `gridY: 31` + `gridHeight: 4`
for `meeting-series`/`meeting-transcription`, so the new rows start at
`gridY: 35`):

| widgetId | gridX | gridY | gridWidth | gridHeight |
|---|---|---|---|---|
| meeting-vragenuur | 0 | 35 | 6 | 5 |
| meeting-interpellations | 6 | 35 | 6 | 5 |
| meeting-proxy-authorizations | 0 | 40 | 6 | 5 |
| meeting-kascommissie | 6 | 40 | 6 | 5 |
| meeting-routed-documents | 0 | 45 | 12 | 5 |

`slots` additions:
```json
"widget-meeting-kascommissie": "MeetingKascommissieTab",
"widget-meeting-routed-documents": "MeetingRoutedDocumentsTab"
```
(Both new keys are added to `MeetingDetail`'s existing `slots{}` map — the
three `object-list` widgets need no slot entry, matching how `body-meetings`
and the other existing `object-list` widgets in this codebase are wired.)

## Nextcloud Integration
- Controllers: none — pure frontend, no new PHP.
- Services: none.
- Mappers/Entities: none — reads go through the existing OpenRegister
  object API via `CnObjectListWidget`'s built-in fetch and
  `useObjectStore`/`ensureRelationType` (already used by sibling tabs).
- Events/Hooks: none.

## Security Considerations

No new endpoints. All five facets read through the existing OpenRegister
object API, which already enforces RBAC per object/schema — a user who
cannot read `mondelinge-vraag` objects will not see them here regardless
of this widget's filter (the filter narrows scope, it does not grant
access). The kascommissie mode gate is a display-only concern (hides an
otherwise-permitted view for tenants where it is not relevant); it is not
a security boundary and MUST NOT be treated as an authorization control —
a user who directly navigates to `/kascommissie-verklaringen/:id` (the
top-level register) still sees the object if the underlying RBAC permits
it, in every mode. This is intentional: hiding a facet from the meeting
workspace does not mean the data itself is confidential.

## NL Design System

All new widgets use existing NL Design System-themed shared components
(`CnObjectListWidget`, `CnDataTable`, `CnStatusBadge` for lifecycle/verdict/
signature badges) — no new CSS tokens, no hardcoded colors.

## File Structure

```
src/
  manifest.json                              (MODIFIED — MeetingDetail widgets/layout/slots)
  registry.js                                (MODIFIED — 2 new imports/registrations)
  components/tabs/
    MeetingKascommissieTab.vue                (NEW)
    MeetingRoutedDocumentsTab.vue              (NEW)
```

## Seed Data

No new schemas or seed objects are introduced by this change — it
composes existing data. Confirmed against the seed data already present
in `lib/Settings/register.d/` so the facets are demoable on install
without any new seeding work:

| Facet | Demoable today on `raadsvergadering-2025-01-15` (seeded `gov`-mode meeting)? | Evidence |
|---|---|---|
| Oral questions | Yes — 3 `mondelinge-vraag` seed objects target this meeting (`mv-wachtlijsten-jeugdzorg`, `mv-sluiting-zwembad`, `mv-opvang-statushouders`) | `lib/Settings/register.d/49-vragenuur-interpellatie.json` |
| Interpellations | Partial — seed objects exist (`int-veiligheid-stationsgebied` etc.) but seed data does not set `behandeldIn` to this specific meeting for any of them; the facet renders correctly empty | `lib/Settings/register.d/49-vragenuur-interpellatie.json` |
| Proxy authorizations | Yes — 2 of 3 seeded `proxyAuthorization` objects (`machtiging-vandam-begroting`, `machtiging-devries-ingetrokken`) target this meeting | `lib/Settings/register.d/63-member-proxy-authorization.json` |
| Kascommissie | No — both seeded `kascommissie-verklaring` objects use the nil-UUID placeholder for `governanceBody` (the real VvE governance body is a not-yet-seeded dependency of `vve-alv-pack`); correctly invisible anyway since the seeded meeting is `gov` mode | `lib/Settings/register.d/57-vve-alv-pack.json` |
| Routed documents | Yes — 3 of 4 seeded `ingekomen-stuk` objects carry `listAgendaItem: "lijst-ingekomen-stukken-2025-01-15"`, an agenda item that belongs to this meeting; 2 of 3 seeded `raadsinformatiebrief` objects carry `agendaItem: "lijst-ingekomen-stukken-2025-01-15"` too | `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json`, `lib/Settings/register.d/51-raadsinformatiebrieven.json` |

No `_registers.json` additions are needed for this change.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path chosen | Rationale |
|---|---|---|
| Oral questions list + scoped create | Declarative (`object-list` widget) | Single-hop FK filter, `@objectId` token, standard create-with-prefill — exactly what the primitive was built for |
| Interpellations list | Declarative (`object-list` widget) | Single-hop FK filter; `allowCreate: false` is itself declarative (a content flag, not code) |
| Proxy authorizations list + scoped create | Declarative (`object-list` widget) | Same shape as oral questions |
| Kascommissie mode-gated visibility | Imperative (thin wrapper component) | ADR-031 exception category "lifecycle guard" applies loosely here — more precisely, no declarative primitive exists for widget-level visibility gating (`visibleWhen` is scoped to `headerActions`/form fields only in the current shared library, verified by source search); the join itself stays declarative (delegated to `CnObjectListWidget` inside the wrapper) |
| Routed-documents two-hop join | Imperative (component doing 2 sequential fetches) | ADR-031 exception category — no declarative primitive can filter by a second query's result-set membership; this is a genuine multi-hop aggregation, not something `x-openregister-aggregations`/`x-openregister-relations` on the schema register can express either, since the relation crosses two different consumer schemas (`raadsinformatiebrief`, `ingekomen-stuk`) against one shared intermediate (`agenda-item`) |

Both imperative pieces are the smallest possible wrapper around the
already-declarative `object-list`/store-fetch primitives — neither
reimplements fetch, pagination, or create-dialog logic.

## Risks / Trade-offs

- [Risk] A `manifest.d` fragment race on `MeetingDetail` in some future
  change → Mitigation: Decision 1 (edit `manifest.json` directly);
  documented in proposal.md Risk 1 for visibility to future changes.
- [Risk] The kascommissie mode gate is invisible to `check:manifest`/
  `check:nav-ceiling` (both validate structure, not runtime visibility
  logic) → Mitigation: covered by a dedicated Playwright/vitest assertion
  in tasks.md, not just manifest validation.
- [Trade-off] The routed-documents facet does 3 sequential network fetches
  (agenda items, then two document schemas) instead of 1 — accepted given
  the meeting's own agenda-item count is small (~5-30 typically) and this
  is a per-page-load cost, not a per-row cost.

## Migration Plan

Not applicable — no schema, database, or backend changes. Frontend-only
deploy; rollback is a plain revert of the manifest/component/registry
changes (see proposal.md Rollback Strategy).

## Open Questions

See DEFERRED_QUESTIONS, returned separately by the generation agent.

## Trade-offs

See "Risks / Trade-offs" above.
