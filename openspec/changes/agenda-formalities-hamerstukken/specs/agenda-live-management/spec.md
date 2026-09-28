# agenda-live-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-formalities-hamerstukken](../../) (this delta)

## Purpose

The live screen can adopt formalities (hamerstukken) in one go, but nothing lets anyone mark an item as a formality, and the adopted state lands in a property the schema lacks. Moves decidiq matrix rows age-07 toward built.

## ADDED Requirements

### Requirement: REQ-AFH-001 Formalities are marked and adopted together

The chair or secretary SHALL be able to mark agenda items as formalities, and the live screen SHALL adopt all formalities in one step, recording the outcome on each item.

#### Scenario: The chair adopts the formalities
- GIVEN items 3, 4 and 7 are marked as formalities
- WHEN the chair presses Adopt formalities on the live screen
- THEN each of the three shows adopted without debate with the time
