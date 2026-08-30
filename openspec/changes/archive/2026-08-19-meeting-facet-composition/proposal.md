---
kind: code
---

# Proposal: meeting-facet-composition

## Summary

Composes five meeting-scoped facets onto the existing Meeting detail page
(`src/manifest.json`, page `MeetingDetail`) for surfaces that the wave-1 IA
change (`ia-six-clusters`, ADR-004 v2) relocated into the *Meetings*
top-level cluster: oral questions (vragenuur), interpellation requests,
proxy authorizations, kascommissie verklaringen (VvE/association mode
only), and incoming documents/council information letters routed to the
meeting's agenda. Every relation these facets use (`targetMeeting`,
`behandeldIn`, `meeting`, `agendaItem`, `governanceBody`,
`targetAgendaItem`/`listAgendaItem`) already exists on its schema — this
change adds no schema fields. Three of the five facets are pure declarative
`object-list` manifest widgets; two need a narrow registry component
because the manifest widget primitive genuinely cannot express what they
need (mode-gated visibility; a two-hop agenda-item join across two
schemas) — see design.md for the justification of each.

## Motivation

ADR-004 Rule 3 ("Cross-cutting registers live alongside the meeting
workspace") requires that authoring happen in the meeting context while
browsing/searching stays in the dedicated top-level register. Wave 1
(`ia-six-clusters`) moved the *nav entries* for these five surfaces into
the *Meetings* cluster, but did not compose them onto the Meeting detail
page itself — today a griffier who opens a meeting cannot see or manage
its oral questions, interpellations, proxy authorizations, kascommissie
statements, or routed correspondence without leaving the meeting workspace
and searching a separate top-level register by hand. That is exactly the
mental-model break Rule 3 exists to prevent. Without this change the IA
relocation is only half-done: the register/browse door exists, the
meeting/author door does not.

## Affected Projects

- [ ] Project: `decidesk` — `src/manifest.json` (`MeetingDetail` page:
      `widgets`/`layout`/`slots`), two new registry components under
      `src/components/tabs/`, `src/registry.js`, translation strings.

## Scope

### In Scope

- **Oral questions facet** (`mondelinge-vraag`, ref `targetMeeting`,
  required on the schema): declarative `object-list` widget on
  `MeetingDetail`, filtered to the current meeting, with add-in-context
  (the create dialog pre-fills `targetMeeting`).
- **Interpellations facet** (`interpellatieverzoek`, ref `behandeldIn`,
  optional on the schema): declarative `object-list` widget, filtered to
  the current meeting, browse/review only (see design.md Decision 2 for
  why create-in-context is not offered here).
- **Proxy authorizations facet** (`proxyAuthorization`, ref `meeting`,
  required on the schema): declarative `object-list` widget, filtered to
  the current meeting, with add-in-context.
- **Kascommissie verklaringen facet** (`kascommissie-verklaring`, refs
  `agendaItem` + `governanceBody`, both optional/no direct `meeting`
  field): a mode-gated facet, visible only when the tenant's
  `organisatie_modus` is `assoc` — hidden (not deleted) for every other
  mode. Browse only.
- **Routed incoming documents facet** (`raadsinformatiebrief` ref
  `agendaItem`, `ingekomen-stuk` refs `targetAgendaItem`/`listAgendaItem`):
  a single read-only "routed here" widget showing both schemas' objects
  whose agenda-item reference resolves to one of the current meeting's own
  agenda items.
- Registry + manifest wiring for the two facets that need a bespoke
  component (kascommissie mode gate; routed-documents two-hop join).
- Dutch + English translation strings for every new user-facing label.

### Out of Scope

- Any change to the five consumed schemas (`mondelinge-vraag`,
  `interpellatieverzoek`, `proxyAuthorization`, `kascommissie-verklaring`,
  `raadsinformatiebrief`, `ingekomen-stuk`) — every ref this change reads
  already exists; see design.md's schema evidence.
- Any new top-level nav entry — the nav-ceiling gate already enforces the
  six-item ceiling and wave 1 already placed these surfaces under
  *Meetings*; this change only composes their meeting-scoped view.
- A generic, reusable widget-visibility/mode-gating primitive on
  `CnDetailPage` (the shared `@conduction/nextcloud-vue` library) — the
  kascommissie facet's mode gate is implemented locally in decidesk as a
  thin wrapper component (design.md Decision 3); extending the shared
  library is a separate, cross-app decision (see DEFERRED_QUESTIONS).
- Fixing the pre-existing `MeetingDecisionsTab.vue` bug where the tab
  filters `decision` by a `meeting` field the `Decision` schema does not
  declare (observed during this change's research, unrelated component,
  tracked here only as a note for a future fix — not in this change's
  task list).
- Editing via a new `src/manifest.d/*.json` fragment — see design.md
  Decision 1 for why that would silently delete `MeetingDetail`'s existing
  widgets.

## Approach

Pure frontend composition, no backend/PHP changes. Three widgets are added
directly to `MeetingDetail`'s `widgets[]`/`layout[]` arrays in
`src/manifest.json` as `type: "object-list"` entries (register `decidesk`,
schema slug, a single-hop `filter` keyed on the meeting's own object id or
field). Two widgets are added as `type: "custom"` entries wired through
`slots` to two new, narrowly-scoped components in
`src/components/tabs/`, each composing the shared `CnObjectListWidget`
(kascommissie) or the existing store-fetch pattern used by sibling tabs
like `MeetingDecisionsTab` (routed documents, two-hop join). Details,
rationale, and exact widget configs are in design.md.

## New Dependencies

None.

## Impact

- `src/manifest.json` — `MeetingDetail` page gains 5 widgets, 5 layout
  entries, 2 slot bindings.
- `src/registry.js` — 2 new component imports/registrations.
- `src/components/tabs/` — 2 new Vue files.
- `src/l10n/` (or equivalent i18n source) — new English source strings +
  Dutch translations for widget titles, empty-states, and column labels.

## Cross-Project Dependencies

None — decidesk only. The schemas this change reads were introduced by
`vragenuur-interpellatie`, `member-proxy-authorization`, `vve-alv-pack`,
and `toezeggingen-ingekomen-stukken`/`raadsinformatiebrieven`; those
changes' register fragments are already merged into this branch (confirmed
present in `lib/Settings/register.d/`), so no `depends_on` chain is
declared.

## Risks

### Risk 1: A future `manifest.d` fragment overwrites `MeetingDetail` wholesale
**Severity:** Medium — **Mitigation:** `buildManifest`'s `mergePages`
replaces a page by id wholesale (last-declared fragment wins), not a deep
merge. This change edits `src/manifest.json` directly rather than adding a
new fragment for exactly this reason (design.md Decision 1). Documented
here so a future change touching `MeetingDetail` doesn't reintroduce the
fragment approach and silently drop these widgets.

### Risk 2: Kascommissie mode gate is a one-off pattern, not a reusable primitive
**Severity:** Low — **Mitigation:** The wrapper component is small (~40
lines) and isolated to one facet. If a second mode-gated facet is needed
later, promoting the pattern to a shared `CnDetailPage` capability becomes
worth the cross-repo cost; tracked as an open question, not blocking this
change.

## Rollback Strategy

Pure frontend, no data migration. Revert the `src/manifest.json` widget/
layout/slots additions, remove the two new component files and their
`registry.js` entries, and drop the added translation strings. No
OpenRegister objects or schemas are touched, so no data cleanup is needed.

## Open Questions

See DEFERRED_QUESTIONS (returned separately by the generation agent) for
two product judgment calls made under uncertainty: (1) whether
interpellations should also get add-in-context create, and (2) whether the
kascommissie mode gate should stay a local decidesk pattern or become a
shared `CnDetailPage` capability.
