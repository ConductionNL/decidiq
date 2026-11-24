# decidesk-notifications

## ADDED Requirements

### Requirement: A notice sent with nobody signed in runs as the system (REQ-MRD-010)

A notice that writes to a meeting (the agenda change notice, the meeting reminder) SHALL run as the system when no user is signed in, as from cron or `occ`, and SHALL run as the signed-in user otherwise.

#### Scenario: The cron reminder stamps the meeting
- **GIVEN** a scheduled meeting whose reminder is due, and no signed-in user
- **WHEN** the hourly reminder job runs
- **THEN** the member SHALL be reminded and the meeting SHALL be stamped, and no "Anonymous does not have permission" SHALL be logged
- @e2e exclude {background job; covered by MeetingReminderServiceTest}

#### Scenario: An example set writing agenda items from occ
- **GIVEN** a meeting with a published agenda, and `occ` writing agenda items to it with nobody signed in
- **WHEN** each item is written
- **THEN** the agenda change notice SHALL run as the system and record its version
- @e2e exclude {occ path; covered by AgendaItemChangeListenerTest}

#### Scenario: A signed-in user keeps their rights
- **GIVEN** a signed-in user editing an agenda item
- **WHEN** the notice runs
- **THEN** it SHALL NOT be elevated to the system
- @e2e exclude {covered by SystemWhenNobodySignedInTest}
