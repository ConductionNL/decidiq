# Spec delta: Voting System — advisory citizen voting mode

This file contains delta specifications for the citizen-participation change against the existing `voting-system` capability. It EXTENDS the voting machinery; it does not duplicate it.

---

## ADDED Requirements

### Requirement: Advisory voting mode for citizen participation

The voting machinery (tally calculation, atomic tally updates, deadline/phase enforcement, duplicate-vote detection, count-integrity verification) MUST support an **advisory mode** used by citizen participation on `BudgetProposal` objects. Advisory mode MUST reuse the existing tally engine and integrity guarantees and MUST differ from statutory voting only as follows: no quorum requirement, no secret-ballot mode, no proxy votes, simple voor/tegen only (weighted and ranked methods are out of scope), votes recorded as `CitizenVote` (`schema:VoteAction`) instead of staff `Vote` objects, and results are advisory (they never produce an adopted/rejected decision outcome). Advisory tallies MUST remain strictly separate from statutory `VotingRound` tallies and MUST never be combined with them in any decision calculation.

**Feature tier**: V1
**Reuses**: open-vote tally engine, deadline enforcement, duplicate detection from the MVP voting requirements

#### Scenario: Advisory vote uses the shared tally engine

@e2e exclude service-level reuse assertion — PHPUnit verifies the advisory path delegates to the shared tally service
- **GIVEN** a `validated` budget proposal in a round in `voting` phase
- **WHEN** an authenticated citizen casts a `voor` advisory vote
- **THEN** the vote is recorded as a `CitizenVote` and the tally update runs through the same atomic tally path as statutory open votes

#### Scenario: Advisory vote requires no quorum

@e2e exclude mode-configuration branch — PHPUnit on the voting rule resolver
- **GIVEN** a budget round with any number of participants
- **WHEN** advisory voting opens
- **THEN** no quorum check is performed and voting proceeds regardless of participation level

#### Scenario: Advisory and statutory tallies never mix

@e2e exclude separation invariant — PHPUnit on the tally/result services
- **GIVEN** a motion with a statutory `VotingRound` and a related budget proposal with advisory `CitizenVote` records
- **WHEN** the statutory result is calculated
- **THEN** only `Vote` objects from the `VotingRound` are counted; `CitizenVote` records contribute nothing to the statutory outcome

#### Scenario: Duplicate detection shared with statutory voting

- **GIVEN** a citizen who has already cast an advisory vote on a proposal
- **WHEN** they attempt to vote again on the same proposal
- **THEN** the duplicate is rejected by the same duplicate-detection mechanism used for statutory votes and the tally is unchanged
