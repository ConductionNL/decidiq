# Design: Decidesk IA Alignment

**Change:** refactor-decidesk-ia-alignment
**App:** Decidesk
**Audit date:** 2026-05-22

## Audit Result

| spec_slug | status | IA placement (proposed) | Current placement (manifest) | Verdict |
| --- | --- | --- | --- | --- |
| p1-crud-operations | done | INFRA (no menu item) | Cross-cutting; lib + manifest | ALIGNED |
| p1-dashboard-and-navigation | done | SETTING / Beheer > Dashboard + global shell | Top-level `Dashboard` menu (order 10) + Settings page | ALIGNED (IA label "Beheer > Dashboard" interpreted as the app landing surface) |
| p1-schemas-and-data-model | done | SETTING / Beheer | `Settings` page > "Registers" section with `register-mapping` widget | ALIGNED |
| p2-agenda-management | done | DETAIL_TAB / Vergaderingen > detail > Agenda tab | `MeetingDetail` sidebar tab `agenda` → `MeetingAgendaTab` | ALIGNED |
| p2-meeting-management | done | SUB_PAGE / Vergaderingen top | Top-level `Meetings` menu + index + detail | ALIGNED |
| p2-meeting-management-core-t1 | done | SUB_PAGE / Vergaderingen > detail (core) | `MeetingDetail` overview tab + data/metadata widgets | ALIGNED (rolled into parent) |
| p2-meeting-management-other-t1 | archived | SUB_PAGE / Vergaderingen > detail (other) | Merged into MeetingDetail | ALIGNED (archived stub) |
| p2-meeting-management-other-t2 | archived | SUB_PAGE / Vergaderingen > detail (other) | Merged into MeetingDetail | ALIGNED (archived stub) |
| **p2-minutes-and-decisions** | **done** | **DETAIL_TAB / Vergaderingen > Notulen + Besluiten tabs (write); Besluiten (read register) — split** | Top-level `Minutes` + `Decisions` only; **no MeetingDetail tabs** | **DRIFT** |
| p2-minutes-and-decisions-other-t1 | archived | SUB_PAGE / Besluiten > detail (other) | `DecisionDetail` with overview / actionItems / audit tabs | ALIGNED (archived stub) |
| p2-minutes-and-decisions-other-t2 | archived | SUB_PAGE / Besluiten > detail (other) | `DecisionDetail` (as above) | ALIGNED (archived stub) |
| **p2-motion-and-voting** | **done** | **DETAIL_TAB / Moties (top) + Vergaderingen > Stemmingen tab — split** | Top-level `Motions` exists + `MotionDetail` has `votes` tab; **MeetingDetail has no votes tab** | **DRIFT** (Moties top-level aligned; Stemmingen tab on MeetingDetail missing) |
| p2-motion-and-voting-other-t1 | archived | SUB_PAGE / Moties > detail (other) | `MotionDetail` overview / amendments / votes / audit | ALIGNED (archived stub) |
| p2-motion-and-voting-other-t2 | archived | SUB_PAGE / Moties > detail (other) | `MotionDetail` (as above) | ALIGNED (archived stub) |
| p2-motion-and-voting-other-t3 | archived | SUB_PAGE / Moties > detail (other) | `MotionDetail` (as above) | ALIGNED (archived stub) |
| p3-citizen-participation | archived | SETTING / Fracties & Organen > Burgerparticipatie portaal | No Fracties & Organen surface; no Burgerparticipatie portal | UNCERTAIN — spec is archived (intentional descope); no current implementation to drift from. Out of scope for this refactor. |

**Net: 12 ALIGNED · 2 DRIFT · 1 UNCERTAIN (archived).**

## Target IA Topology

The new (post-refactor) Decidesk IA, with drifts resolved:

```
Decidesk (app shell)
|
+-- Dashboard                           (top-level, landing tiles)
|
+-- Meetings (Vergaderingen)            (top-level register)
|   +-- index: meetings list
|   +-- /meetings/:id (detail) — sidebar tabs:
|       +-- overview
|       +-- agenda            (MeetingAgendaTab)         [existing]
|       +-- participants      (MeetingParticipantsTab)   [existing]
|       +-- minutes           (MeetingMinutesTab)        [NEW]
|       +-- decisions         (MeetingDecisionsTab)      [NEW]
|       +-- votes             (MeetingVotesTab)          [NEW]
|       +-- audit
|   +-- /meetings/:id/live   — LiveMeetingView (custom)  [existing]
|   +-- /meetings/:id/integrations — registry sidebar   [existing]
|
+-- Motions (Moties)                    (top-level register)
|   +-- /motions/:id (detail) — overview / amendments / votes / audit
|   +-- /amendments/:id (detail)
|
+-- Minutes (Notulen)                   (top-level read register; existing)
|   +-- /minutes/:id (detail) — overview / signers / audit
|
+-- Decisions (Besluiten)               (top-level read register; existing)
|   +-- /decisions/:id (detail) — overview / actionItems / audit
|
+-- ActionItems / Tasks / Workspaces / Comments / EmailLinks / Engagement
|   (top-level registers; out of scope for this refactor)
|
+-- Beheer (Settings)
    +-- Version
    +-- Registers (schema mapping; p1-schemas-and-data-model)
    +-- Advanced (ORI, email voting toggle)
```

The two new affordances:

1. **Per-meeting authoring tabs** for Notulen + Besluiten. The
   register-side index pages stay as the canonical *browse* surface;
   the tabs are the canonical *create + scope* surface for a single
   meeting. The split mirrors the agenda pattern (per-meeting tab
   authors; top-level register lists across meetings).

2. **Per-meeting Stemmingen tab.** Post-meeting overview of all
   voting rounds + tallies for the meeting, read-only. Live casting
   stays in LiveMeeting; per-motion drill-down stays on MotionDetail
   `votes` tab. The new tab is the meeting-scoped aggregate.

## Component Design

All three new components follow the existing tab pattern
(`MeetingAgendaTab.vue`, `MotionVotesTab.vue` etc.):

- Receive `objectId` and `objectData` props from `CnObjectSidebar`.
- Resolve the relation via the manifest-driven object store (no bespoke
  store).
- Use `CnDataTable`, `CnNoteCard`, `CnStatusBadge` from
  `@conduction/nextcloud-vue`.
- SPDX header in docblock; EUPL-1.2.

### MeetingMinutesTab.vue
- Filter `minutes` by `meeting === objectId` (or the schema's link
  field — verify at implementation time against the minutes schema).
- Show columns: `title`, `lifecycle`, `version`, `approvedAt`.
- Provide "Nieuwe notulen aanmaken" action that pre-fills the
  meeting reference and opens `MinutesDetail` for editing.
- Empty state with one-click create.

### MeetingDecisionsTab.vue
- Filter `decision` by linked meeting (via meeting reference or via
  agenda-item → meeting traversal — pick whichever the schema
  declares as the canonical join).
- Show columns: `title`, `outcome`, `decisionDate`, `isPublished`.
- "Besluit aanmaken" action pre-fills the meeting reference.
- Deep-links to DecisionDetail.

### MeetingVotesTab.vue
- Read-only (consistent with the `votes` tab on MotionDetail).
- Walk meeting → motions → voting-rounds → votes (or the schema's
  direct meeting-link on voting-round if present).
- Group by motion: title, motion type, for/against/abstain tallies,
  round result, timestamp.
- Deep-link each motion row to MotionDetail > Votes tab.
- "Geen stemmingen vastgelegd voor deze vergadering" empty state.

## Why Not Just Rename or Move?

The proposed IA does NOT remove the top-level Minutes / Decisions /
Motions register pages — it *splits* the surface ("split" placement in
the audit input). Moving the register lists into tabs would lose the
cross-meeting browsing affordance that other consumers (audit, search,
publication workflows) rely on. The drift is strictly an **additive**
miss of per-meeting tabs, so the fix is additive too.

## Risks & Tradeoffs

- **Schema-link discovery.** Each new tab needs to know the field
  name linking minutes / decision / voting-round to a meeting. The
  implementation task includes verifying the field names against the
  current schemas before wiring the filters. If a schema lacks a
  direct meeting link (e.g. a decision is only linked via its parent
  agenda-item), the tab must traverse via the agenda relation.
- **Empty/seed environments.** Tabs must render a useful empty state
  rather than failing; covered in the tab-component pattern above.
- **No new permissions.** Tabs reuse the existing register
  read/write permissions; no permission-spec changes.

## Rollback

Removing the three new tabs from `manifest.json` and the three
component registrations from `customComponents.js` fully reverts the
change. No data migration, no schema changes, no API surface.
