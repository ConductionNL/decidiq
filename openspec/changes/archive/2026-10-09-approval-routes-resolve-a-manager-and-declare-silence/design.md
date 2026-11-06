# Design: approval-routes-resolve-a-manager-and-declare-silence

Kind: code. Two additive schema blocks, one resolver, one timed job, one read.

## Architecture

`approval-routes` holds a subject on an `ApprovalRoute`, materialises the
template's steps as `DecisionStage` rows and advances them as `ApprovalAction`s
arrive. `document-approval-chain-leaf` added `dueAt` per stage. This change adds
nothing to how a route advances. It adds who a step resolves to, what happens
when nobody acts before `dueAt`, and one question a consumer may ask about the
subject.

The engine stays in decidiq. That is settled by `approval-routes` and
`parafering-route-runtime`, which argued the placement and the alternative
(OpenRegister's `TaskSequenceService`) at length. This change does not reopen it.

## D1. A step names a rule

`steps[].actorRule` is an enum, not a free expression. Three values ship:
`manager-of-subject-owner`, `manager-of-actor`, `substitute-of-actor`. An enum
is checkable by schema validation and readable on a route detail page. A rule
language would be neither, and nothing in the two rows asks for one.

Resolution happens at activation, in `ApprovalRouteService`, and writes
`actor`, `actorResolvedBy` and `actorResolvedAt` onto the stage. Instantiation
keeps the rule and nothing else, so a route raised in January and reached in
June asks the manager of June.

## D2. Where the manager comes from

decidiq does not hold the organisation tree. humaniq does, in `Employee` and
`OrgAssignment`, and the handoff note in `configurable-types-domain-model`
records that decidiq's `Person` and humaniq's `Employee` are two identities
with a bridge, not one model.

So the resolver reads by semantic type (ADR-048): enumerate installed schemas,
take the one implementing the person kind that carries a manager reference,
resolve through OpenRegister. No humaniq class, no HTTP to a sibling app
(ADR-022, ADR-041). An instance that installs no such schema cannot use the
rule, and gets an error that says that.

Two candidates refuse. Ranking them would be inventing an organisation policy
this app does not know.

## D3. What silence means

`steps[].onSilence` with `hold` as default. The default is the behaviour the
engine has today, so an existing route changes nothing when this ships.

`approve` is administrator-only because it writes an approval nobody gave.
`refuse` and `escalate` need no such guard: refusing on silence is the
conservative reading, and escalating asks one more person.

`escalate` restarts the window once. A second lapse of the same stage holds.
Otherwise a route with a broken manager chain escalates up an organisation
forever, one sweep at a time.

## D4. The substitute, before the deadline

`askSubstituteAfter` is a fraction of the window, not an absolute date, so one
template serves a two day route and a twenty day route. At the ask point the
substitute is asked as well as the actor, never instead: taking the ask away
from someone who is present and simply slow is how a route loses its own
decision maker.

The substitute signs as themselves with `onBehalfOf` set, which is the shape
`ApprovalAction` already carries (REQ-AR-002) and the posture ADR-099 requires.

## D5. Clearance

One read: for a subject, has every `required` route on it concluded. It answers
with the waiting stage and its actor, because a consumer that refuses to close
a case has to be able to say who it is waiting for.

The same answer rides `ApprovalRouteConcludedEvent`, which
`parafering-route-runtime` already fires from every concluding path, so a
consumer can project it rather than poll.

dossiq's half, the refusal to close, is dossiq's change and is not specified
here.

## D6. The sweep

One `TimedJob` in `lib/BackgroundJob/`, registered in `appinfo/info.xml`
(ADR-069). It reads active stages with a `dueAt` in the past, applies the
declared policy, and writes one `ApprovalAction` per stage it changes.

Idempotency comes from the action, not from a flag: a stage that already
carries a system action for this lapse is skipped. A stage a person decided
between two sweeps is no longer active, so the second sweep does not see it.

## Risks

- Working-day arithmetic for `dueAt` is `document-approval-chain-leaf`'s, and
  this change reuses it rather than adding a second clock.
- A sweep that runs on a stalled instance applies every lapse at once. The
  actions carry `recordedAt` of the sweep, not of the deadline, so the record
  says when the machine acted.
- `onSilence: approve` is a real risk and it is declared, per step, by an
  administrator. The audit trail names the policy in every action it causes.
