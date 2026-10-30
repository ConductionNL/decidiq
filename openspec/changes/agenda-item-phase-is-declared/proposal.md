---
kind: code
---

# Proposal: agenda-item-phase-is-declared

## Summary

The chair already moves a discussion or decision item through forming a picture, forming an opinion and deciding (beeldvorming, oordeelsvorming, besluitvorming) on the live meeting screen. The phase is written to an `status` property that the `AgendaItem` schema does not declare, and only the live screen shows it. This change declares the phase on the schema and shows it on the agenda item page, as the boards draw it.

## Motivation

`AgendaService::advanceBobPhase()` patches `status` on the agenda item, but `lib/Settings/decidesk_register.json` and every `register.d` fragment leave `status` out of `AgendaItem`. Whether OpenRegister keeps an undeclared property decides whether the phase ever advances past its first step, and no list, filter or facet can use it. REQ-LIV-002 (`specs/agenda-live-management`) promises the phase on the agenda item detail as well; only `src/views/LiveMeeting.vue` draws it. The live screen is reachable from the meeting page since REQ-AMP-004.

## Scope

### In scope

1. `AgendaItem.phase`: a declared, facetable enum (`voorstel`, `beeldvorming`, `oordeelsvorming`, `besluitvorming`, `completed`), with a one-time move of existing `status` values.
2. `advanceBobPhase()` reads and writes `phase`.
3. The agenda item page shows the phase as a stage line, as DcAgendapunt draws it.

### Out of scope

- New phases or a configurable phase list per body. The three-phase model is the shipped default; a body that works differently is configuration for a later change.

## Matrix rows

- age-08: Track an item through the phases of forming a picture, forming an opinion and deciding.
