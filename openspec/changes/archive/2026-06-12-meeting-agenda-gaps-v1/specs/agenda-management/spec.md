# Delta: agenda-management — meeting+agenda gaps v1

## MODIFIED Requirements

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
