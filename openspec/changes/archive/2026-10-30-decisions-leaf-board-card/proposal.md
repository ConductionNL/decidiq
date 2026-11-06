# Proposal: decisions-leaf-board-card

## Why

dossiq's DqZaakBesluiten board draws the decisions on a case as a card: a title and a line under it, "Besluit
voorbereiden", and per decision a row with initials, title, a meta line and a status pill, "Openen in decidiq", the
decision's route as a step bar (Opgesteld, Paraaf teamleider, Ondertekend, Bekendgemaakt) and a notice that the person
who drafted it may take it under the mandate register. The `decidesk-decisions` leaf drew a sidebar list grouped by
kind, with no route and no mandate.

## What changes

- The leaf's tab draws the board card. The route comes from the decision's `decision-stage` records, the people from
  `person`; a part that cannot be read is left out.
- `GET /api/decisions/{decisionId}/authority` answers whether the decision's drafter (its owner) holds an authority
  for its `decisionType` in the delegatie- en mandaatregister, through `DelegatedAuthorityCheck`, which had no caller.
  The decision is read under RBAC first; an unreadable decision answers 404. The covering allocation's subject and
  restrictions come back only when it covers the drafter.

## Scope

The tab's look changes for every host; its data and the create flow are unchanged. One new read-only route.
