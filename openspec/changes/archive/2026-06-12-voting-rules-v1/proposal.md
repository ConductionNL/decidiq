---
kind: code
---

# Proposal: Voting Rules v1 — Qualified Majorities, Abstention Handling, Tie-Breaking, Proxy Limits, Remote-Vote Annotation

## Problem

The seeded spec `openspec/specs/voting-system/spec.md` (status: partial) lists five
residual gaps after the 2026-06-12 audit:

1. **Qualified-majority calculation** — `VotingService::tallyResults()` only implements
   a simple "for > against" comparison. The spec requires configurable thresholds
   (simple majority 50%+1, two-thirds, three-quarters, unanimous) with the required
   threshold recorded alongside the result (BW 2:42 statute amendments, BW 2:18
   dissolution).
2. **Abstention-handling configuration** — the spec requires abstentions to be
   configurable as excluded from or counted toward the calculation base. Today
   abstentions are always tally-neutral.
3. **Configurable tie-breaking** — a 10–10 vote is hard-coded to result `tied` with no
   configured consequence. The spec requires a configurable rule: motion fails
   (status quo), chair's casting vote, or revote.
4. **Per-member proxy limits** — `ProxyVoteService::register()` accepts unlimited
   proxies per holder. NL governance practice (and the spec scenario "Limit proxy
   votes per member") caps proxies per holder per meeting (statutory default 2).
5. **Remote-vote session annotation** — votes cast by remote participants in
   digital/hybrid meetings are indistinguishable from in-person votes. The spec
   requires the attendance mode to be recorded alongside the vote.

## What Changes

- **MODIFIED** `lib/Settings/decidesk_register.json` — ADDITIVE fields only:
  - `voting-round`: `voteThreshold` (enum mirroring Resolution.voteThreshold:
    `simple-majority` | `qualified-majority-two-thirds` |
    `qualified-majority-three-quarters` | `unanimous`, default simple-majority),
    `abstentionHandling` (enum `exclude` | `count`, default exclude),
    `tieBreakRule` (enum `rejected` | `chair-decides` | `revote`, default rejected),
    `chairCastingVote` (enum `for` | `against`, recorded when the chair breaks a tie),
    `revoteOfRound` (string UUID link — set on a round opened as the single permitted
    revote of a tied round), `voteBase` (integer — the computed calculation base
    recorded at tally time for auditability).
  - `participant`: `participantType` (enum `in-person` | `remote`) recording the
    participant's attendance mode for the current/most recent meeting.
  - `vote`: `castAs` (enum `in-person` | `remote` | `unknown`) — honest recording of
    the caster's attendance mode at cast time, no verification theater.
- **MODIFIED** `lib/Service/VotingService.php`:
  - `tallyResults()` computes the threshold-aware result from the round's configured
    `voteThreshold` + `abstentionHandling` + `tieBreakRule` (formula in design.md),
    returns the computed base and applied rules, and persists them on the round.
  - `closeVotingRound()` accepts an optional `chairCasting` parameter (`for`/`against`)
    that is only honoured when the round's `tieBreakRule` is `chair-decides`
    (fail closed) — the chair re-runs close on a tied round with their casting vote.
  - `openVotingRound()` accepts the three rule parameters (validated against the
    enums, fail closed) plus an optional `revoteOfRoundId` for the one permitted
    revote of a round tied under `tieBreakRule=revote`.
  - `castVote()` stamps `castAs` from the casting participant's `participantType`
    (`unknown` when unresolvable).
  - All existing hooks preserved: quorum gate, activity publishing, votingOpened
    notifications, absence-delegation gate, proxy grant checks, secret-ballot tokens.
- **MODIFIED** `lib/Service/ProxyVoteService.php` — `register()` enforces a per-holder
  per-meeting cap on ACTIVE proxies, configurable via app config key
  `decidesk` / `max_proxies_per_holder` (default 2). Fail closed: when the existing
  proxies cannot be counted, registration is rejected.
- **MODIFIED** `lib/Controller/VotingController.php` — `open()` accepts + validates the
  rule fields and `revoteOfRound`; `close()` accepts `chairCasting` guarded by a
  per-meeting chair-only role check (existing ParticipantResolver::hasRole pattern,
  fail closed).
- **MODIFIED** `src/components/VotingRoundPanel.vue` — rule selectors (threshold,
  abstention handling, tie-break) in the open-round dialog with defaults; active
  rules + computed base shown in the live tally and results display; chair casting
  vote controls and revote button on tied rounds.
- **ADDED** `src/utils/votingRules.js` — pure helpers (base computation, rule labels)
  shared by the panel and unit-tested with Vitest.
- **ADDED** PHPUnit tally matrix (every threshold × abstention mode × tie scenario),
  proxy-limit tests, castAs stamping tests; Playwright UI additions; Newman
  collection `tests/integration/decidesk-voting-rules.postman_collection.json`
  wired into `tests/newman/run-all.sh`.

## Impact

- Backwards compatible: rounds without the new fields behave exactly as before
  (simple majority, abstentions excluded, tie → the round's stored result stays
  `tied` only under chair-decides/revote rules; the default `rejected` rule makes a
  tie fail the motion, which matches the legal default that a tied motion is not
  adopted).
- No existing schema fields are touched; all schema changes are additive.
- `ProxyVoteService::register()` becomes stricter (cap enforcement). Existing
  callers receive the established `{success:false, message}` failure shape.
