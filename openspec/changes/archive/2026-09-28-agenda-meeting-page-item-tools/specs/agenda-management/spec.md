# agenda-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-meeting-page-item-tools](../../) (this delta)

## Purpose

Brings the agenda tools a chair and a secretary need to the meeting page, where they already work, and links the live meeting screen from it. Closes decidiq matrix rows age-02 and age-03.

**Standards**: Schema.org `ItemList` and `ListItem`, WCAG 2.2 SC 2.5.7 (dragging movements).

## ADDED Requirements

### Requirement: REQ-AMP-001 The meeting page asks the server for the caller's meeting roles

The app SHALL expose `GET /api/meetings/{meetingId}/my-roles`, answering `chair`, `secretary` and `admin` as booleans for the signed-in caller only. It SHALL read the roles through `ParticipantResolver::hasRole()`, the same resolver `AgendaAuthorizationGuard::requireChairOrAdmin()` uses, so the page shows a control exactly when the server would accept the call behind it.

#### Scenario: A secretary is recognised on the meeting page
- GIVEN Sanne is a participant of the council meeting of 14 October with role secretary
- WHEN her browser calls `GET /api/meetings/{meetingId}/my-roles`
- THEN the response is 200 with `chair: false`, `secretary: true`, `admin: false`

#### Scenario: Nobody is answered for someone else
- GIVEN no signed-in session
- WHEN the endpoint is called
- THEN the response is 401 and names no roles

### Requirement: REQ-AMP-002 A chair or secretary reorders the agenda on the meeting page

The agenda widget on the meeting page SHALL let a chair, a secretary or an admin put agenda items in a new order by dragging a row, and SHALL offer Move up and Move down on every row as the keyboard alternative. A parent item SHALL carry its sub-items along. The new order SHALL be saved in one call to `PUT /api/agendas/{meetingId}/reorder`. Users without one of these roles SHALL see no drag handle and no move actions.

#### Scenario: The chair drags an item to the top
- GIVEN the chair opens the meeting page of a meeting with items 1 Opening, 2 Minutes, 3 Budget
- WHEN she drags Budget above Opening
- THEN the agenda reads 1 Budget, 2 Opening, 3 Minutes after a reload

#### Scenario: A secretary moves an item with the keyboard
- GIVEN the secretary focuses the row Minutes
- WHEN he chooses Move up
- THEN Minutes becomes item 1 and the order is saved

#### Scenario: A member cannot reorder
- GIVEN a council member without the chair or secretary role
- WHEN he opens the meeting page
- THEN the agenda shows no drag handle and no Move up or Move down action

### Requirement: REQ-AMP-003 Every agenda row opens its item page

Each row of the agenda widget SHALL carry an Open action that goes to the agenda item page (`/agenda-items/{id}`), where the item's documents are attached. Clicking the row itself SHALL keep opening the edit form.

#### Scenario: A clerk attaches a paper to one item
- GIVEN the clerk sees the agenda of next week's committee meeting
- WHEN she chooses Open on the row Budget 2027
- THEN the agenda item page opens with its Documents widget, and a file she adds there is listed on that item only

### Requirement: REQ-AMP-004 The meeting page links the live meeting screen

The agenda widget header SHALL show an Open live meeting button to a chair, a secretary or an admin, going to `/meetings/{id}/live`. Other users SHALL not see it.

#### Scenario: The chair starts running the meeting
- GIVEN the chair opens the meeting page on the evening of the meeting
- WHEN she chooses Open live meeting
- THEN the live meeting screen of that meeting opens

#### Scenario: A member does not see the button
- GIVEN a member without the chair or secretary role
- WHEN he opens the meeting page
- THEN no Open live meeting button is shown
