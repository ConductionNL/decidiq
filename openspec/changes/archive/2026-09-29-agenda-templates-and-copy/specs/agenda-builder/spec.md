# agenda-builder Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-templates-and-copy](../../) (this delta)

## Purpose

The only template-like path is copying standing recurring items on the live screen. Moves decidiq matrix rows age-04, pla-14 toward built.

## ADDED Requirements

### Requirement: REQ-ATC-001 Start an agenda from a template

The meeting Agenda widget SHALL offer to add the items of an agenda template in order.

#### Scenario: The secretary uses the standard agenda
- GIVEN agenda template Raadsvergadering lists opening, minutes, questions and closing
- WHEN the secretary starts the agenda of a new council meeting from it
- THEN the agenda holds those four items in that order

### Requirement: REQ-ATC-002 Copy items or a whole agenda from an earlier meeting

The Agenda widget SHALL copy chosen items, or all items, from an earlier meeting, and a meeting series SHALL copy the agenda to each meeting it creates.

#### Scenario: An item carries over
- GIVEN item Housing plan was postponed at the meeting of 7 October
- WHEN the secretary copies it from that meeting into the meeting of 14 October
- THEN the item appears on the new agenda with its description
