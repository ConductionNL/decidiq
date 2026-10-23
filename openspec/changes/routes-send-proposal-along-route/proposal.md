---
kind: code
depends_on: []
---

# Proposal: routes-send-proposal-along-route

## Summary

The approval route engine works and approvers can act, but no decidiq screen sends a proposal along a route: it takes the API or another app. This change adds a Send for approval action on the decision page that picks an active approval route for the decision and starts it, and shows the route progress there.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### rou-01, send a proposal along a route of named people who must approve it before it goes to the body

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/ApprovalRouteService.php:354 instantiate() and :483 record(), lib/Service/ApprovalRouteAdvancer.php, ApprovalThresholdCalculator; started via routes.php:58 POST /api/approval-routes/instantiate or lib/Listener/ApprovalRouteRequestedListener.php:84 (registered lib/AppInfo/Registrar/CrossAppEventRegistrar.php:83). Frontend: src/integrations/approvalChainLink.js:237 holdRoute() is exported but called by no component; CnApprovalChainWidget.vue:306 recordAction() lets the actor approve/reject. Inside decidiq, src/components/tabs/DecisionRouteTab.vue is read-only and no decidiq screen starts a route (src/dialogs/DecisionFormDialog.vue has no route step).

Matrix note, verbatim:

> The route engine works and approvers can act through the approval-chain leaf, but no decidiq screen sends a proposal along a route; a route has to be started by another app or the API. I did not find a host app that dispatches the event in the local checkouts.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/goedkeuring.md states a dossier is offered for approval along a route set per agendatype, parallel or sequential, before agendering
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/blogs/tabsign__de_oplossing_voor_het_beoordelen_van_stukken/ states wethouders assess proposals with Tabsign in GO. vergaderen before the secretariat puts them on the agenda, with a workflow set per organisation

## Why

rou-01 is rated yes by ibabs and GO (two competitors).

## What is built today

- ApprovalRouteService instantiate() and record(), POST /api/approval-routes/instantiate and /actions.
- Acting on a step through the approval chain leaf widget.
- DecisionRouteTab shows the decision stage route, read only.

## What changes

1. A Send for approval action on DecisionDetail for the owner, secretary or admin: a dialog lists active approval routes for subjectType decision (default first) and posts instantiate.
2. The decision page shows the approval chain widget for the decision once a route runs; a proposal whose route is not finished cannot be put on an agenda (message names the open step).

## Out of scope

- Routes started from other apps (already possible through the event).
