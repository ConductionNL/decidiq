---
status: complete
status-note: 2026-06-12 — the three audit gaps (statutory ALV agenda-item enforcement, hierarchical sub-items, meeting document-package assembly) closed by meeting-agenda-gaps-v1 (agendaRules statutory warning in AgendaBuilder + MeetingAgendaTab, additive AgendaItem.parentItem with nested rendering + grouped reorder, MeetingPackageService + "Assemble meeting package" action). Earlier requirements built via agenda-builder/-item-crud/-live-management/-publication + meeting-agenda capabilities. Known soft spot — the "agenda exceeds scheduled meeting length" warning shows total duration only, no exceed-comparison.
---

# Agenda Management Specification

## Purpose

Agenda management handles the creation, ordering, and conduct of meeting agendas. An agenda is a structured list of items to be discussed at a meeting, each with a type (informational, discussion, decision), allocated time, and attached documents. The system supports drag-and-drop reordering, legally required items for specific meeting types (e.g., ALV statutory items), and real-time agenda tracking during meetings.

**Standards**: Schema.org (`ItemList`, `ListItem`), Akoma Ntoso (`debateSection`, `pointOfOrder`), OpenRaadsinformatie (`AgendaPunt`)
**Feature tier**: MVP

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full AgendaItem entity definition including property tables, Schema.org mappings, and OpenRaadsinformatie alignment.
## Requirements

---

### Requirement: Agenda Item Creation

The system MUST support creating agenda items with a title, type (informational, discussion, decision), description, allocated time, presenter, and attached documents. Agenda items MUST be stored as OpenRegister objects in the `decidesk` register using the `agendaItem` schema.

**Feature tier**: MVP

#### Scenario: Create a decision agenda item

- GIVEN a user preparing an agenda for the board meeting
- WHEN they add an agenda item with title "Approve Q1 Budget", type "decision", allocated time 20 minutes, and presenter "CFO"
- THEN the system MUST create an OpenRegister object with the `agendaItem` schema
- AND the item MUST appear at the end of the agenda list
- AND the item MUST have a sequential order number

#### Scenario: Create an informational agenda item with documents

- GIVEN a user preparing a meeting agenda
- WHEN they add an agenda item with title "Management Report" and type "informational" and attach a PDF document
- THEN the document MUST be linked to the agenda item via Nextcloud Files
- AND meeting participants MUST be able to access the document

#### Scenario: Submit a member proposal for the agenda

- GIVEN a member of a governing body with an upcoming meeting
- WHEN they submit a motion or proposal for the agenda with title and supporting arguments
- THEN the proposal MUST be submitted for chair review
- AND the chair MUST be notified of the pending proposal
- AND the chair MUST be able to accept or reject the agenda addition

---

### Requirement: Agenda Ordering and Structure

The system MUST support drag-and-drop reordering of agenda items. The system MUST enforce legally required items for specific meeting types (e.g., ALV must include annual report, financial statements, kascommissie report, board elections) by warning about — and listing — the missing statutory items whenever a `general_assembly` agenda is incomplete. Sub-items MUST be supported for grouping related topics: an additive `parentItem` property on AgendaItem nests an item under its parent, sub-items render nested with their own type and allocated time, and reordering keeps children grouped under their parent (the flattened parent→children order is persisted through the existing reorder endpoint).

**Feature tier**: MVP

#### Scenario: Reorder agenda items via drag-and-drop

- GIVEN a meeting agenda with 5 items
- WHEN the user drags item 4 to position 2
- THEN the order numbers MUST update automatically for all items
- AND the new order MUST persist immediately

#### Scenario: Enforce legally required ALV agenda items

- GIVEN a meeting of type "general_assembly" for an association
- WHEN the user creates the agenda
- THEN the system MUST prompt to include required items: opening, approval of previous minutes, annual report, financial statements, kascommissie report, board elections, any other business, closing
- AND missing required items MUST be highlighted with a warning

#### Scenario: Group agenda items with sub-items

- GIVEN an agenda item "Committee Reports"
- WHEN the user adds sub-items "Finance Committee" and "Audit Committee"
- THEN the sub-items MUST appear nested under the parent item
- AND each sub-item MUST have its own allocated time, type, and presenter

#### Scenario: Sub-items stay grouped under their parent when reordering

@e2e exclude pure ordering arithmetic; covered by agendaRules vitest (buildAgendaTree + flattenTree) — the nesting surface itself is asserted by the sub-item e2e test

- GIVEN an agenda where "Committee Reports" has two sub-items
- WHEN the user moves another top-level item above "Committee Reports"
- THEN the sub-items MUST keep following their parent in the persisted order
- AND sub-items MUST only reorder within their own sibling group

### Requirement: Agenda Time Management

The system MUST calculate total meeting duration from individual item allocations. The system MUST warn when total allocated time exceeds a configured meeting length. During meetings, the system MUST track time spent per agenda item.

**Feature tier**: MVP

#### Scenario: Calculate total agenda duration

- GIVEN agenda items with allocated times of 10, 20, 15, 30, and 5 minutes
- WHEN the user views the agenda summary
- THEN the total duration MUST be displayed as "1 hour 20 minutes"
- AND if the meeting is scheduled for 1 hour, a warning MUST indicate the agenda exceeds the scheduled duration by 20 minutes

#### Scenario: Track time during meeting conduct

- GIVEN a meeting in progress on agenda item 3 of 5
- WHEN the allocated time for item 3 (15 minutes) has elapsed
- THEN the system MUST display a time-over indicator
- AND the chair MUST be able to extend the time or move to the next item

---

### Requirement: Agenda Document Package

The system MUST support assembling all agenda item documents into a single meeting package (vergaderstukken) for distribution to participants. Assembly MUST produce a structured folder in Nextcloud Files (one `NN - <item title>/` folder per agenda item, ordered by item number) with a generated table of contents, and unresolvable documents MUST be reported as skipped rather than failing the assembly.

**Feature tier**: MVP

#### Scenario: Assemble meeting package from agenda documents

- GIVEN a meeting with 5 agenda items, each with one or more attached documents
- WHEN the secretary triggers "Assemble meeting package"
- THEN the system MUST create a structured document package with a table of contents
- AND documents MUST be organized by agenda item number and title
- AND the package MUST be available for download and distribution via convocation

#### Scenario: Package assembly reports skipped documents instead of failing

@e2e exclude defensive file-copy semantics; covered by MeetingPackageServiceTest (PHPUnit) — the package action surface is asserted by the assemble-package e2e test

- GIVEN a meeting whose agenda items include a document that cannot be resolved
- WHEN the package is assembled
- THEN the assembly MUST complete successfully
- AND the unresolvable document MUST be listed in the `skipped` report

## User Stories

1. **Board secretary creating AGM agenda**: As a board secretary, I want to create and manage the AGM agenda with drag-and-drop resolution ordering, so that I can efficiently prepare a compliant meeting agenda within statutory deadlines. (Source: intelligence DB #1)

2. **Chair composing ALV agenda**: As chair, I want to compose the ALV agenda ensuring all legally required items are included (annual report, financial statements, kascommissie report, board elections) so that the meeting is legally valid. (Source: intelligence DB #49)

3. **MT member submitting agenda item**: As an MT member, I want to submit agenda items with supporting documents through a structured form, so that the secretary can compile a complete and well-organized agenda. (Source: intelligence DB #87)

4. **Member submitting a motion for agenda**: As a member, I want to submit a motion or proposal for the ALV agenda with supporting arguments so that my topic is formally discussed and voted on. (Source: intelligence DB #54)

5. **Management assistant compiling agenda package**: As a management assistant, I want to compile submitted agenda items into a structured agenda and distribute the complete package to all MT members, so that everyone is prepared for the meeting. (Source: intelligence DB #88)

## Acceptance Criteria

- Agenda items are stored as OpenRegister objects with sequential ordering
- Drag-and-drop reordering persists immediately with automatic order number recalculation
- Legally required items are enforced per meeting type with warnings for missing items
- Time allocation is tracked per item with over-time warnings
- Meeting document packages can be assembled from agenda attachments
- Sub-items are supported for hierarchical agenda structure
- OpenRaadsinformatie `AgendaPunt` mapping is available
