# governance-bodies Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-membership-terms-contacts-and-factions](../../) (this delta)

## Purpose

A body's members widget shows who sits on it today, but not since when, not who left, not how to reach them, and not which faction they belong to. Moves decidiq matrix rows bod-02, bod-06, bod-16 toward built.

## ADDED Requirements

### Requirement: REQ-BMT-001 A membership records from when to when

The Members widget SHALL write a start date when a member is added and SHALL list past members with their start and end dates on request.

#### Scenario: The clerk sees who left the council
- GIVEN council member Anna was removed from the council on 1 June
- WHEN the clerk turns on Past members on the council page
- THEN Anna is listed with her start date and 1 June as end date

### Requirement: REQ-BMT-002 Contact details for members and bodies

The body page SHALL show and edit contact details (email, phone, address) for each member and for the body itself, stored as ContactDetail records.

#### Scenario: The clerk adds a phone number
- GIVEN council member Pieter has only an email address
- WHEN the clerk opens Contact details on his row and adds 06 12345678 as phone
- THEN the members table shows his phone number after a reload

### Requirement: REQ-BMT-003 Members belong to a faction with its own workspace

A membership SHALL reference its faction body, and a faction page SHALL offer the faction a shared Collectives workspace.

#### Scenario: A faction gets its workspace
- GIVEN the council has a faction Groen with three members
- WHEN a member of Groen opens the faction page
- THEN the page shows the faction workspace widget and the three members
