---
kind: code
depends_on: []
---

# Proposal: bodies-conflict-of-interest-recusal

## Summary

A member can declare a conflict of interest only through the API, and nothing in the voting path reads it. This change lets a member declare a conflict on an agenda item or motion from its page and keeps a recused member out of the vote on it.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### bod-10, declare a conflict of interest on a topic and keep that member out of the vote on it

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Controller/ConflictOfInterestController.php + lib/Service/ConflictOfInterestService.php:126 declare(), :292 recordAction() (recused-from-vote), routes appinfo/routes.php:192-194 POST /api/conflicts; grep '/conflicts' and 'conflict' under src/: no caller; no manifest page for schema conflict-of-interest; getActiveConflicts() (ConflictOfInterestService.php:380) is called only by the controller, not by VoteCastGuard/VoteCastingService/VotingRoundGuard

Matrix note, verbatim:

> A declaration can be stored through the API only; no screen offers it. Nothing in the voting path reads a recusal, so a conflicted member is not kept out of the vote.

No competitor cell is rated `yes`.

## Why

The row is in the core area (bodies). A declaration that the vote ignores gives a false sense of integrity.

## What is built today

- ConflictOfInterestService declare(), recordAction() and getActiveConflicts() behind POST /api/conflicts and PUT /api/conflicts/{id}/action.

## What changes

1. A Declare a conflict of interest action on AgendaItemDetail and MotionDetail opens a dialog with the reason and posts to /api/conflicts.
2. The item and motion pages list active declarations.
3. VoteCastGuard refuses a ballot from a member with an active recusal on the motion or its agenda item, with a message naming the declaration.
4. The quorum and the round totals leave recused members out of the eligible count.

## Out of scope

- Nevenfuncties and gifts registers (interests-and-integrity).
