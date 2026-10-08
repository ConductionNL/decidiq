# embargo-geheimhouding Specification (delta)

## Purpose

A confidentiality restriction carries the decision that imposed it and its planned end date, lapses when the ratifying body met and did not ratify it, and comes up for lifting when its end date nears. Follows board DcGeheimhouding. Covers rows pub-06 and pub-07 (decision 100).

## ADDED Requirements

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
