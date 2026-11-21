# digital-meetings-and-recurrence Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [meeting-video-call](../../) (this delta)

## Purpose

A meeting can be marked digital or hybrid, but its video call can only be attached on an integrations page nothing links to. Moves decidiq matrix rows pla-08 toward built.

## ADDED Requirements

### Requirement: REQ-MVC-001 A digital or hybrid meeting has its video call

A digital or hybrid meeting page SHALL let the secretary create or link a Talk room and SHALL show participants a Join video call button.

#### Scenario: A member joins the call
- GIVEN a hybrid committee meeting with a linked Talk room
- WHEN member Pieter opens the meeting page
- THEN he sees Join video call and it opens the Talk room
