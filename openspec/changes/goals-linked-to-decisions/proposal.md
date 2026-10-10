---
kind: code
---

# Proposal: goals-linked-to-decisions

## Summary

A decision can name the organisation goal it serves, and the goal page shows everything that works towards it: commitments, action items, planned agenda items and decisions. Progress is counted from those objects as "7 van 12", per kind, and stays current when one of them changes. This follows board DcDoel on the design canvas.

## The row this covers

Source: decidiq `openspec/parity/capabilities.json`.

- **fol-09** Link decisions to the organisation's goals and see progress per goal (specified, partial). Reopened by decision 100 (8 Oct 2026). It was decided no on 28 Sep (gap-decisions.json: no competitor rated yes, no demand row, outside the core area). The board DcDoel draws the goal page, so the missing half is back in scope.

## Why

The goal pages exist (`src/manifest.d/organisation-goals.json`, `/goals` and `/goals/:id`) and commitments, action items and planned agenda items can point at a goal. Three things are missing or wrong, read on development:

1. The `decision` schema has no `goal` property, so a decision cannot say which goal it serves. That is the row's name.
2. The goal's rollups still count retired schemas. `lib/Settings/register.d/66-organisation-goals.json` declares `linkedCommitmentCount` and `settledCommitmentCount` over schema `toezegging`, which `84-commitment-in-plain-words.json` retired (`active: false`) in favour of `governance-commitment`. New commitments never count, so the commitment rate reads 0 on every goal.
3. The rates are `materialise: true` calculations. OpenRegister materialises them when the goal itself is saved (`CalculationOnSaveListener`), not when a linked commitment is settled. The board says "De voortgang telt vanzelf mee"; today it only does once someone edits the goal.

## What changes

1. `decision` gets an optional `goal` reference (fragment `127-goals-linked-to-decisions.json`), picked on the decision form and shown in the decision's data widget.
2. The goal's aggregations count `governance-commitment` and `planned-agenda-item`, and add `linkedDecisionCount`.
3. When a commitment, action item, planned agenda item, child goal or decision that points at a goal is saved or deleted, decidiq re-saves that goal's calculations, so its counts are current.
4. The goal detail page follows the board: a progress card with four counts in "x van y" form, a subgoals table with Subdoel toevoegen, a "Wat aan dit doel bijdraagt" list (Soort, Wat, Termijn, Status) with Alle n bekijken, a Kerngegevens card, a Hoofddoel card, the stepper Concept, Actief, Bereikt, and the tabs Overzicht and Gerelateerd n.

## Out of scope

- Weighting contributions or computing a single percentage. The board says nobody fills in a percentage, and the counts stay counts.
- Goals in other apps (planninq). A goal here is a governance goal of a body.

## Risks

- A listener that re-saves goals could loop when a child goal saves its parent. The refresh writes only calculated fields and skips when they did not change, and the cascade stops at the parent: a single level, as organisation-goals REQ-005 already limits the tree.
- Counting decisions inflates progress for goals with many decisions. Decisions appear in the contribution list and in the count of related objects, not in the progress card, as on the board.
