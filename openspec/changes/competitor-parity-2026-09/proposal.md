---
kind: umbrella
depends_on: []
---

# Proposal: competitor-parity-2026-09

## Summary

This is the decidiq half of the dossiq competitor parity programme. Two
sources of record sit behind it, both in ConductionNL/market-intelligence:
the gap register at `procest/_gaps/` (`README.md`, `gap-register.md`,
`gap-register.json`, `ownership-rules.md`, written 2026-09-13) and the
round 4 discovery sweep at `procest/_round4/discovery/` (36 systems read,
636 candidates, 71 capability clusters, written 2026-09-14).

Ruben's ownership rule governs the split. dossiq reaches full
comparability with the competition, and logic that belongs to another app
is specified in that app. dossiq then consumes it. Decidiq is one of those
owner apps, so this umbrella indexes the logic decidiq owes.

Nothing here is implemented. Each indexed change carries its own
`proposal.md`, `design.md`, `specs/` and `tasks.md`.

## Coverage confirmation

The gap register puts one ledger row on decidiq, and it is covered.

| row | capability | covered by | verdict |
|---|---|---|---|
| 3.13 | Document review or approval workflow | `decidiq/openspec/changes/document-approval-chain-leaf/proposal.md` | confirmed covered, no new change needed |

The discovery sweep is the part that needs building. It moves 11 of its
636 candidates to decidiq, all of them in one cluster, and decidiq carried
no change for it until this umbrella.

## The changes under it

| change | cluster | candidates | size | decision | dossiq consumer |
|---|---|---|---|---|---|
| `the-decision-as-a-walked-process` | 22, the decision as a walked process | C-decisions-1, C-decisions-2, C-decisions-3, C-decisions-4, C-decisions-7, C-decisions-10, C-decisions-13, C-decisions-16, C-decisions-17, C-decisions-22, C-decisions-25 | L | D6, D21 | needs a dossiq change: the case reads the approval outcome, the admissibility verdict and the remedy clause from decidiq, and stops modelling a parafeerroute of its own |

Cluster 22 is the third loudest of decidiq's kind in the sweep: 11
candidates, 7 of them `must`, 10 passers of which 8 driven, one matrix
hole. The cluster's own mechanism line reads "extend decidiq's decision
and approval specs; dossiq consumes the outcome onto the case", and the
change above does exactly that.

## The pending-proposal rows, added 2026-09-14

The corpus batch file
`procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md` proposes 98 rows
under decision D1. Two of them are decidiq's, both in the tasks and phases
area, both rated `partial` for dossiq. Neither is covered by an existing
change: `approval-routes`, `approval-route-events`,
`document-approval-chain-leaf`, `parafering-route-runtime` and
`the-decision-as-a-walked-process` were all read in full, and none carries a
manager resolved from the organisation record or a declared meaning for
silence.

Every competitor column on these rows is `unread`, and the corpus says so
itself: "`no` is a reading of a product somebody opened, and filling these
cells with it would fabricate thirty readings per row." So no competitor claim
rests on them.

| change | rows | size | dossiq consumer |
|---|---|---|---|
| `approval-routes-resolve-a-manager-and-declare-silence` | 3.24, 3.31 | M | the raise exists in dossiq `parafering-to-decidiq`; the closure gate is to be specified in dossiq |

One change rather than two: both rows are the same missing piece of a route
step. A step names a person and has no clock, so it cannot be raised for
whoever manages the owner and cannot end when nobody answers. The substitute
asked before a deadline (3.31) is resolved by the same rule mechanism as the
manager (3.24), and splitting them would build that mechanism twice.

## Why decidiq and not dossiq

dossiq deleted its parafeerroute surface on 2026-09-03 (`src/manifest.json`
retirement note, quoted by the decisions lane). `approval-routes` in this
repo already models a reusable route, an append-only action record and an
engine that advances it. Putting a second approval engine on the case app
would give the fleet two, and the register's ownership rules refuse that.

## The decisions these rest on

- **D6, relevance-led promotion.** Every `must` enters whatever its passer
  count. Seven of the eleven candidates are `must`, and four of those have
  a single driven passer. None is dropped for that.
- **D21, documented candidates admitted and labelled.** Three of the eleven
  rest on a vendor page rather than on a run system: C-decisions-7,
  C-decisions-16 and C-decisions-17. Each is labelled in the spec, and none
  is counted in a driven tally.

## Affected projects

- `decidiq`: one change, listed above.
- `dossiq`: consumes the outcome onto the case. Not changed here.
- `filinq`: owns the document itself. The future effective date in
  C-decisions-3 releases a decidiq governing document, not a filinq
  template.
- `openregister`: owns the audit trail every approval action is written to.
