---
kind: code
depends_on:
  - embargo-geheimhouding
  - confidentiality-in-plain-words
---

# Proposal: confidentiality-term-and-lapse

## Summary

A confidentiality restriction records the decision that imposed it and the latest date by which it should be lifted, its page shows the four steps board DcGeheimhouding draws, and it ends on its own in the one case the law ends it: the ratifying body met and did not ratify. When the planned end date comes, the lifting decision is put on the agenda; the system does not lift it, because "Opheffen kan alleen met een besluit van de raad".

## The rows this covers

Source: decidiq `openspec/parity/capabilities.json`. Both reopened by decision 100 (8 Oct 2026).

- **pub-06** Put a confidentiality restriction on a document with its legal ground and end date (specified, partial). Decided no on 29 Sep (single competitor, iBabs; no demand row). The ground is built; the end date is not: `liftingDate` records when a restriction was lifted and `liftingConditions` is free text.
- **pub-07** Lift a confidentiality restriction automatically when it expires (specified, no). Decided no on 29 Sep as a recorded non-goal of `embargo-geheimhouding` (REQ-EMB-003: never auto-lift). The board draws two endings: "Bekrachtigt de raad niet, dan vervalt de geheimhouding" (it lapses by law) and "Opheffen kan alleen met een besluit van de raad" (the end date needs a decision). This change builds the first automatically and the second as an agenda item plus a flag.

## Why

The restriction schema exists (`lib/Settings/register.d/83-confidentiality-in-plain-words.json`: scope, targets, ground, imposedBy, imposedByBody, imposedAt, ratification fields, dissolutionDecision, liftingDate, liftingConditions; lifecycle imposed, ratified, dissolved) with an index and detail page (`src/manifest.d/embargo-geheimhouding.json`) and a three-stage timeline widget. Read against the board:

| On the board | On development |
|---|---|
| Opgelegd with "Collegebesluit 2026-0912" | no link to the imposing decision |
| "Met het raadsvoorstel naar de raad", read only by members in the closed reading folder | no step; the timeline has three stages |
| "Bekrachtigt de raad niet, dan vervalt de geheimhouding" | no lapsed state; REQ-EMB-003 forbids any automatic change |
| Opheffen "Na gunning, uiterlijk 1 juli 2027" | conditions free text, no date |
| Soort "Woo, relatief", Bekrachtiging nodig Ja | `category`, `requiresRatification` on the ground (built) |

## What changes

1. The restriction gets `imposingDecision` (decision reference), `sentToBodyAt` and `sentWithAgendaItem` (when and with which proposal the papers went to the ratifying body), and `liftBy` (date: the latest planned lifting), in fragment `129-confidentiality-term-and-lapse.json`. `liftingConditions` stays for the condition in words.
2. The lifecycle gains `lapsed`: imposed to lapsed, terminal. It is set by the system when the meeting that carried the ratification agenda item is closed and that item records no ratification, and by a clerk recording "niet bekrachtigd". A lapsed restriction no longer keeps its target out of publication, and its target goes to the normal publication check, never straight to public.
3. When `liftBy` is near (a configurable number of days, default 30) and the restriction is still imposed or ratified, the system places a lifting proposal on the next meeting of the body that can lift it and marks the restriction "opheffing voorbereiden"; when `liftBy` passes it shows overdue. It does not lift.
4. The timeline shows the board's four steps: Opgelegd, Met het raadsvoorstel naar de raad, Bekrachtiging (or Vervallen), Opheffen; the header reads "Bekrachtiging door de raad uiterlijk <date>"; the stepper is Opgelegd, Bekrachtigd, Opgeheven.

## Out of scope

- Lifting by date without a decision. If an organisation wants that for a ground that needs no decision, it is a later change with its own row.
- Woo delivery after lifting (integriq, `hand-woo-diwoo-to-integriq`).

## Risks

- **A restriction lapses that legally still stands.** The automatic lapse only fires when the ratification item sat on a meeting that is now closed and recorded no ratification. A meeting closed with the item postponed records "uitgesteld" on the item, and then nothing lapses. The lapse is audit-logged with the meeting and item, and a clerk can undo it within the closing day by recording the ratification decision.
- **The legal reading.** Gemeentewet art. 25 lid 3, 55 lid 3 and 86 lid 3 (art. 87-89 after 2023) end a college- or mayor-imposed secrecy when the council does not ratify it in its next meeting. That is the board's sentence. Other bodies (boards, associations) set `requiresRatification` false on their grounds and never lapse.
