---
kind: code
depends_on: []
---

# Proposal: motions-submission-window

## Summary

A meeting can already say until when motions and amendments may be submitted, and decidiq refuses later ones. It cannot say from when. Councils and associations open submission for a set period, for example from the moment the agenda is sent until 24 hours before the meeting. This change adds the opening time, refuses submissions before it, and shows the whole window on the meeting and to the member who tries too early.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### mot-18, set a period in which motions or amendments may be submitted, and block submissions outside it

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`. Matrix note: "Each meeting can have a closing time for motions and amendments, and later submissions are refused. There is no opening time, so the window is not a full period."

Demand: origin `featureRequest`, https://github.com/OpenSlides/openslides-client/issues/1913

No competitor rated yes.

## Why

The demand row is a feature request from OpenSlides users (openslides-client#1913) for a submission time limit model, which OpenSlides does not have. decidiq has half of it: `lib/Listener/SubmissionDeadlineListener.php` rejects a motion or amendment created after the meeting's `submissionDeadline`. Questions already carry a full window (`AgendaItemType.submissionWindowHours`, `questions-as-agenda-items`). Motions should read the same way.

## What changes

1. A meeting gets `submissionOpensAt` beside `submissionDeadline`.
2. The listener that enforces the deadline also refuses a motion or amendment created before the opening time, with a message that says when submission opens.
3. The meeting page's Planning widget shows both times, and a meeting whose opening time lies after its deadline is refused on save.

## Out of scope

- A window per meeting type as a default. Meeting type defaults are not applied at all yet (matrix row pla-11, state building); once they are, the two fields can be defaults like the others.
- Drafts that are not submitted. A motion is submitted when it is created, as it is today.

## Risks

- A member preparing early is refused. The message names the opening time, and the motion text can be kept in Files or a note until then; a clerk can always create it outside the window by clearing the opening time.
