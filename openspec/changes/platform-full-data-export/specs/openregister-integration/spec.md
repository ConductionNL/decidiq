# openregister-integration Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [platform-full-data-export](../../) (this delta)

## Purpose

decidiq has a read API and a regulator export of resolutions and minutes, but no way to take all data along when leaving. Moves decidiq matrix rows pub-15 toward built.

## ADDED Requirements

### Requirement: REQ-PFE-001 An administrator exports all data

An administrator SHALL be able to export every decidiq record and its files in one archive with a description of the format.

#### Scenario: The organisation leaves
- GIVEN the municipality moves to another supplier
- WHEN the administrator presses Export all data
- THEN she receives a link to an archive with every record per type and the meeting files
