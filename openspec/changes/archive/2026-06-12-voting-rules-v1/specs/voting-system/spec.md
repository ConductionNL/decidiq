---
status: draft
---

# Spec Delta: Voting System (voting-rules-v1)

## Purpose

Closes the five audit gaps in the seeded voting-system spec: qualified-majority
calculation, abstention-handling configuration, configurable tie-breaking,
per-member proxy limits, and remote-vote session annotation. Requirement texts
below replace their counterparts in the main spec; all other requirements are
untouched.

## MODIFIED Requirements

---

### Requirement: Qualified Majority and Voting Rules

The system MUST support configurable majority rules per voting round: simple
majority (50%+1), qualified majority two-thirds, qualified majority
three-quarters, and unanimous (mirroring the Resolution schema's
`voteThreshold` enum). Abstentions MUST be configurable as counting toward the
calculation base (`abstentionHandling: count`) or excluded from it
(`abstentionHandling: exclude`, default). Tie-breaking MUST be configurable per
round (`tieBreakRule`): `rejected` (default — a tied motion fails),
`chair-decides` (round result becomes `tied` and the chair resolves it with an
explicit casting vote), or `revote` (round result becomes `tied` and the round
may be reopened exactly once via a new round linked through `revoteOfRound`).
The system MUST record the applied threshold, abstention handling, tie-break
rule and computed base alongside the result.

**Tally formula** (F = weighted for, A = weighted against, B = weighted
abstentions, T = F+A+B):

- base = F + A (`exclude`) or F + A + B (`count`)
- T == 0 → `invalid`; tie (simple-majority only, F == A and F > 0) → tie-break
  rule applies; base == 0 → `rejected`
- simple-majority: adopted iff 2F > base
- qualified-majority-two-thirds: adopted iff 3F ≥ 2·base
- qualified-majority-three-quarters: adopted iff 4F ≥ 3·base
- unanimous: adopted iff F == base

**Feature tier**: MVP
**Legal reference**: BW 2:42 (statute amendment requires 2/3), BW 2:18 (dissolution requires 2/3)

#### Scenario: Verify qualified majority for statute amendment

@e2e exclude tally math is server-side over multi-user ballot state; exhaustively covered by the PHPUnit tally matrix (VotingServiceTallyMatrixTest) and the Newman two-thirds contract test

- GIVEN a vote on statute amendment requiring 2/3 majority of votes cast
- WHEN 20 members vote: 14 for, 5 against, 1 abstain
- THEN the system MUST calculate: 14/(14+5) = 73.7% (abstentions excluded from calculation)
- AND the result MUST be "adopted" (73.7% >= 66.7%)
- AND the system MUST record the required threshold alongside the result

#### Scenario: Verify quorum requirement for statute amendment vote

@e2e exclude quorum gate is a server-side precondition requiring seeded multi-member attendance state; covered at the PHP layer (checkQuorum + openVotingRound tests)

- GIVEN a statute amendment vote requiring 2/3 of members present
- WHEN only 8 of 15 members are present (53%)
- THEN the system MUST block the vote with a message: "Quorum not met. Statute amendment requires 2/3 of members present (10 required, 8 present)."

#### Scenario: Configure voting rules when opening a round

- GIVEN a chair opening a voting round on a motion under debate
- WHEN the open-round dialog is shown
- THEN the chair MUST be able to select the vote threshold (simple majority, two-thirds, three-quarters, unanimous), the abstention handling (exclude or count), and the tie-break rule (motion fails, chair decides, revote)
- AND the defaults MUST be simple majority, abstentions excluded, and motion fails on a tie

#### Scenario: Abstentions counted toward the base

@e2e exclude tally math is server-side; covered by the PHPUnit tally matrix (count-mode rows)

- GIVEN a voting round with `abstentionHandling: count` and a two-thirds threshold
- WHEN 20 members vote: 13 for, 4 against, 3 abstain
- THEN the base MUST be 20 (abstentions counted) and the result "rejected" (3·13=39 < 2·20=40)
- AND with `abstentionHandling: exclude` the same votes MUST yield base 17 and "adopted" (3·13=39 ≥ 2·17=34)

#### Scenario: Handle a tie vote

@e2e exclude tie resolution is server-side over multi-user ballot state; covered by the PHPUnit tally matrix (all three tie-break rules) and the Newman chair-casting guard test

- GIVEN a simple majority vote where 10 for and 10 against
- WHEN the votes are tallied
- THEN the system MUST apply the configured tie-break rule: with `rejected` (default) the result MUST be "rejected" (the motion fails), with `chair-decides` or `revote` the result MUST be "tied"

#### Scenario: Chair casting vote resolves a tie

@e2e exclude requires a live tied round with multi-user ballots; chair-only authorization and resolution are covered by PHPUnit (tally matrix chair-casting rows) and the Newman casting-vote guard test

- GIVEN a round closed as "tied" with `tieBreakRule: chair-decides`
- WHEN the chair re-runs close with an explicit casting vote (`chairCasting: for` or `against`)
- THEN the casting vote MUST be recorded on the round as `chairCastingVote` and the result resolved to "adopted" or "rejected"
- AND a casting vote from anyone who does not hold the chair role for the meeting MUST be refused (fail closed)
- AND a casting vote on a round whose tie-break rule is not `chair-decides` MUST be refused

#### Scenario: Revote permitted once after a tie

@e2e exclude requires a live tied round with multi-user ballots; the revote-once guard is covered by PHPUnit (openVotingRound revote tests) and the Newman revote contract test

- GIVEN a round closed as "tied" with `tieBreakRule: revote`
- WHEN a new round is opened with `revoteOfRound` linking to the tied round
- THEN the new round MUST be created and linked, without re-transitioning the motion lifecycle
- AND a second revote of the same tied round MUST be refused
- AND a revote of a round that is not tied, or whose tie-break rule is not `revote`, MUST be refused

#### Scenario: Display active rules and computed base

- GIVEN a voting round with configured rules
- WHEN a user views the live tally or the closed-round results
- THEN the panel MUST show the active threshold, abstention handling and tie-break rule, and the computed base the threshold is evaluated against

---

### Requirement: Proxy Voting

The system MUST support digital proxy voting (volmacht) where a member
authorizes another member to vote on their behalf. Proxy votes MUST be
verifiable and count toward both quorum and voting. The system MUST enforce a
maximum number of ACTIVE proxies a single member may hold per meeting,
configurable via app config `decidesk`/`max_proxies_per_holder` (NL governance
default: 2). The cap MUST fail closed: when existing proxies cannot be counted,
registration MUST be rejected.

**Feature tier**: MVP
**Legal reference**: BW 2:227 (shareholder proxy), BW 2:38 (ALV proxy per statutes)

#### Scenario: Grant and exercise a digital proxy

@e2e exclude requires two authenticated members with live meeting/round state; covered at the PHP layer (castVote proxy tests) and the lifecycle Newman suite

- GIVEN member A cannot attend the ALV and grants a proxy to member B
- WHEN member B votes on a decision item
- THEN the system MUST prompt member B to cast their own vote AND the proxy vote separately
- AND both votes MUST be recorded (member B's own vote and member A's proxy vote)
- AND the results MUST show the total including proxy votes

#### Scenario: Limit proxy votes per member

@e2e exclude proxy-cap enforcement is a server-side rule over seeded proxy rows; covered by PHPUnit (ProxyVoteServiceTest cap cases) and the Newman proxy-cap contract test

- GIVEN the configured maximum is 2 proxies per holder per meeting
- WHEN member B already holds 2 ACTIVE proxies in the meeting and member C attempts to register a proxy to member B
- THEN the system MUST reject the registration with a message indicating the maximum has been reached
- AND when the existing proxies cannot be counted, the registration MUST also be rejected (fail closed)

---

### Requirement: Remote Voting in Digital/Hybrid Meetings

The system MUST support real-time voting for remote participants in digital and
hybrid meetings. Remote votes MUST have equal weight to in-person votes. The
system MUST honestly record the caster's attendance mode alongside the vote: at
cast time the participant's `participantType` (`in-person` | `remote`) is
stamped on the vote as `castAs` (`unknown` when the participant cannot be
resolved). No session-verification theater is performed.

**Feature tier**: MVP

#### Scenario: Cast vote remotely during hybrid meeting

@e2e exclude requires a remote-attending second user with live round state; castAs stamping is covered by PHPUnit (VotingServiceCastAsTest)

- GIVEN a hybrid meeting where member is attending remotely
- WHEN the chair initiates a vote
- THEN the remote member MUST see the same voting panel as in-person attendees
- AND their vote MUST be counted with equal weight
- AND their attendance mode (remote) MUST be recorded alongside their vote as `castAs: remote`
