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
- AND the archive holds one JSON file per record type, the files of meetings, agenda items and decisions under files/<type>/<id>/, and a manifest.json naming each type, its count and its relations

#### Scenario: A record type that cannot be read is named, not dropped
- GIVEN one record type cannot be read while the export runs
- WHEN the export finishes
- THEN the manifest lists that type under skipped with the reason, and the other types are in the archive

#### Scenario: Only administrators export, and only exports download
- GIVEN a member who is not an administrator
- WHEN she asks for the export or its download
- THEN she is refused; and a download name that is not an export answers 404
