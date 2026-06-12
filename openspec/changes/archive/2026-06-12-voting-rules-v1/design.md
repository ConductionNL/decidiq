# Design — voting-rules-v1

## Tally formula

Let `F` = weighted for-votes, `A` = weighted against-votes, `B` = weighted abstentions,
`T = F + A + B` (total votes cast).

**Base** (the denominator every threshold is evaluated against):

| abstentionHandling | base        |
|--------------------|-------------|
| `exclude` (default)| `F + A`     |
| `count`            | `F + A + B` |

Counting abstentions makes every threshold harder: an abstention is effectively a
vote against the threshold being reached.

**Result** (integer math, no float comparisons):

1. `T == 0` → `invalid` (unchanged).
2. **Tie detection** — only under `voteThreshold == simple-majority`:
   `F == A && F > 0` → the tie-break rule applies:
   - `rejected` (default) → result `rejected` (motion fails, the legal status quo).
   - `chair-decides` → result `tied` until the round carries a `chairCastingVote`
     (`for` → `adopted`, `against` → `rejected`). The chair supplies it by re-running
     close with the `chairCasting` parameter (chair-only, fail closed).
   - `revote` → result `tied`; the round may be reopened exactly once via a new round
     carrying `revoteOfRound = <tied round id>`.
3. **Threshold check** (no tie concept for qualified thresholds — failing the
   threshold is `rejected`):
   - `base == 0` → `rejected` (nothing can carry; also guards unanimous vacuity).
   - `simple-majority`: adopted iff `2F > base` (strict majority, "50%+1").
   - `qualified-majority-two-thirds`: adopted iff `3F >= 2 * base`.
   - `qualified-majority-three-quarters`: adopted iff `4F >= 3 * base`.
   - `unanimous`: adopted iff `F == base`.

Spec worked example (two-thirds, exclude): 14 for, 5 against, 1 abstain →
base 19, `3*14=42 >= 2*19=38` → adopted (14/19 = 73.7% ≥ 66.7%). The required
threshold, abstention handling, tie-break rule and computed base are returned by
`tallyResults()` and persisted on the round so the result is auditable.

Note on tie-vs-half under `count`: a "tie" is the classic `F == A` deadlock. A vote
that merely fails to reach half the counted base (e.g. 5 for / 3 against / 2 abstain
under `count`) is not a tie — it is simply `rejected`.

## Chair casting vote (fail closed)

- `VotingController::close()` accepts an optional `chairCasting` body param.
- When present, the controller requires the per-meeting **chair** role
  (`ParticipantResolver::hasRole(roles: ['chair'])` — the existing chair resolution
  pattern; secretary does NOT suffice for a casting vote). When the meeting cannot
  be resolved it falls back to the existing global `chair_group`/admin check.
  Any failure → 403, the casting vote is never applied.
- `VotingService::closeVotingRound()` validates: value ∈ {for, against} AND the
  round's `tieBreakRule == 'chair-decides'` — otherwise it throws (fail closed).
  The casting vote is persisted as `chairCastingVote` on the round before the
  re-tally so the audit trail shows how the tie was resolved.

## Revote (once)

- `VotingService::openVotingRound()` accepts `revoteOfRoundId`. Validations (all
  fail closed): the referenced round exists, its `result == 'tied'`, its
  `tieBreakRule == 'revote'`, and no other round already references it via
  `revoteOfRound` (the "once" guarantee).
- The revote round copies nothing implicitly — the chair configures it in the open
  dialog as usual (defaults prefilled from the tied round in the UI).
- The motion lifecycle transition (`debating → voting`) is **skipped** for revote
  rounds: the motion is still in `voting` (a tied result never transitioned it out),
  and `voting → voting` is not a legal state-machine transition.

## Proxy cap

- App config: `decidesk` / `max_proxies_per_holder`, integer, default **2**
  (NL governance default, e.g. BW 2:227-style statutory caps).
- `ProxyVoteService::register()` counts proxies in the same meeting with
  `proxyStatus == 'active'` held by the holder via the existing `forMeeting()`
  reader. `count >= max` → rejected with an explicit message. If counting fails
  (OpenRegister unavailable) registration is rejected — fail closed, never
  fail open.

## castAs stamping

- `VotingService::castVote()` resolves the casting participant object and reads its
  `participantType` (`in-person` | `remote`, new additive field). The value is
  stamped on the vote as `castAs`; when the participant cannot be resolved or the
  field is unset, `castAs = 'unknown'`. Honest recording only — no session
  "verification" theater. Applies to secret rounds too: `castAs` carries no
  identity, only attendance mode.

## Test layers

- **PHPUnit**: exhaustive matrix in `VotingServiceTallyMatrixTest` — every
  `voteThreshold` (4) × `abstentionHandling` (2) × representative vote
  distributions incl. all tie scenarios × all three tie-break rules, using the
  anonymous-double container pattern from `VotingServiceDelegationGateTest`
  (avoids issue #90). Plus `ProxyVoteServiceTest` cap cases and
  `VotingServiceCastAsTest`.
- **Vitest**: `src/utils/votingRules.js` (base computation + labels).
- **Playwright**: `tests/e2e/spec-coverage/voting-rules.spec.ts` — rule selectors in
  the open-round dialog, defensive skips when no motion in `debating` exists.
- **Newman**: `decidesk-voting-rules.postman_collection.json` — contract tests for
  open-with-rules, tally under two-thirds, chair casting vote guard, revote-once
  guard, proxy cap; follows the lifecycle collection's auth model (per-request
  basic auth + noAuthBase 401 checks), self-seeding + teardown.
