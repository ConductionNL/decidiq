# meeting-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [meeting-ad-hoc-with-guests](../../) (this delta)

## Purpose

Any signed-in user can create a meeting, but only administrators can change it afterwards, and invitees come only from existing participant records. Moves decidiq matrix rows pla-20 toward built.

## ADDED Requirements

### Requirement: REQ-MAH-001 An organiser runs their own ad hoc meeting

The user who created a meeting SHALL be able to edit and delete it, and SHALL be able to invite guests from outside by email.

#### Scenario: A project lead invites an outside advisor
- GIVEN project lead Anna created meeting Kick-off windpark
- WHEN she invites guest advisor@example.org
- THEN the advisor gets an invitation with the date, the agenda and a link to the papers
