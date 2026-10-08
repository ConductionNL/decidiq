---
kind: code
depends_on:
  - urgent-decision-procedure
---

# Proposal: urgent-decisions-register

## Summary

The urgent decisions list becomes the register board DcSpoedbesluiten draws: per decision who declared the urgency and when it was reported to the body that has to ratify it, whether it still waits for ratification, its lifecycle, and quick filters with counts. The declare flow, the ratification stage and the guard stay in `urgent-decision-procedure`; this change adds what the board shows on top of it.

## The row this covers

Source: decidiq `openspec/parity/capabilities.json`.

- **rou-06** Take an urgent decision outside the normal meeting cycle and have it confirmed afterwards (specified, partial). Reopened by decision 100 (8 Oct 2026). It was decided no on 29 Sep (gap-decisions.json: no competitor rated yes, no demand row, outside the core area). Board DcSpoedbesluiten draws the register, so the procedure is back in scope. Building it means building `urgent-decision-procedure` first and this change after it.

## Why

`src/manifest.d/urgent-decision-procedure.json` already declares the list (`/urgent-decisions`, filter `isUrgent`, quick filters on `awaitingRatification`, columns Decision, Declared, Awaiting ratification, Lifecycle). The fields it reads are declared in `lib/Settings/register.d/46-urgency-policy.json`, but nothing sets them: no PHP under `lib/` handles urgency, no route declares it, and `UrgencyTriggerGuard` named in the schema does not exist. So the list is always empty.

Two things on the board are in neither the list nor `urgent-decision-procedure`:

1. Under each title the board names who declared the urgency as a body or office ("Burgemeester", "College van B en W"), not a Nextcloud user id. `urgencyDeclaredBy` holds a uid.
2. "gemeld aan de raad op 6 okt": the date the urgent decision was reported to the ratifying body. A mayor's emergency ordinance is reported to the council and then ratified; the list should show that the first step happened.

## What changes

1. `decision` gets `urgencyDeclaredByBody` (reference to the governance body, or the office the declarer held) and `reportedToRatifyingBodyAt` (date), in fragment `128-urgent-decisions-register.json`.
2. The urgency declaration of `urgent-decision-procedure` stores the declaring body. A new action "Gemeld aan de raad" on the decision records the report date, and placing the ratification agenda item or linking a raadsinformatiebrief sets it when still empty.
3. The list follows the board: subtitle line, Ja/Nee awaiting-ratification pill, lifecycle pill, quick filters Alle spoedbesluiten, Wacht op bekrachtiging and Bekrachtigd with counts, "n van m spoedbesluiten · nieuwste eerst", Downloaden, view modes and the row action Bekijken.

## Out of scope

- The declaration, the expedited routes and the ratification stage: `urgent-decision-procedure`.
- Kaarten, Bord and Kaart view modes beyond what the shared index page already offers.
