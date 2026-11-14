# motion-status-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [motions-stages-and-themes](../../) (this delta)

## Purpose

A motion can only be moved through its stages from the Decisions page, withdrawing is API only, and motions cannot be tagged or filtered by theme. Moves decidiq matrix rows mot-08, mot-15 toward built.

## ADDED Requirements

### Requirement: REQ-MST-001 Move a motion through its stages on its page

The motion page SHALL show the stage transitions the caller may take, including withdraw for the submitter.

#### Scenario: A member withdraws her motion
- GIVEN Anna submitted motion M-12
- WHEN she presses Withdraw on the motion page
- THEN the motion shows withdrawn and the list filter Withdrawn includes it

### Requirement: REQ-MST-002 Tag motions by theme and filter

Motions SHALL carry themes from a configurable list, and the motions list SHALL filter on them.

#### Scenario: Filter on a theme
- GIVEN motions are tagged Housing or Climate
- WHEN a member filters the motions list on Housing
- THEN only motions tagged Housing are shown
