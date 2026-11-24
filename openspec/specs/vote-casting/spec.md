---
status: done
openspec-changes:
  - decision-methods
---

# vote-casting Specification

## Purpose
Lets active participants cast and change their vote (for, against, or abstain) in an open voting round, with exactly one vote per participant. Votes can be cast through the UI or by replying to the voting notification email, the chair records show-of-hands rounds as manual totals, and a live tally is visible to the chair and secretary while members see only the count cast until the round closes. The casting interface meets WCAG 2.1 AA, and votes in a stage-linked round feed the decision-stage outcome.
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

### Requirement: Votes cast in a stage-linked round feed the stage outcome

When a `VotingRound` resolves a `DecisionStage` of `method=vote`, the `Vote` objects cast in that round SHALL tally into `VotingRound.result` exactly as for any round, and that result SHALL be the single source from which the stage `outcome` is derived. Casting, tallying, and ballot configuration are unchanged by the `decision-methods` capability; only the round's linkage to a stage is added.

#### Scenario: Casting votes resolves the linked stage

- **GIVEN** a `method=vote` DecisionStage linked to an open VotingRound
- **WHEN** participants cast their votes and the round closes with `result=adopted`
- **THEN** the stage outcome derives to `adopted` from the round with no separate per-stage tally

### Requirement: REQ-NPV-001 A chair opens a roll call round whose votes are recorded by name

The system SHALL offer `roll-call` as a voting method when a round is opened. On an open `roll-call` round the voting round panel SHALL NOT show members the for, against and abstain buttons, and SHALL show the chair and secretary a "Record votes by name" control.

#### Scenario: The chair opens a roll call

- GIVEN `chair1` chairs meeting "Raad 12 maart" and motion "Parkeerbeleid binnenstad" is in lifecycle `deliberating`
- WHEN `chair1` opens a round on the motion page and picks "Roll call, recorded by name"
- THEN the round is stored with `votingMethod: roll-call`
- AND `chair1` sees "Record votes by name"
- AND `member1` sees that a roll call is in progress and no vote buttons

### Requirement: REQ-NPV-002 The chair or secretary records each member's choice

The system SHALL expose `POST /api/voting-rounds/{id}/named-votes` to the chair and secretary of the round's meeting. It SHALL store one vote per listed participant with the given value, `entryMode: recorded`, `recordedBy` set to the caller, and `castAt` set to the time the vote was held. Posting a corrected list SHALL replace the earlier recorded votes of the same members, never add to them. A participant left out of the list SHALL have no vote.

#### Scenario: The griffier enters a paper roll call

- GIVEN a closed paper vote in meeting M where Doornbos and Bakker voted for and Yilmaz against
- AND `griffier1` is the secretary of M
- WHEN `griffier1` opens "Record votes by name" on the open `roll-call` round, sets the three choices and saves
- THEN three votes exist on the round, each naming its participant, with `entryMode: recorded` and `recordedBy: griffier1`
- AND the motion's "Votes" widget lists Doornbos for, Bakker for and Yilmaz against

#### Scenario: A whole party at once

- GIVEN the entry sheet lists five members of party "GroenLinks" and four of party "VVD"
- WHEN `griffier1` sets party "GroenLinks" to for and saves
- THEN each of the five GroenLinks members has a vote `for`
- AND the VVD members have no vote until a choice is set for them

#### Scenario: A correction does not double count

- GIVEN Yilmaz was recorded as `against`
- WHEN `griffier1` posts the list again with Yilmaz as `abstain`
- THEN the round holds one vote for Yilmaz, with value `abstain`

### Requirement: REQ-NPV-003 The recorded list is refused as a whole when any entry is wrong

The endpoint SHALL validate the whole list before it writes anything. It SHALL refuse the request, write nothing, and name the offending entries when the round is not open, is not `roll-call`, or is secret; when a participant is not an active participant of the round's meeting or is listed twice; when a value is not `for`, `against` or `abstain`; or when an entry would replace a vote the member cast themselves (`entryMode: cast`).

#### Scenario: A person outside the meeting

- GIVEN a list with three members of M and one participant of another meeting
- WHEN `griffier1` posts it
- THEN the response is 400 and names the one unknown participant
- AND no vote was written for any of the four

#### Scenario: A member's own vote is not overwritten

- GIVEN Bakker cast a vote `for` from their own account before the round became a paper vote
- WHEN `griffier1` posts a list that records Bakker as `against`
- THEN the response is 409 and names Bakker
- AND Bakker's own vote is unchanged

#### Scenario: No names on a secret ballot

- GIVEN an open round with `isSecret: true`
- WHEN anyone posts recorded votes to it
- THEN the response is 400 and says a secret ballot cannot be recorded by name

#### Scenario: Only the round's own chair or secretary

- GIVEN `member1` is a member of M without a presiding role
- WHEN `member1` posts recorded votes to a round in M
- THEN the response is 403 and nothing is written

### Requirement: REQ-NPV-004 Closing a roll call round computes the result from the recorded votes

Closing a `roll-call` round SHALL compute `votesFor`, `votesAgainst`, `votesAbstain` and `result` from its recorded votes, with the round's threshold, abstention and tie-break rules, through the same tally that counts cast votes.

#### Scenario: The recorded votes decide the motion

- GIVEN a `roll-call` round with 2 recorded `for` and 1 recorded `against` under a simple majority
- WHEN `chair1` closes the round
- THEN the round shows 2 for, 1 against, 0 abstain and result `adopted`
- AND the motion moves to lifecycle `decided` with outcome `adopted`

### Requirement: REQ-NPV-005 Closing a show-of-hands round keeps the totals the chair entered

Closing a `show-of-hands` round SHALL keep the `votesFor`, `votesAgainst` and `votesAbstain` saved through the tally endpoint and SHALL compute the result from them. It MUST NOT replace them with a count of `vote` objects, which a show-of-hands round does not have.

#### Scenario: Totals survive the close

- GIVEN a show-of-hands round where `chair1` saved 14 for, 9 against and 2 abstain
- WHEN `chair1` closes the round
- THEN the round still shows 14 for, 9 against and 2 abstain
- AND its result is `adopted`, not `invalid`
