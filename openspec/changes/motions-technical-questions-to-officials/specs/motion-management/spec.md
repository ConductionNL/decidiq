# motion-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [motions-technical-questions-to-officials](../../) (this delta)

## Purpose

A technical question can be put on the agenda as an item with fields for question and answer, but nothing assigns it to an official or watches the answer deadline. Moves decidiq matrix rows mot-17 toward built.

## ADDED Requirements

### Requirement: REQ-MTQ-001 Technical questions go to an official with a deadline

A technical question SHALL be assignable to an official with an answer deadline; the official SHALL be notified, and open and overdue questions SHALL be listed.

#### Scenario: The official answers in time
- GIVEN member Anna asked a technical question about information letter 2026-14
- WHEN the griffier assigns it to official Jan with a deadline of Friday
- THEN Jan is notified, and after he answers Anna is notified and the list shows it answered
