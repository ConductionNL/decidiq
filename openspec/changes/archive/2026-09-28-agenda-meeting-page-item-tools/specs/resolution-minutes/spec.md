# resolution-minutes Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-meeting-page-item-tools](../../) (this delta)

## Purpose

Puts the per-item minutes editor on the minutes page, so a secretary reaches it without the unlinked live screen. Closes decidiq matrix row min-03.

**Standards**: Akoma Ntoso `debateSection`, OpenRaadsinformatie `Verslag`.

## ADDED Requirements

### Requirement: REQ-AMP-005 The minutes page carries the per-item minutes editor

The minutes page SHALL show a widget with one note field per regular agenda item of the minutes' meeting, backed by the same `MinutesPanel` component and the same `itemNotes` property the live screen uses. The fields SHALL be editable while the minutes are in draft and read-only once the minutes are submitted, approved or signed.

#### Scenario: The secretary writes the minutes after the meeting
- GIVEN draft minutes of a meeting with the items Opening, Budget and Any other business
- WHEN the secretary opens the minutes page and types "Adopted without a vote" under Budget
- THEN the note is saved to the minutes and still shows after a reload

#### Scenario: Approved minutes cannot be changed here
- GIVEN minutes that the council approved on 1 November
- WHEN the secretary opens the minutes page
- THEN the per-item notes show as text and no field can be edited
