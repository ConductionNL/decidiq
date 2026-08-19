# embargo-geheimhouding Specification

## Purpose
A `geheimhouding` record moves through a legally meaningful lifecycle — imposed, awaiting/passed bekrachtiging (ratification), then dissolved — and a griffie needs to see that progression and its legal ground at a glance. This spec adds a status-timeline widget to `GeheimhoudingDetail` on top of the page skeleton the `embargo-geheimhouding` change already shipped, and fixes a genuine key-naming defect on the `Geheimhoudingen` index.

## Requirements

### Requirement: REQ-EMB-010 Confidentiality status timeline widget on GeheimhoudingDetail

The `GeheimhoudingDetail` page MUST render a `confidentiality-status-timeline` widget showing the record's lifecycle as an ordered sequence — imposed (`imposedAt`, `imposedBy`/`imposedByBody`) → bekrachtiging (`ratificationDeadline`, and once set, `ratificationDate`/`ratificationDecision`/`ratificationAgendaItem`) → dissolution (`liftingDate`/`dissolutionDecision`, when present) — with each populated stage showing its date and, where set, a resolved link to the relevant Decision/AgendaItem. Stages that have not yet occurred (e.g. dissolution on a still-`imposed` record) MUST render as pending, not omitted, so the reader sees the full expected sequence.

#### Scenario: Imposed-only record shows two pending stages
- GIVEN a `geheimhouding` with `lifecycle: "imposed"`, `imposedAt` set, and no ratification or dissolution fields set
- WHEN the user opens `GeheimhoudingDetail`
- THEN the timeline shows the imposed stage populated and the bekrachtiging + dissolution stages as pending

#### Scenario: Ratified record links to its ratification decision
- GIVEN a `geheimhouding` with `ratificationDate` and `ratificationDecision` set
- WHEN the user views the timeline
- THEN the bekrachtiging stage shows the ratification date and a link to the ratification Decision

#### Scenario: Overdue bekrachtiging is visually distinguished
- GIVEN a `geheimhouding` with `lifecycle: "imposed"` and `ratificationDeadline` in the past
- WHEN the user views the timeline
- THEN the bekrachtiging stage renders an overdue indicator distinct from a not-yet-due pending stage

### Requirement: REQ-EMB-011 Confidentiality ground resolves with legacy citation on GeheimhoudingDetail

`GeheimhoudingDetail` MUST resolve the record's `ground` reference to its `GeheimhoudingGrond` object and display its citation, and its `legacyCitation` when set (pre-2023 Gemeentewet article numbering), alongside the current citation rather than only the raw ground identifier.

#### Scenario: Ground with a legacy citation shows both citations
- GIVEN a `geheimhouding` whose resolved `ground` has both `citation` and `legacyCitation` set
- WHEN the user opens `GeheimhoudingDetail`
- THEN both the current and legacy citation render

### Requirement: REQ-EMB-012 Target reference resolves to its actual object type

`GeheimhoudingDetail` MUST resolve whichever of `targetDocument`, `targetAgendaItem`, or `targetDecision` is set on the record to a link to that object's own detail page, labelled with its object type, rather than showing a bare UUID.

#### Scenario: Document-targeted geheimhouding links to the document
- GIVEN a `geheimhouding` with `targetDocument` set and the other two target fields empty
- WHEN the user opens `GeheimhoudingDetail`
- THEN a labelled link to that document's detail page renders

### Requirement: REQ-EMB-013 Geheimhoudingen index bekrachtiging-deadline column references the correct schema field

The `Geheimhoudingen` index's bekrachtiging-deadline column MUST be keyed on the `Geheimhouding` schema's actual `ratificationDeadline` property (not a non-existent `bekrachtigingDeadline` key), so the column renders real data through the schema's declared `format: "date"` instead of rendering blank.

#### Scenario: Index shows the real ratification deadline
- GIVEN a `geheimhouding` with `ratificationDeadline` set
- WHEN the user views the `Geheimhoudingen` index
- THEN the bekrachtiging-deadline column shows that date, formatted
