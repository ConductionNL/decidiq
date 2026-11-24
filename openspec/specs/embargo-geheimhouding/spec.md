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

@e2e exclude the underlying `buildConfidentialityStages` computation is covered by tests/vitest/registerDetailWidgets.spec.js::"an imposed-only record shows the imposed stage populated and the other two pending"; tests/e2e/spec-coverage/register-detail-widgets.spec.ts's GeheimhoudingDetail test exercises the imposed stage populated, not this exact three-stage pending pattern in one page load — the Vue rendering of the pending-vs-populated distinction has no direct e2e assertion.

#### Scenario: Ratified record links to its ratification decision
- GIVEN a `geheimhouding` with `ratificationDate` and `ratificationDecision` set
- WHEN the user views the timeline
- THEN the bekrachtiging stage shows the ratification date and a link to the ratification Decision

@e2e exclude the underlying stage-computation (ratification stage `populated: true` once `ratificationDate`/`ratificationDecision` are set) is covered by tests/vitest/registerDetailWidgets.spec.js::"never flags overdue once the record has moved past imposed..."; no e2e test seeds a ratified geheimhouding and asserts the timeline renders a working link to the ratification Decision — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Overdue bekrachtiging is visually distinguished
- GIVEN a `geheimhouding` with `lifecycle: "imposed"` and `ratificationDeadline` in the past
- WHEN the user views the timeline
- THEN the bekrachtiging stage renders an overdue indicator distinct from a not-yet-due pending stage

@e2e exclude the underlying overdue-flagging logic is covered by tests/vitest/registerDetailWidgets.spec.js::"flags the ratification stage overdue when the deadline has passed and lifecycle is still imposed" / "does not flag overdue when the deadline is still in the future"; no e2e test seeds an overdue geheimhouding and asserts the visual indicator renders — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

### Requirement: REQ-EMB-011 Confidentiality ground resolves with legacy citation on GeheimhoudingDetail

`GeheimhoudingDetail` MUST resolve the record's `ground` reference to its `GeheimhoudingGrond` object and display its citation, and its `legacyCitation` when set (pre-2023 Gemeentewet article numbering), alongside the current citation rather than only the raw ground identifier.

@e2e exclude tests/e2e/spec-coverage/register-detail-widgets.spec.ts asserts the ground resolves to its citation text ("Geheimhouding raadsstukken") via `confidentiality-ground`, but the seeded fixture it uses is not confirmed to carry a `legacyCitation`, so the "both citations render together" half of this scenario is untested — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Ground with a legacy citation shows both citations
- GIVEN a `geheimhouding` whose resolved `ground` has both `citation` and `legacyCitation` set
- WHEN the user opens `GeheimhoudingDetail`
- THEN both the current and legacy citation render

### Requirement: REQ-EMB-012 Target reference resolves to its actual object type

`GeheimhoudingDetail` MUST resolve whichever of `targetDocument`, `targetAgendaItem`, or `targetDecision` is set on the record to a link to that object's own detail page, labelled with its object type, rather than showing a bare UUID.

@e2e exclude no current e2e or unit test asserts the polymorphic target-reference resolution (`targetDocument`/`targetAgendaItem`/`targetDecision`) on GeheimhoudingDetail; the shared `resolveObjectLabel` mechanism this widget likely reuses is generically covered by tests/vitest/registerDetailWidgets.spec.js, but not this requirement's specific type-labelled-link behaviour — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Document-targeted geheimhouding links to the document
- GIVEN a `geheimhouding` with `targetDocument` set and the other two target fields empty
- WHEN the user opens `GeheimhoudingDetail`
- THEN a labelled link to that document's detail page renders

### Requirement: REQ-EMB-013 Geheimhoudingen index bekrachtiging-deadline column references the correct schema field

The `Geheimhoudingen` index's bekrachtiging-deadline column MUST be keyed on the `Geheimhouding` schema's actual `ratificationDeadline` property (not a non-existent `bekrachtigingDeadline` key), so the column renders real data through the schema's declared `format: "date"` instead of rendering blank.

@e2e exclude a manifest-column-key correctness fix — checkable directly by inspecting the `Geheimhoudingen` index column definition against the `Geheimhouding` schema's property names; no e2e test opens the index and asserts the deadline column is non-blank for a seeded row — genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

#### Scenario: Index shows the real ratification deadline
- GIVEN a `geheimhouding` with `ratificationDeadline` set
- WHEN the user views the `Geheimhoudingen` index
- THEN the bekrachtiging-deadline column shows that date, formatted

### Requirement: REQ-EMB-020 A restriction records its imposing decision and planned end date

A confidentiality restriction SHALL carry an optional `imposingDecision` (reference to the decision that imposed it), an optional `liftBy` date (the latest planned lifting) beside the free-text `liftingConditions`, and `sentToBodyAt` with `sentWithAgendaItem` (when and with which agenda item the restricted papers went to the ratifying body). The detail page SHALL show the imposing decision as a link, and Voorwaarden opheffing as the conditions followed by "uiterlijk <liftBy>". A `liftBy` before `imposedAt` SHALL be refused.

#### Scenario: Imposed by a college decision with an end date
- GIVEN the college imposes a restriction on "Onderbouwing tarieven grondexploitatie.pdf" by Collegebesluit 2026-0912 with conditions "Na gunning van de grondexploitatie" and liftBy 1 July 2027
- WHEN the restriction page opens
- THEN Opgelegd links to Collegebesluit 2026-0912
- AND Voorwaarden opheffing reads "Na gunning van de grondexploitatie, uiterlijk 1 juli 2027"

#### Scenario: An end date before the start
- GIVEN a restriction imposed on 10 September 2026
- WHEN a clerk saves liftBy 1 September 2026
- THEN the save is refused with a message naming both dates

#### Scenario: Sent to the council
- GIVEN the restricted paper is attached to the council's agenda item "Raadsvoorstel tarieven grondexploitatie" on 24 September
- WHEN the restriction page opens
- THEN its timeline shows "24 september 2026, Met het raadsvoorstel naar de raad"

### Requirement: REQ-EMB-021 A restriction the ratifying body did not ratify lapses

The restriction lifecycle SHALL have a terminal state `lapsed`, reachable from `imposed` only. When a meeting closes that carries a restriction's `ratificationAgendaItem`, and the restriction is still `imposed` on a ground with `requiresRatification`, and the item records neither a ratification decision nor that it was postponed, the system SHALL move the restriction to `lapsed`, write an audit entry naming the meeting and the item, and notify the secretariat. A clerk SHALL also be able to record "niet bekrachtigd" on the item, with the same effect. A lapsed restriction SHALL no longer keep its target from publication, and its target SHALL go through the normal publication check rather than becoming public.

#### Scenario: The council does not ratify
- GIVEN a restriction imposed by the college on a ground that needs ratification, with its ratification item on the council meeting of 15 October
- WHEN that meeting closes and the item records no ratification
- THEN the restriction is lapsed, the timeline step reads Vervallen with the meeting, and the griffie gets a notification

#### Scenario: The item was postponed
- GIVEN the same restriction
- WHEN the meeting closes with the item marked postponed
- THEN the restriction stays imposed and its ratification deadline shows overdue

#### Scenario: The council ratifies
- GIVEN the same restriction
- WHEN the item records the ratification decision before the meeting closes
- THEN the restriction is ratified and never lapses

#### Scenario: A ground without ratification
- GIVEN a restriction on a ground with `requiresRatification` false
- WHEN any meeting closes
- THEN the restriction does not change

### Requirement: REQ-EMB-022 The planned end date puts the lifting on the agenda

When a restriction in `imposed` or `ratified` has a `liftBy` within the lead time (an admin setting, default 30 days) and no lifting agenda item yet, the system SHALL place an agenda item proposing to lift it on the next scheduled meeting of the body that ratified it, or of the imposing body when it needs no ratification, and SHALL show the restriction as "opheffing voorbereiden" in the register. After `liftBy` passes without a lifting decision the register SHALL show it overdue. The system SHALL NOT change the restriction's state on the date alone.

#### Scenario: Thirty days before the end date
- GIVEN a ratified restriction with liftBy 1 July 2027 and a council meeting on 24 June 2027
- WHEN the daily job runs on 1 June 2027
- THEN an agenda item "Opheffen geheimhouding Tarieven grondexploitatie" exists on the 24 June meeting
- AND the restriction stays ratified

#### Scenario: The date passes
- GIVEN the same restriction with no lifting decision on 2 July 2027
- WHEN the register loads
- THEN the restriction shows overdue for lifting and is still ratified

### Requirement: REQ-EMB-023 The detail page shows the four steps of the board

The restriction detail page SHALL show the stepper Opgelegd, Bekrachtigd, Opgeheven (Vervallen in place of Bekrachtigd once lapsed), the header line "Bekrachtiging door <body> uiterlijk <ratificationDeadline>" while ratification is open, and a status timeline of four steps: Opgelegd (date, imposing body, imposing decision, ground), Met het voorstel naar <body> (date and agenda item, when sent), Bekrachtiging (planned date and agenda item, with the line that the restriction lapses when not ratified, or the outcome), and Opheffen (conditions and liftBy, with the line that lifting needs a decision of the body). Steps that have not happened SHALL show as planned, not be left out.

#### Scenario: An imposed restriction before the council meets
- GIVEN the example restriction imposed on 10 September and sent on 24 September, ratification planned 15 October
- WHEN its page opens
- THEN the header reads "Bekrachtiging door de raad uiterlijk 15 oktober 2026"
- AND the timeline shows four steps, the third marked gepland and the fourth reading "Na gunning, uiterlijk 1 juli 2027"
