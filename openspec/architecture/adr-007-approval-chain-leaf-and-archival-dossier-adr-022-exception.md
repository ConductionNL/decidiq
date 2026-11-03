# ADR-007: The approval-chain leaf and the archival dossier are ADR-022 documented exceptions

- **Status:** Proposed (held for review)
- **Date:** 2026-11-03
- **Sunset:** 2027-06-30
- **Deciders:** Ruben van der Linde
- **Scope:** decidiq, two files that hydra gate 23 matches on their name
- **References:** hydra ADR-022 (apps consume OpenRegister abstractions), exception clause. Hydra gate 23, `or-abstraction-anti-patterns`, rule 3 (`consume-or-approval-workflow-fleet-wide`) and rule 7 (`or-capability:archival-destruction-workflow`). Programme decision 64 (5 October 2026, gate 23 question 7). `openspec/changes/approval-routes-on-flow-user-tasks/`. `openspec/changes/records-management-archiving/design.md`, "What OpenRegister already provides".

## Context

Gate 23 enforces ADR-022 on file names. Two decidiq files match, and both
keep development's Hydra Gates job red:

- `lib/Listener/RegisterApprovalChainLeafListener.php` matches rule 3, any
  `*ApprovalChain*.php` outside OpenRegister. The class holds no approval
  logic. It registers the `decidiq-approval-chain` render leaf through
  OpenRegister's integration registry (`LeafDescriptor`), so a host app can
  show a decision's sign-off route on its own page. The route it shows is
  run by decidiq's approval-route engine (`ApprovalRouteService` and its
  helpers). Those files do not match the gate's name rule, but they are what
  rule 3 is about. Decision 64 already settles them: approval routes move onto
  OpenRegister flows made of `openregister.user-task` nodes, and decidiq's
  engine is removed once no route still runs on it. That work is the change
  `approval-routes-on-flow-user-tasks`, and it waits on OpenRegister.
- `lib/Service/ArchivalDossierService.php` matches rule 7, any
  `Archival*Service.php`. It forms the archival dossier of a meeting, gathers
  its records while the dossier is forming and closes it. OpenRegister has
  no dossier aggregate. Retention, Selectielijst, legal holds, destruction,
  transfer and the certificate all stay OpenRegister's, and decidiq calls
  them rather than reimplementing them. The records-management design lists
  each one with its OpenRegister file.

Renaming either file would quiet the gate and change nothing, so this ADR
records the exception instead.

## Decision

Per the ADR-022 exception clause, gate 23 suppresses each path below under
the one rule named after it, and under no other rule. Every suppression is
printed on every gate run with this ADR named beside it.

- lib/Listener/RegisterApprovalChainLeafListener.php (gate 23 rules: 3)
- lib/Service/ArchivalDossierService.php (gate 23 rules: 7)

## When this ends

- The approval-chain leaf exception ends when `approval-routes-on-flow-user-tasks`
  lands. The leaf then reads the flow run, the engine files are gone, and
  this line is withdrawn in the same change.
- The archival dossier exception holds while OpenRegister has no dossier
  aggregate. If OpenRegister gains one, the dossier moves onto it and this
  line is withdrawn.

The sunset date forces both questions to be asked again by then.
