# authorization-via-or-rbac Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [platform-role-rights-per-record-type](../../) (this delta)

## Purpose

Per record type read and write rules exist as register declarations that OpenRegister enforces, but an administrator cannot see or change them per role. Moves decidiq matrix rows plt-03 toward built.

## ADDED Requirements

### Requirement: REQ-PRR-001 Administrators see and map rights per record type

The admin settings SHALL list, per record type, which roles may read, create, change and delete it, and SHALL let the administrator map each role to Nextcloud groups.

#### Scenario: The administrator checks who can edit minutes
- GIVEN the administrator opens Rights per record type
- WHEN she reads the row for Minutes and maps role griffie to group Griffie
- THEN the page shows who may change minutes, and members of Griffie can now edit them
