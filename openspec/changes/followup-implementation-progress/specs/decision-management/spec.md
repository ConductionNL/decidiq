# decision-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [followup-implementation-progress](../../) (this delta)

## Purpose

Records how far the implementation of an adopted decision or motion has got, with dated updates, a current status on the decision and a reminder when nobody reports. Closes decidiq matrix rows fol-10 and mot-13.

**Standards**: Schema.org `UpdateAction`, OpenRaadsinformatie `Besluit`.

## ADDED Requirements

### Requirement: REQ-FUP-001 An adopted decision takes dated progress updates

The app SHALL let the secretariat add an implementation update to a decision that is decided or enacted with outcome adopted: a date, a status (not started, on track, delayed, completed, will not be implemented), an optional percentage and a text, with evidence files attached. It SHALL refuse updates on any other decision. The decision page and the motion page SHALL list the updates newest first.

#### Scenario: The griffier reports progress on a motion
- GIVEN the council adopted "Motie meer groen in de wijk" in January
- WHEN the griffier adds an update on 15 June, status delayed, 45 percent, "Aanbesteding uitgesteld tot na de zomer"
- THEN the motion page lists that update above the one of 1 March

#### Scenario: A rejected motion takes no updates
- GIVEN a motion the council rejected
- WHEN the griffier tries to add an update
- THEN the update is refused with a message that only adopted decisions are followed up

### Requirement: REQ-FUP-002 The decision shows its current implementation status

The decision SHALL carry an implementation status and its date, taken from its newest update by date. A back-dated update SHALL not replace a newer status.

#### Scenario: The status follows the newest update
- GIVEN a motion whose newest update says on track
- WHEN an update dated later says delayed
- THEN the motion's implementation status reads delayed with that date

### Requirement: REQ-FUP-003 The motions and decisions lists filter on implementation status

The Motions and Decisions lists SHALL show the implementation status as a column and offer it as a quick filter.

#### Scenario: The council asks which motions are delayed
- GIVEN twelve adopted motions, three of them delayed
- WHEN the griffier filters the Motions list on delayed
- THEN three motions are listed

### Requirement: REQ-FUP-004 The secretariat is reminded when nobody reported for 90 days

The decision schema SHALL declare a scheduled notification that tells the secretariat about each adopted decision whose status is not completed or will not be implemented and whose last update, or decision date when there is none, is more than 90 days old.

#### Scenario: A silent motion is flagged
- GIVEN an adopted motion whose last update is 100 days old and says on track
- WHEN the daily notification run fires
- THEN each member of the secretariat gets "No progress reported: Motie meer groen in de wijk"
