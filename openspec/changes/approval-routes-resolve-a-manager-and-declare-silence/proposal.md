---
kind: code
depends_on: [approval-routes, document-approval-chain-leaf]
---

# Proposal: approval-routes-resolve-a-manager-and-declare-silence

## Summary

Today an approval step names one person and then waits, with no end. This
change lets a step name a rule instead, "the manager of whoever owns the
subject", and lets it declare what silence means once its deadline passes.
It also gives a consuming app one question to gate closure on: is this
subject cleared.

## The rows this closes

Two rows of the competitor gap register in ConductionNL/market-intelligence
(`procest/_gaps/`), both promoted under decision D1, both owned by decidiq
under `procest/_gaps/ownership-rules.md`: routing a sign-off past a sequence
of officials is governance, and governance is this app's domain.

### Row 3.24, approval by the case owner's manager, with closure gated on it

Rating for dossiq: `partial`. Area: tasks and phases.

Ledger `source`, verbatim:

```
dossiq#2314, published as 3.21
```

Ledger `note`, verbatim, which is dossiq's own evidence read out of dossiq's
tree:

> DispositionService gates a complaint disposition on an approval. It resolves no manager from the organisation tree and no other case type can use it.

Corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **3.24** | 3.21 | Approval by the case owner's manager, with closure gated on it | partial | unread | discovery D-request-tracker-7 |
```

### Row 3.31, multi-step approval where silence has a declared meaning

Rating for dossiq: `partial`. Area: tasks and phases.

Ledger `source`, verbatim:

```
dossiq#2314, published as 3.28
```

Ledger `note`, verbatim:

> SubstitutionService and MandaatEscalatieService exist. Nothing declares what happens when an approver says nothing, and nothing asks a substitute before the timeout runs out.

Corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`,
table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **3.31** | 3.28 | Multi-step approval where silence has a declared meaning | partial | unread | corpus 3.13 |
```

## What the competitor evidence is

Nothing was read of any competitor for either row. Both are D1 rows, and the
corpus says so in as many words:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is a reading of a product somebody opened, and filling these cells with it would fabricate thirty readings per row.

So this proposal makes no competitor claim. The evidence it rests on is the
ledger note above, which reads dossiq's own tree, and the cross-references the
rows carry: discovery `D-request-tracker-7` for 3.24 and corpus row 3.13, the
document approval chain, for 3.31.

## The ADRs it cites

Read for this change, and only these:

- **ADR-022, apps consume OpenRegister abstractions.** The organisation record
  is not decidiq's to hold. The manager is resolved through OpenRegister, never
  by reaching into humaniq's container.
- **ADR-048, cross-app semantic references.** A step's rule names a kind by
  semantic type URI, not an app id and not a schema slug, and resolution is
  null-safe across whatever schemas the instance has installed.
- **ADR-041, cross-app commands via events.** The clearance answer and the
  conclusion travel the typed seam `approval-route-events` already defines. A
  consumer that cannot reach the engine fails closed, it never auto-approves.
- **ADR-069, background-job conventions.** The lapse sweep is one `TimedJob`
  under `lib/BackgroundJob/`, registered in `appinfo/info.xml`.
- **ADR-099, acting on behalf of a user.** A substitute signs as themselves and
  the record says on whose behalf. Nobody's session is swapped to sign.
- **decidiq ADR-005, decision as universal supertype**, for the subject a route
  travels against.

## What decidiq builds

1. `ApprovalRoute.steps[]` gains `actorRule`, so a step names a rule rather
   than a person. The first rules are `manager-of-subject-owner`,
   `manager-of-actor` and `substitute-of-actor`.
2. A resolver that answers those rules from the organisation record through
   OpenRegister, and refuses the route when it resolves to nobody.
3. `ApprovalRoute.steps[]` gains `onSilence` and `askSubstituteAfter`, so a
   step declares what its own silence means and when a substitute is asked.
4. A lapse sweep that applies a lapsed step's declared meaning once, and
   records it as an `ApprovalAction` by the system rather than by a person.
5. A clearance answer for a subject, and the conclusion event that carries it,
   so a consumer can gate closure on the route.

## What dossiq consumes from it

- The raise already exists. dossiq's `parafering-to-decidiq` sends a route over
  the typed seam today, and this change adds fields to the route it sends.
- The closure gate is dossiq's half and has no artefact on dossiq
  `development` yet, so: to be specified in dossiq. dossiq refuses to close a
  case while a required route on it is unconcluded, and reads the refusal from
  the clearance answer here. It holds no engine and no manager lookup of its
  own.

## The neighbour this change does not repeat

`the-decision-as-a-walked-process` landed on `development` on 2026-09-14, from
the discovery half of the same programme, and it extends the same engine. Read
in full before this change was written. The boundary:

- **REQ-DWP-001** makes an open approval a work item and blocks the subject
  from advancing past its step. That is the route's own advance. REQ-AR-014
  here is the read a sibling app gates CLOSURE on, across every required route
  on a subject, carried on the conclusion event and fail-closed when decidiq is
  absent. It adds a question a consumer can ask, not a second block.
- **REQ-DWP-009** lets a requester nominate an approver from a bounded group.
  REQ-AR-012 resolves an approver from the organisation record with nobody
  choosing. A step uses one or the other, never both.
- **REQ-DWP-001 also puts `dueAt` on the action**, where REQ-AR-009 already put
  it on the stage. The lapse sweep here reads the stage, which is the field
  `document-approval-chain-leaf` derives from the route's deadline. Whether the
  action needs its own date is a question for the walked-process lane, recorded
  here rather than settled here.

Neither change carries the manager rule or the silence policy. Both rows stay
open until this one lands.

## Size

M. Two additive schema blocks, one resolver, one sweep job, one read endpoint,
and the tests that pin each refusal. It is not L: the route engine, the
append-only action record and the return path already exist and are not
touched.

## The spec it extends

`approval-routes`, planned by the change of the same name and already extended
once by `document-approval-chain-leaf` (REQ-AR-008 to REQ-AR-011). This delta
adds REQ-AR-012 to REQ-AR-017 and changes no existing requirement.

## Risks

- **A silence policy that approves is a signature nobody gave.** So `hold` is
  the default and the only policy that needs no declaration, `approve` has to
  be declared per step by an administrator, and every lapse is recorded as an
  action naming the policy that caused it.
- **A manager rule that resolves to the wrong person signs the wrong thing.**
  The resolver refuses on nobody and on more than one candidate, and the
  resolved person is written onto the stage at activation so the record says
  who was asked, not who the rule would pick today.
- **The organisation record may be absent.** An instance without a schema
  implementing the person kind cannot use `manager-of-subject-owner`. The route
  refuses to instantiate that step with an explanatory error, which is visible,
  rather than assigning nobody, which is not.
