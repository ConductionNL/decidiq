# agenda-publication Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-publish-and-invite-members](../../) (this delta)

## Purpose

Publishing an agenda has no working step: the public publish button waits on a convocation field nothing sets, and the member notification is API only. Moves decidiq matrix rows age-05, pla-05 toward built.

## ADDED Requirements

### Requirement: REQ-APIM-001 Publishing the agenda invites the members

The chair or secretary SHALL be able to publish the agenda from the meeting page; each member SHALL receive an invitation with the date, place and agenda items by the delivery they chose.

#### Scenario: Members are invited
- GIVEN a council meeting with five agenda items and 30 members
- WHEN the secretary presses Publish agenda
- THEN each member finds an invitation in the bell, and members who chose email get one listing the five items

### Requirement: REQ-APIM-002 A published agenda of a public meeting can go public

Publishing the agenda SHALL record when the convocation was sent, and a public meeting SHALL then be publishable to the public catalogue.

#### Scenario: The public sees the agenda
- GIVEN a public meeting whose agenda was published
- WHEN the clerk presses Publish in the Publication widget
- THEN the agenda appears in the public catalogue
