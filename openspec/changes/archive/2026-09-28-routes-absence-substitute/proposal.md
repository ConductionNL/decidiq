---
kind: code
depends_on: []
---

# Proposal: routes-absence-substitute

## Summary

A review can bring in a named substitute part way through a step, but there is no absence period that hands approvals over while someone is away. This change lets a user set an absence period and substitute in their settings; during it, new approval steps go to the substitute directly.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### rou-16, set a substitute who approves in someone's place while they are away

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `tender`, originUrl https://www.tenderned.nl/aankondigingen/overzicht/408309.

Matrix evidence, verbatim:

> lib/Service/ApprovalActorResolver.php:80 rule substitute-of-actor and :299 substituteOf() read the substitute from the organisation record; lib/Service/StageLapsePolicy.php:305 shouldAskSubstitute() asks the substitute part-way through a step's window, both may act; lib/Service/ApprovalStageLapseService.php:168 askSubstituteIfDue() in the lapse sweep, which tells the substitute and the original actor through NotificationPreferenceService::dispatch() (event type approval-stage-lapse is always on, #1395); src/integrations/CnApprovalChainWidget.vue:569 startReview() starts a route from named people on any host object's Parafering card with 'Also ask the substitute' (halfway or three quarters through each step), sent as askSubstituteAfter through src/integrations/approvalChainLink.js:323 holdRoute() and carried onto every stage by lib/Controller/ApprovalRouteController.php:164 and lib/Service/ApprovalRouteService.php:178 (#1397); lib/Service/NotificationPreferenceService.php:53-55 delegate/delegationFrom/delegationUntil forwards notifications only

Matrix note, verbatim:

> A review started from the Parafering card can bring in each approver's substitute, named on the organisation record, part way through the step, and whoever acts first closes the step. There is no absence period that hands approvals over while someone is away.

No competitor cell is rated `yes`.

## Why

A tender demand row (TenderNed 408309) asks for it.

## What is built today

- ApprovalActorResolver substitute-of-actor rule, StageLapsePolicy asking the substitute part way, 'Also ask the substitute' on the Parafering card; notification delegation.

## What changes

1. User settings get Away from, Away until and Substitute (a user).
2. ApprovalActorResolver resolves an actor who is away on the step's start to their substitute, and records on the step that it acts on behalf of the original.
3. The original actor is told which approvals went to the substitute.

## Out of scope

- Organisation-wide absence from a HR system.
