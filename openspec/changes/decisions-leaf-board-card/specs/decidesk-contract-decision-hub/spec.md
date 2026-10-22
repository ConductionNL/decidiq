# decidesk-contract-decision-hub Delta

**Status**: proposed
**Scope**: decidiq
**OpenSpec changes**:

- [decisions-leaf-board-card](../../)

## ADDED Requirements

### Requirement: The decisions leaf draws each decision with its route

The `decidesk-decisions` leaf SHALL draw the host's decisions as a card with a title, a line under it and a "Prepare
decision" action. Each decision SHALL show its drafter's initials, its title, a line with its state, its outcome when
it has one and who drafted it when, a status pill and "Open in decidiq". Its `decision-stage` records SHALL show as a
step bar in route order: a decided or skipped stage done (with when and by whom), the active one current (with whom it
waits on), the rest to come (with their note).

#### Scenario: DqZaakBesluiten

- **GIVEN** a draft Woo decision whose route has Drafted done, Team lead initials active and two stages to come
- **WHEN** the case's Decisions tab opens
- **THEN** the row reads "Concept · ... · opgesteld door Pieter Jansen op 7 okt" and the step bar marks the first step
  done and the second current

### Requirement: The decisions leaf says whether the drafter may take the decision

`GET /api/decisions/{decisionId}/authority` SHALL read the decision under RBAC first and answer 404 when the caller may
not read it. It SHALL then ask `DelegatedAuthorityCheck` whether the decision's owner holds an authority for its
`decisionType`, and return `{ authorised, reason, actor, act, subject, restrictions }`, the covering allocation's
subject and restrictions only when it covers the owner. The leaf SHALL show a notice naming the drafter and the
mandate only when the answer is authorised.

#### Scenario: A mandated drafter

- **GIVEN** an effective allocation that gives pieter the act `woo-decision`, subject "Woo-besluiten"
- **WHEN** the leaf shows a Woo decision pieter drafted
- **THEN** it says "Pieter Jansen mag dit besluit nemen: mandaat Woo-besluiten."
