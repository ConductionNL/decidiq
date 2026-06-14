---
openspec-changes:
  - decision-methods
---

# vote-casting Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting. Update Purpose after archive.

## Requirements

### Requirement: REQ-VCT-001 Participant casts a vote in an open VotingRound
The app SHALL allow any active Participant to cast a vote (for, against, or abstain) in an open VotingRound. Each Participant may cast exactly one vote per VotingRound. Duplicate submissions overwrite the previous vote.

#### Scenario: Participant casts a vote
- **GIVEN** an open VotingRound with `votingMethod: "for-against-abstain"`
- **WHEN** an active Participant clicks "Voor", "Tegen", or "Onthouding"
- **THEN** `VotingService::castVote()` creates a Vote object with `value`, `castAt` = now, `isProxy: false`, linked to the VotingRound and the Participant via OpenRegister relations

#### Scenario: Participant changes their vote before the round closes
- **GIVEN** a Participant who has already voted "Tegen" in an open VotingRound
- **WHEN** the Participant clicks "Voor" before the round is closed
- **THEN** `VotingService::castVote()` finds the existing Vote for that Participant and updates `value` to "for" and `castAt` to now

#### Scenario: Vote is rejected after round closes
- **GIVEN** a VotingRound with `closedAt` set in the past
- **WHEN** a Participant attempts to cast a vote
- **THEN** the system returns a `400 Bad Request` with message "De stemronde is gesloten" and the vote is not recorded

---

### Requirement: REQ-VCT-002 Vote cast via email reply is accepted
The app SHALL accept votes cast by replying to the voting notification email with "Voor", "Tegen", or "Onthouding" (case-insensitive, first non-empty line). The `MailReplyHandler` background job processes the reply and calls `VotingService::castVote()`.

#### Scenario: Participant replies "Voor" to the voting notification email
- **GIVEN** an open VotingRound and a Participant who received the voting notification email
- **WHEN** the Participant replies with "Voor" as the first line of the reply body
- **THEN** `MailReplyHandler` recognises the keyword, calls `VotingService::castVote()` with `value: "for"`, and sends a confirmation email: "Uw stem 'Voor' is geregistreerd voor: [Motion title]"

#### Scenario: Unrecognised email reply triggers re-prompt
- **GIVEN** a Participant replies with an unrecognised body (e.g., "Ik ben er nog niet uit")
- **WHEN** `MailReplyHandler` processes the reply
- **THEN** the vote is NOT registered AND a re-prompt email is sent: "Uw antwoord kon niet worden herkend. Antwoord met 'Voor', 'Tegen', of 'Onthouding'."

#### Scenario: Third unrecognised reply disables email voting for that Participant
- **GIVEN** a Participant has sent three unrecognised replies for the same VotingRound
- **WHEN** the fourth reply is received
- **THEN** `MailReplyHandler` sends a final email asking the Participant to vote via the UI and stops processing email replies from that Participant for that round

---

### Requirement: REQ-VCT-003 Show-of-hands results are entered by the chair
The app SHALL provide a show-of-hands recording interface when `VotingRound.votingMethod = "show-of-hands"`. The chair enters the counted totals for for/against/abstain manually. Individual Vote objects are NOT created for show-of-hands rounds.

#### Scenario: Chair records show-of-hands result
- **GIVEN** an open VotingRound with `votingMethod: "show-of-hands"`
- **WHEN** the chair enters `voor: 18`, `tegen: 5`, `onthouding: 2` and clicks "Resultaat opslaan"
- **THEN** `VotingRound.votesFor = 18`, `votesAgainst = 5`, `votesAbstain = 2` are saved via `ObjectService.saveObject()` and no individual Vote objects are created

---

### Requirement: REQ-VCT-004 Live vote tally is visible to the chair during an open round
The app SHALL display a real-time vote tally (number of for/against/abstain/not-yet-voted) to users with role `chair` or `secretary` while a VotingRound is open. Other Participants see only the count of votes cast (not the breakdown) until the round closes.

#### Scenario: Chair sees partial results during voting
- **GIVEN** an open VotingRound with 10 votes cast (7 for, 2 against, 1 abstain) out of 32 Participants
- **WHEN** the chair views the VotingRoundPanel
- **THEN** the panel shows "Uitgebracht: 10 / 32 — Voor: 7, Tegen: 2, Onthouding: 1" refreshed every 5 seconds

#### Scenario: Member sees count only during voting
- **GIVEN** the same open VotingRound
- **WHEN** a Participant with role `member` views the panel
- **THEN** the panel shows "Uitgebracht: 10 / 32" without the for/against/abstain breakdown

---

### Requirement: REQ-VCT-005 WCAG AA vote casting interface
The vote casting interface SHALL meet WCAG 2.1 AA: all vote buttons are keyboard-navigable, labelled with ARIA, and the selected vote option is confirmed via a visible text label — not colour alone.

#### Scenario: Keyboard user casts a vote
- **GIVEN** a user navigating by keyboard
- **WHEN** the user tabs to the vote casting panel and presses Enter on "Voor"
- **THEN** the vote is submitted and a visible confirmation message "Uw stem 'Voor' is geregistreerd" is displayed without requiring mouse interaction

#### Scenario: Colour-blind user can identify their selected vote
- **GIVEN** a vote casting panel showing Voor / Tegen / Onthouding buttons
- **WHEN** the user has selected "Tegen"
- **THEN** the selected state is indicated by both colour AND a visible text marker (e.g., "✓ geselecteerd") so colour alone is not the only indicator
