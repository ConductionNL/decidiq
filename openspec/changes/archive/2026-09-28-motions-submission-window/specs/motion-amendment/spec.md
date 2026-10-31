# motion-amendment Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [motions-submission-window](../../) (this delta)

## Purpose

Completes the submission window for motions and amendments with an opening time. Closes decidiq matrix row mot-18.

**Standards**: Reglement van Orde (rules of procedure), OpenRaadsinformatie `Motie`, `Amendement`.

## ADDED Requirements

### Requirement: REQ-SUBW-001 A meeting can open submission at a set time

A meeting SHALL carry an optional `submissionOpensAt` beside `submissionDeadline`, and the meeting page SHALL show both.

#### Scenario: The griffier sets the window
- GIVEN the council meeting of 14 October
- WHEN the griffier sets submission to open on 4 October at 09:00 and close on 13 October at 12:00
- THEN the meeting's Planning widget shows "Submission opens 4 October 09:00" and "Submission deadline 13 October 12:00"

### Requirement: REQ-SUBW-002 A motion or amendment submitted before the window opens is refused

The app SHALL refuse, with HTTP 422 and a message naming the opening time, the creation of a motion or an amendment for a meeting whose `submissionOpensAt` lies in the future. Without an opening time, submission SHALL be open until the deadline, as before.

#### Scenario: A member is too early
- GIVEN submission for the council meeting of 14 October opens on 4 October at 09:00
- WHEN council member Pieter submits a motion on 2 October
- THEN the motion is refused with "Submission of motions and amendments for this meeting opens on 4 October 2026 09:00."

#### Scenario: Inside the window
- GIVEN the same meeting
- WHEN Pieter submits the motion on 6 October
- THEN the motion is created

### Requirement: REQ-SUBW-003 A window that opens after it closes is refused

The app SHALL refuse to save a meeting whose `submissionOpensAt` is not before its `submissionDeadline`.

#### Scenario: A typo in the dates
- GIVEN the griffier sets submission to open on 14 October and close on 13 October
- WHEN she saves the meeting
- THEN the save is refused with "The submission window opens after it closes."
