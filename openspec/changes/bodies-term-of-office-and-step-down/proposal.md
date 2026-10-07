---
kind: code
depends_on: []
---

# Proposal: bodies-term-of-office-and-step-down

## Summary

Show on a body's page whose term ends when, soonest first, for members and for position holders together, with whether each can be reappointed. Let the secretary enter a member's term when adding them, and remind the secretary before a term ends. The schedule is computed from `Membership` and `PositionHold`; nothing new is stored.

## The row this covers

Source: `openspec/parity/capabilities.json`.

- **bod-07** Keep track of each member's term of office and who is due to step down when (building, partial).

The row's note says what is missing: "For position holders the body page shows term number and end date, soonest first, which answers who steps down next. Ordinary memberships carry no start date from the UI and no term view. The retirement schedule (rooster van aftreden) was retired as never built, and nothing computes or reminds."

## Why

A board secretary, an association and a supervisory board all have to know who steps down at the next general meeting and whether that person may stand again. GO Raadsinformatie records an end date per position; Diligent mentions succession planning. decidiq has the data (`PositionHold.startDate`, `endDate`, `termNumber`; `PositionType.termDurationMonths`, `maxConsecutiveTerms`, `reappointable`; `Membership.endDate`) and, since `configurable-types-domain-model` REQ-CTM-008, the rule that the schedule is derived and never stored. What it lacks is the view that derives it, a start date for an ordinary membership from the UI, and a reminder.

## What changes

1. The Leden add and edit form on the body page asks "Termijn begint" and "Termijn eindigt" (board DcOrgaan), stored on `Membership.startDate` and `endDate`.
2. A "Rooster van aftreden" list under Meer > Functies en integriteit on the body page: every membership and position hold of the body with an end date in the chosen window, soonest first, with term number and "Herbenoembaar" (yes, no, or last term).
3. A declared notification (ADR-031) to the body's secretary a configurable number of days before a term ends, default 90.
4. A membership whose end date has passed shows under "Oud-leden", as the board already has it. Nothing is written when a term ends.

## Out of scope

- Ending a membership automatically. The offboarding traject (`member-onboarding`) stays the way a membership is closed.
- Elections and appointments; vot-06 and vot-23 are decided-no.
- Restoring the retired `RoosterVanAftreden` schemas (`retire-the-unbuilt-rooster`).
- Publishing the schedule on the public site.
