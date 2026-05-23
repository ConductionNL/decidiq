# Refactor: Decidesk IA Alignment

**Slug:** refactor-decidesk-ia-alignment
**App:** Decidesk
**Date:** 2026-05-22
**Status:** Draft

## Why

A fresh Information Architecture (IA) review of Decidesk compared the
proposed surface placement of each implemented feature against the
runtime topology declared in `src/manifest.json`. Two specs drift from
the proposed IA in ways that affect everyday meeting-secretary
workflows:

1. **`p2-minutes-and-decisions`** — the IA places minutes (Notulen) and
   decisions (Besluiten) **authoring** as tabs on the Meeting detail
   surface, with the top-level Minutes/Decisions pages serving as
   read-side register browsers ("split" placement). Today the app only
   exposes the top-level register pages. A secretary working on a
   meeting must leave the meeting context, open Minutes (or Decisions),
   then create + link back manually. The authoring affordance is
   missing from the place where the proposed IA expects it.

2. **`p2-motion-and-voting`** — the IA places voting outcomes as a
   **Stemmingen tab** on the Meeting detail surface (in addition to the
   top-level Motions page). Today MeetingDetail has no votes tab.
   Voting rounds + cast votes only surface inside the realtime
   `LiveMeeting` view (during the meeting) and on MotionDetail (per
   motion). There is no static per-meeting voting overview after the
   meeting has closed — the secretary cannot scan all rounds + tallies
   for a single meeting without LiveMeeting.

All other implemented or archived specs already land where the IA
places them (see `design.md` for the per-spec audit). This change is
**scoped strictly to the two drifts above**: it adds the missing
sidebar-tab surfaces and re-asserts the split-placement contract in
the per-spec requirements. No top-level routes are removed, no menu
labels are renamed, no schemas are changed.

## What Changes

- **MeetingDetail manifest page** gains two new sidebar tabs:
  - `minutes` (Notulen) — lists + creates Minutes objects linked to the
    current meeting; deep-links to the existing MinutesDetail surface
    for full editing.
  - `decisions` (Besluiten) — lists + creates Decision objects linked
    to the current meeting; deep-links to the existing DecisionDetail
    surface.
  - `votes` (Stemmingen) — read-only post-meeting overview of all
    voting rounds + tallies for the meeting; deep-links to
    MotionDetail > Votes for cast-vote drill-down.
- **Three new custom tab components** in `src/components/tabs/`:
  - `MeetingMinutesTab.vue`
  - `MeetingDecisionsTab.vue`
  - `MeetingVotesTab.vue`
- **`customComponents.js`** registers the three new components.
- **Per-spec requirements** (in the two affected specs) gain
  ADDED Requirements for the new tab surfaces. Existing register-list
  requirements remain unchanged (the split is additive, not migratory).

## What Is NOT Changed

- Menu labels stay English (`Meetings`, `Motions`, `Minutes`,
  `Decisions`). The IA proposal uses Dutch surface names
  (Vergaderingen / Moties / Notulen / Besluiten / Beheer) but
  translation of the active locale is out of scope for an IA refactor.
- Dashboard stays as a top-level menu item (the IA's "Beheer >
  Dashboard" label is interpreted as *the app landing*, which the
  Dashboard top-level route already provides).
- Schemas, registers, lifecycle transitions, MCP tools, LiveMeeting
  realtime behaviour — all untouched.
- The archived `p3-citizen-participation` spec remains archived;
  re-introducing a Fracties & Organen / Burgerparticipatie surface is
  a separate proposal.
- The existing top-level `AgendaItems` register page stays (it is a
  superset of the proposed IA, which only mandates the per-meeting
  Agenda tab — the additional register list is harmless extra surface).

## Impact

- **Affected specs:** `p2-minutes-and-decisions`,
  `p2-motion-and-voting`.
- **Affected code:** `src/manifest.json`, `src/customComponents.js`,
  three new files under `src/components/tabs/`.
- **No backend changes.** All three new tabs read/write the existing
  `minutes`, `decision`, `voting-round`, and `vote` schemas through the
  manifest-driven object store.
- **Migration:** none. Tabs appear additively on existing
  MeetingDetail pages on next reload.
