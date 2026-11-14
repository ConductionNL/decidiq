# motion-amendment Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [motion-change-recommendations](../../) (this delta)

## Purpose

The chair or secretary can propose text changes to a motion line by line as recommendations, and accept or reject each one without a vote. Covers the unbuilt half of matrix row mot-19. Screen: board DcVoorstel, with the tracked change of board DcAmendement.

## ADDED Requirements

### Requirement: REQ-MCR-001 The motion text can be read with stable line numbers

The motion page SHALL offer a line-numbered view of the motion text. Lines MUST break at word boundaries at no more than 80 characters, a paragraph break MUST start a new line, and the same text MUST always produce the same numbers.

#### Scenario: The griffier turns on line numbers

- GIVEN the motion "Verlichting fietspaden 2027" with three paragraphs
- WHEN the griffier turns on Regelnummers on the motion page
- THEN every line of the text shows its number, starting at 1
- AND opening the page again shows the same numbers on the same lines

### Requirement: REQ-MCR-002 A recommendation names its lines, its passage and its new wording

The chair or secretary SHALL be able to recommend a change by picking a first and last line, writing the new wording and an optional reason. The system MUST store a `change-recommendation` linked to the motion with `lineFrom`, `lineTo`, the exact text of those lines as `targetPassage`, `proposedText`, `reason` and status `pending`. Anyone else MUST NOT be able to create one.

#### Scenario: The griffier recommends a corrected amount

- GIVEN lines 4 and 5 of the motion read "Een krediet van 640.000 euro beschikbaar stellen"
- WHEN the griffier picks lines 4 to 5 under Wijziging aanbevelen, enters "Een krediet van 645.000 euro beschikbaar stellen" and the reason "Bedrag uit de begrotingswijziging"
- THEN a pending recommendation for lines 4 to 5 shows under Aanbevolen wijzigingen

#### Scenario: A member cannot recommend

- GIVEN a council member who is not chair or secretary of the meeting
- WHEN she opens the motion page
- THEN Wijziging aanbevelen is not shown
- AND creating a recommendation through the API is refused

### Requirement: REQ-MCR-003 The chair or secretary accepts a recommendation into the text without a vote

When the chair or secretary accepts a pending recommendation, the system SHALL replace its `targetPassage` in the motion text with its `proposedText` as an adopted amendment does (REQ-AMT-001): `originalText` keeps the first wording and `amendmentHistory` gains an entry that names the recommendation. The recommendation MUST become `accepted` with who decided and when. No voting round SHALL be opened. Accepting MUST be refused once the motion is in `voting` or later.

#### Scenario: Accepting the corrected amount

- GIVEN the pending recommendation for lines 4 to 5
- WHEN the secretary presses Accept
- THEN the motion text reads "645.000 euro"
- AND the recommendation shows Geaccepteerd with the secretary's name and the date
- AND the motion's history has an entry for this recommendation

#### Scenario: Too late to accept

- GIVEN a motion whose voting round is open
- WHEN the chair presses Accept on a pending recommendation
- THEN the request is refused and the text is unchanged

### Requirement: REQ-MCR-004 A rejected recommendation changes nothing

When the chair or secretary rejects a pending recommendation, the system SHALL set it to `rejected` with who decided and when, and MUST NOT change the motion. A rejected recommendation MUST stay listed.

#### Scenario: Rejecting a wording suggestion

- GIVEN a pending recommendation to reword line 9
- WHEN the chair presses Reject
- THEN the recommendation shows Afgewezen
- AND the motion text and history are unchanged

### Requirement: REQ-MCR-005 A recommendation whose passage has changed cannot be accepted

When a recommendation's `targetPassage` no longer occurs in the motion text, the system SHALL show it as "Passage changed" and MUST refuse to accept it, leaving the motion and the recommendation unchanged.

#### Scenario: An amendment changed the lines first

- GIVEN a pending recommendation on lines 4 to 5
- AND an adopted amendment that rewrote line 4
- WHEN the secretary presses Accept on the recommendation
- THEN the request is refused with "The passage has changed since this recommendation was made"
- AND the recommendation stays pending, marked Passage changed

### Requirement: REQ-MCR-006 The motion page lists every recommendation with its status

The motion page SHALL show an "Aanbevolen wijzigingen" card below Amendementen listing every recommendation of the motion, oldest first, with its lines, its status and, when opened, the tracked change between the passage and the new wording. Accept and Reject MUST show only to the chair and secretary, and only on a pending recommendation.

#### Scenario: A member reads the recommendations

- GIVEN a motion with one accepted, one rejected and one pending recommendation
- WHEN a council member opens the motion page
- THEN all three are listed with their status
- AND opening one shows the removed words struck through in red and the added words underlined in green
- AND no Accept or Reject button is shown to her
