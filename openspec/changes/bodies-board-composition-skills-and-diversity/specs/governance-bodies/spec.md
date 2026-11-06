# governance-bodies Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-board-composition-skills-and-diversity](../../changes/bodies-board-composition-skills-and-diversity/)

## Purpose

A body knows what expertise it needs, who brings it, where it falls short, and how its membership is made up against the targets it set itself.

Matrix rows: bod-11 and bod-12 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-BCS-001 A body lists the competences it needs

A user in a body's signatory scope SHALL be able to create, edit and deactivate the body's competences, each with a name, a description and how many members should hold it. Other users SHALL be able to read them and SHALL NOT be able to change them.

#### Scenario: The secretary sets up the board profile

- GIVEN Jan de Vries is secretary of "Raad van Commissarissen Waterschap Amstel, Gooi en Vecht"
- WHEN Jan opens the body page, opens the "Composition" widget and adds "IT and cybersecurity" with one required holder
- THEN the competence exists for that body and appears as a column in the skills matrix

#### Scenario: A member cannot change the profile

- GIVEN Mark van den Berg is an ordinary member of the board
- WHEN Mark tries to edit the required holders of "Legal"
- THEN the change is refused and the competence is unchanged

### Requirement: REQ-BCS-002 A member's competences are recorded and confirmed

A member SHALL be able to record their own competence against their membership with a level (basic, experienced or expert); a signatory SHALL be able to record one for any member. Only a signatory SHALL be able to confirm a competence. Changing the level of a confirmed competence SHALL clear the confirmation.

#### Scenario: A member records and the chair confirms

- GIVEN Mark van den Berg is a member of the board
- WHEN Mark records "Water management" at level expert
- THEN the matrix shows "expert, unconfirmed" in his row
- AND after chair Janneke de Bruin confirms it, the cell shows "expert" and names her as confirmer

#### Scenario: A member cannot confirm their own competence

- GIVEN Mark's unconfirmed competence
- WHEN Mark tries to confirm it
- THEN the confirm action is refused

### Requirement: REQ-BCS-003 The skills matrix shows where the body falls short

The "Composition" widget SHALL show a matrix of the body's current members against its active competences. Under each competence it SHALL show how many members hold it confirmed at level experienced or expert, against how many the body requires, and SHALL mark the competence "Gap" when fewer hold it.

#### Scenario: The board sees its gaps

- GIVEN the seeded board with Finance and audit held by Janneke (expert, confirmed), Legal by Jan (experienced, confirmed), Water management by Mark (expert, unconfirmed) and nobody holding IT and cybersecurity
- WHEN any board member opens the "Composition" widget
- THEN "IT and cybersecurity" and "Water management" are marked "Gap"
- AND "Finance and audit" and "Legal" are not

### Requirement: REQ-BCS-004 The body sees its composition figures against its own targets

The "Composition" widget SHALL show the current members by gender, age band, nationality, independence and whether they sit from outside, as counts and shares, each with a "not recorded" count. A body SHALL be able to set targets as a minimum share for a value of a dimension, and the widget SHALL show each target as met or not met. The widget SHALL NOT list names per value.

#### Scenario: A gender target is met

- GIVEN the board has three current members, one recorded as female and two as male, and a target of at least 0.33 female
- WHEN a board member opens the "Composition" widget
- THEN the gender figures show female 1 (33%), male 2 (67%), not recorded 0
- AND the target is shown as met

#### Scenario: Missing data is counted, not guessed

- GIVEN one member has no birth date recorded
- WHEN the widget shows age bands
- THEN that member is counted under "not recorded" and in no age band
