# case-system-exchange Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [platform-case-system-document-exchange](../../) (this delta)

## Purpose

Links agenda items to cases in the organisation's case management system,
fetches case documents onto an item, and sends the meeting file back after the
minutes are approved, through integriq's ZGW and StUF-ZKN adapters. Closes
matrix rows plt-23 and plt-24.

**Standards**: VNG ZGW APIs (Zaken API, Documenten API), StUF-ZKN 3.10, ORI
`Vergadering` and `Besluit`.

## ADDED Requirements

### Requirement: REQ-CSDX-001 The case system is an integriq connection

decidiq SHALL declare a `case-system` connection and SHALL reach the case
system only through integriq's call service against the linked source. Without
a linked source, the case actions SHALL NOT be shown and their endpoints SHALL
answer 409 with "No case system is connected".

#### Scenario: No case system linked

- GIVEN no source is linked to the `case-system` connection
- WHEN the griffier opens an agenda item
- THEN no case actions are shown
- @e2e exclude visibility follows the connection status; covered by PHPUnit on the controller returning 409 and a vitest on the widget

### Requirement: REQ-CSDX-002 An agenda item links to its case

`AgendaItem` SHALL carry an optional `caseReference` with the case's address,
its case number and its title. Saving a reference SHALL read the case once
through integriq and SHALL be refused when the case cannot be found.

#### Scenario: The griffier links a raadsvoorstel to its case

- GIVEN the agenda item "Vaststelling omgevingsvisie" and a connected case system
- WHEN the griffier enters case number "Z-2026-00412" on the item and saves
- THEN the item shows the case "Omgevingsvisie 2040" with its number

#### Scenario: A case that does not exist is refused

- GIVEN a connected case system without case "Z-2026-99999"
- WHEN the griffier enters that number
- THEN the save is refused with "Case Z-2026-99999 was not found in the case system"
- @e2e exclude adapter path; covered by PHPUnit with a stubbed integriq call

### Requirement: REQ-CSDX-003 The griffier fetches case documents onto the item, once each

On an agenda item with a case, "Fetch documents from the case" SHALL list the
case's documents, mark the ones already fetched, and copy the chosen ones into
the item's folder. Each fetched document SHALL be recorded with its address in
the case system and SHALL NOT be fetched twice.

#### Scenario: The griffier fetches the raadsvoorstel

- GIVEN the linked item and a case holding "Raadsvoorstel omgevingsvisie.pdf" and "Bijlage 1 kaart.pdf"
- WHEN the griffier opens "Fetch documents from the case", ticks both and confirms
- THEN both files are in the item's Documents widget and the dialog marks them as fetched

### Requirement: REQ-CSDX-004 Approving the minutes produces a decision list document

When a meeting's minutes reach `approved`, and again at `signed`, decidiq SHALL
render a decision list of every decision taken under the meeting's agenda
items with title, outcome and vote totals, headed with the approval date and
the signers recorded on the minutes, as `Besluitenlijst.pdf` in the meeting
folder through filinq.

#### Scenario: The decision list follows the minutes

- GIVEN a council meeting with three decisions and minutes signed by the chair and the griffier
- WHEN the minutes reach `signed`
- THEN the meeting folder holds a decision list with the three decisions, their outcomes and both signers
- @e2e exclude document rendering; covered by PHPUnit on DecisionListService with a stubbed FilinqPdf

### Requirement: REQ-CSDX-005 The meeting file goes back to the case system after approval

After the minutes are `approved`, the chair or secretary SHALL be able to send
the meeting file to the case system from the minutes page, and an
administrator SHALL be able to have it sent on approval. The meeting file
SHALL hold the last published agenda, each item's documents, the decision
list, the minutes and the proof package. Each item's decisions SHALL go to its
own case, and the whole file SHALL go to one case created for the meeting.
Documents under a confidentiality restriction SHALL be sent marked
confidential with their ground. Sending before approval SHALL be refused.

#### Scenario: The griffier sends the file after the council meeting

- GIVEN approved minutes for the council meeting of 12 March and one item linked to case Z-2026-00412
- WHEN the griffier presses "Send the meeting file to the case system" on the minutes page
- THEN the item's decision reaches case Z-2026-00412, a case for the meeting receives the whole file, and the Case system widget on the meeting page shows every document as sent

#### Scenario: Sending before approval is refused

- GIVEN minutes in `review`
- WHEN `POST /api/meetings/{id}/case-system/send` is called
- THEN the response is 422 with "Approve the minutes before sending the meeting file"
- @e2e exclude API contract; covered by PHPUnit on CaseSystemExchangeService

#### Scenario: A confidential document is sent marked

- GIVEN an item document under a confidentiality restriction on ground "Gemeentewet artikel 25"
- WHEN the meeting file is sent
- THEN that document's line carries confidential with its ground in the request to integriq
- @e2e exclude adapter payload; covered by PHPUnit with a stubbed integriq call

### Requirement: REQ-CSDX-006 Every exchange is recorded and a failed document is sent again on request

Each fetch and send SHALL be a `CaseExchangeRecord` with a line per document
holding its status (`pending`, `sent`, `failed`), the remote address or the
error. The meeting page SHALL show a "Case system" widget with the records and
a "Send again" action that resends only failed lines. A failed line SHALL NOT
be retried automatically.

#### Scenario: One document failed

- GIVEN a send with five lines `sent` and one `failed` with "The case system refused the document"
- WHEN the griffier presses "Send again" in the Case system widget
- THEN only the failed line is sent, and the widget shows its new status
