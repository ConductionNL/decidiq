# meeting-requested-event Specification

## Purpose
An in-process command seam that lets another installed fleet app ask decidiq to
hold a `Meeting` for one of its own objects (a case session with parties, a site
visit), and read back the id decidiq gave it. Per ADR-041 a cross-app command
travels as a typed event, not as REST. The meeting carries the producer's object
in `subjectRegister`, `subjectSchema` and `subjectId` (register fragment
`123-meeting-subject.json`); decidiq stores no copy of the object.

## Requirements

### Requirement: REQ-MRE-001 A meeting is requested by typed event and answered in process

Decidiq SHALL register a listener for `OCA\Decidiq\Event\MeetingRequestedEvent`,
constructed positionally as `(subjectRegister, subjectSchema, subjectId, title,
scheduledDate, attributes, actorId)`. The listener SHALL write the meeting id, the
created flag and the handled flag onto the dispatched instance. A refused or
failed command SHALL leave the event unhandled, log why, and throw nothing.

#### Scenario: A session opens as a meeting
- **GIVEN** an installed decidiq and no meeting for dossiq's session `session-1`
- **WHEN** dossiq dispatches `MeetingRequestedEvent` for it with a title and a start
- **THEN** a `Meeting` exists with that title and start, `lifecycle: scheduled`,
  and `subjectRegister`, `subjectSchema` and `subjectId` pointing at the session
- **AND** the event reports `handled = true`, `created = true` and the meeting id

#### Scenario: A refused command throws nothing
- **GIVEN** a command without a title
- **WHEN** it is dispatched
- **THEN** the event reports `handled = false` and no meeting is written

### Requirement: REQ-MRE-002 The subject is the key, so a repeat moves the one meeting

The command SHALL resolve an existing meeting on `subjectSchema` and `subjectId`
(and `subjectRegister` when both sides name one), re-checked on the returned rows,
before it writes. A repeat SHALL patch that meeting's title, start and the allowed
attributes and SHALL leave its lifecycle and everything else decidiq added to it.

#### Scenario: A re-planned session moves its meeting
- **GIVEN** a meeting held for `session-1` that has since been opened
- **WHEN** the session is re-planned to a later start and the command repeats
- **THEN** there is still one meeting for `session-1`, at the later start, still `opened`

### Requirement: REQ-MRE-003 Only listed fields are commandable

Beyond title and start a command MAY set `endDate`, `location`, `meetingType`,
`meetingMode`, `governanceBody`, `virtualLocation` and `isPublic`; any other key is
ignored. `meetingType` defaults to `committee` and `meetingMode` to `in-person`; a
value outside the schema's list, an empty title, a missing subject schema or uuid,
or a start that is no date SHALL be refused.

#### Scenario: Lifecycle cannot be commanded
- **GIVEN** a command carrying `lifecycle: closed`
- **WHEN** it creates a meeting
- **THEN** the meeting opens `scheduled`
