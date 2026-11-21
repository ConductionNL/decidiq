---
kind: code
depends_on: []
---

# Proposal: followup-implementation-progress

## Summary

After a council adopts a motion or a board takes a decision, somebody has to carry it out, and the council wants to know how far that got. Today decidiq can only show the decision's action items and whether it reached `enacted`. This change adds dated progress updates to any decision, a current implementation status on the decision, a column and filter for it on the Motions and Decisions lists, and a reminder when nobody reported for 90 days.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### fol-10, record how far the implementation of a decision has got

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`. Matrix note: "Implementation progress is only readable from the decision's action item statuses and the enacted state; there is no progress record of its own."

No demand row (origin `competitor`).

Competitor cells rated yes:

- ibabs: "https://support.ibabs.com/docs/stand-van-zaken-veld.md states a progress field that appends dated and timed entries on list items"
- go-raadsinformatie: "https://lta.nu/woerden/T24.114.html shows dated status updates (Update 06-11-2024, Update 11-02-2026) on a commitment"

### mot-13, follow what happened to an adopted motion afterwards

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`. Matrix note: "Commitments and action items tied to the motion can be tracked from its Decision page. There is no motion execution status and MotionDetail shows none of it."

No demand row (origin `code`).

Competitor cells rated yes:

- notubiz: "https://amsterdam.raadsinformatie.nl/modules/6/moties_en_amendementen/view records Datum tussentijdse afdoening, Verwachte datum afdoening, Datum afdoening and afdoening documents per motion; https://www.notubiz.nl/onze-diensten/gekoppelde-modules states the LTA keeps grip on actions from motions"
- ibabs: "https://support.ibabs.com/docs/workflow-op-overzichten.md states motions, toezeggingen and written questions get an owner, deadline, reminders and a completed field to monitor follow-up"
- go-raadsinformatie: "https://lta.nu/woerden/M26.006.html shows adopted motion M26.006 with afdoening, planning and portefeuillehouder, and https://lta.nu/woerden lists motions with their planning; https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._raadsinstrumenten/ monitors progress of council instruments"

## Why

Following a motion after adoption is the council's main control over the college. iBabs keeps a "stand van zaken" field with dated entries, GO. shows dated updates per motion on lta.nu, NotuBiz records interim and final settlement dates. decidiq has the pieces around it (commitments with deadlines, action items with lanes) but not the thing itself.

Two earlier specs named this and neither can be applied now. Archived `2026-05-11-p2-motion-and-voting-core-t3` specified execution lifecycle states on `Motion` and an Uitvoering panel on the motion page; motions have since become decisions (`2026-06-14-unify-decision-supertype`, ADR-005), and nothing of the panel exists. The open `motie-amendement-administratie` specifies an `UitvoeringsUpdate` schema, but it declares a new `Motion` schema beside the one the register holds (ADR-006) and has 0 of 80 tasks done. This change specifies the capability on the current model, on `Decision`, so it serves motions, board resolutions and college decisions alike, and supersedes the execution-tracking part of both.

## What changes

1. A new `ImplementationUpdate` schema: the decision it reports on, the date, a status (not started, on track, delayed, completed, will not be implemented), an optional percentage, the text, and who reported it. Evidence files attach to it through OpenRegister's files.
2. The decision carries `implementationStatus` and `implementationStatusDate`, taken from its newest update.
3. The decision page and the motion page get an Implementation widget: the updates newest first, with Add update for the secretariat.
4. The Motions and Decisions lists get an Implementation column and quick filter.
5. A scheduled notice reminds the secretariat of adopted decisions whose status is not completed and whose last update is older than 90 days.

## Out of scope

- The period report of all motions with their implementation status (matrix row ins-02 is the per-period decisions report, specified in `insight-decisions-report-by-period-and-body`).
- Publishing implementation updates to residents. It can follow through the existing publication path once this exists.
- Commitments (toezeggingen), which keep their own lifecycle and settlement fields.

## Risks

- Two sources of truth for "done": action items and the implementation status. The status is what the reporter says; the widget shows the action item count beside it and does not derive one from the other.
- The decision's `update` rule is admin-only, so the listener that sets the status writes as the system, not as the reporter. The update object itself records who reported.
