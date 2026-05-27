---
status: idea
---

# Voting System Specification

## Purpose
@e2e exclude All voting scenarios require a live meeting in-progress with active voting rounds, quorum calculations, and multi-user ballot state that cannot be deterministically set up via pure UI interactions. The VotingRoundPanel component exists but its scenarios are integration-level (vote casting, real-time tallying, secret ballot, proxy enforcement) requiring backend state that must be tested at the PHP/WebSocket layer.

The voting system is Decidesk's most critical feature. It supports multiple voting methods (open vote, secret ballot, roll call, weighted voting), real-time ballot casting and result calculation, quorum-aware majority thresholds, proxy vote handling, and configurable voting rules per governing body. The system ensures legally compliant voting for associations (ALV), corporate boards (BV/NV), and government councils.

**Standards**: Schema.org (`VoteAction`, `ChooseAction`), Akoma Ntoso (`voting`, `count`), OpenRaadsinformatie (`Stemming`, `Stem`)
**Feature tier**: MVP
**Legal reference**: BW 2:38 (ALV voting), BW 2:230 (BV shareholder voting), Gemeentewet 27-32 (council voting), WBTR (documentation requirements)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Vote and VotingRound entity definitions including property tables, Schema.org mappings, and OpenRaadsinformatie alignment.

## Requirements

---

### Requirement: Open Vote (For/Against/Abstain)

The system MUST support open (public) voting where each participant casts a for, against, or abstain vote. Results MUST be displayed in real-time. The vote of each participant MUST be recorded and visible in the minutes.

**Feature tier**: MVP

#### Scenario: Conduct an open vote on an agenda item

- GIVEN a meeting with quorum met and an active agenda item of type "decision"
- WHEN the chair initiates an open vote
- THEN each eligible member MUST see a voting panel with "For", "Against", and "Abstain" buttons
- AND the system MUST display the running tally in real-time
- AND once all members have voted (or the chair closes voting), the result MUST be calculated
- AND the result (adopted/rejected) MUST be announced based on the configured majority rule

#### Scenario: View individual votes after an open vote

- GIVEN an open vote has been completed
- WHEN a user views the voting results
- THEN the system MUST display how each member voted (for/against/abstain)
- AND the results MUST be recorded in the decision audit trail

#### Scenario: Reject a vote when quorum is lost mid-meeting

- GIVEN a meeting where quorum was initially met but members have since left
- WHEN the chair attempts to start a new vote
- THEN the system MUST recalculate quorum from current attendance
- AND if quorum is no longer met, voting MUST be blocked with a quorum warning

---

### Requirement: Secret Ballot

The system MUST support secret (anonymous) voting where individual votes are not linked to voters in the results. Secret ballots MUST be used for board elections and other votes where the chair or statutes require anonymity.

**Feature tier**: MVP
**Legal reference**: BW 2:38 (election by secret ballot), Gemeentewet 31 (secret ballot requirements)

#### Scenario: Conduct a secret ballot for board election

- GIVEN a meeting with an agenda item "Board Election — Treasurer"
- WHEN the chair initiates a secret ballot
- THEN each eligible member MUST see a voting panel with candidate options
- AND individual votes MUST NOT be linked to voters in the stored results
- AND only aggregate totals (votes per candidate) MUST be recorded
- AND the system MUST verify that the total vote count matches the number of eligible voters

#### Scenario: Verify vote count integrity for secret ballot

- GIVEN a secret ballot has been completed with 12 eligible voters
- WHEN the results are tallied
- THEN the total number of votes MUST equal exactly 12
- AND if a discrepancy is detected, the system MUST flag it for the chair

---

### Requirement: Qualified Majority and Voting Rules

The system MUST support configurable majority rules: simple majority (50%+1), qualified majority (e.g., 2/3), unanimous, and weighted voting. Abstentions MUST be configurable as counting toward total or excluded from calculation.

**Feature tier**: MVP
**Legal reference**: BW 2:42 (statute amendment requires 2/3), BW 2:18 (dissolution requires 2/3)

#### Scenario: Verify qualified majority for statute amendment

- GIVEN a vote on statute amendment requiring 2/3 majority of votes cast
- WHEN 20 members vote: 14 for, 5 against, 1 abstain
- THEN the system MUST calculate: 14/(14+5) = 73.7% (abstentions excluded from calculation)
- AND the result MUST be "adopted" (73.7% >= 66.7%)
- AND the system MUST record the required threshold alongside the result

#### Scenario: Verify quorum requirement for statute amendment vote

- GIVEN a statute amendment vote requiring 2/3 of members present
- WHEN only 8 of 15 members are present (53%)
- THEN the system MUST block the vote with a message: "Quorum not met. Statute amendment requires 2/3 of members present (10 required, 8 present)."

#### Scenario: Handle a tie vote

- GIVEN a simple majority vote where 10 for and 10 against
- WHEN the votes are tallied
- THEN the system MUST declare the result as "tied"
- AND the system MUST apply the configured tie-breaking rule (e.g., chair's casting vote, motion fails, or revote)

---

### Requirement: Proxy Voting

The system MUST support digital proxy voting (volmacht) where a member authorizes another member to vote on their behalf. Proxy votes MUST be verifiable and count toward both quorum and voting.

**Feature tier**: MVP
**Legal reference**: BW 2:227 (shareholder proxy), BW 2:38 (ALV proxy per statutes)

#### Scenario: Grant and exercise a digital proxy

- GIVEN member A cannot attend the ALV and grants a proxy to member B
- WHEN member B votes on a decision item
- THEN the system MUST prompt member B to cast their own vote AND the proxy vote separately
- AND both votes MUST be recorded (member B's own vote and member A's proxy vote)
- AND the results MUST show the total including proxy votes

#### Scenario: Limit proxy votes per member

- GIVEN the statutes allow a maximum of 2 proxies per member
- WHEN member B already holds 2 proxies and member C attempts to grant a proxy to member B
- THEN the system MUST reject the proxy with a message indicating the maximum has been reached

---

### Requirement: Remote Voting in Digital/Hybrid Meetings

The system MUST support real-time voting for remote participants in digital and hybrid meetings. Remote votes MUST have equal weight to in-person votes. The system MUST ensure vote integrity through session verification.

**Feature tier**: MVP

#### Scenario: Cast vote remotely during hybrid meeting

- GIVEN a hybrid meeting where member is attending remotely
- WHEN the chair initiates a vote
- THEN the remote member MUST see the same voting panel as in-person attendees
- AND their vote MUST be counted with equal weight
- AND their attendance mode (remote) MUST be recorded alongside their vote

## User Stories

1. **Chair conducting open vote**: As chair, I want to conduct an open vote (for/against/abstain) on an agenda item and see results in real-time so that I can announce the outcome immediately. (Source: intelligence DB #57)

2. **Chair conducting secret ballot**: As chair, I want to conduct a secret ballot for board elections so that members can vote freely without social pressure. (Source: intelligence DB #60)

3. **Secretary verifying qualified majority**: As secretary, I want to verify that a statute amendment vote meets the required quorum and qualified majority so that the notary can confirm proper adoption. (Source: intelligence DB #59)

4. **Member casting remote vote**: As a member attending remotely, I want to cast my vote securely during the ALV so that my participation is equal to physical attendees. (Source: intelligence DB #58)

5. **Member granting digital proxy**: As a member who cannot attend the ALV, I want to grant a proxy (volmacht) to another member digitally so that my vote is represented without paper forms. (Source: intelligence DB #63)

## Acceptance Criteria

- Open vote records individual votes per participant (for/against/abstain)
- Secret ballot stores only aggregate totals with vote count integrity verification
- Configurable majority rules: simple, qualified (2/3, 3/4), unanimous, weighted
- Proxy votes are verifiable, count toward quorum, and respect per-member limits
- Remote votes have equal weight with session verification
- Quorum is rechecked before each vote
- Tie-breaking rules are configurable per body
- All voting results mapped to OpenRaadsinformatie `Stemming`/`Stem`
- Real-time result display during voting
