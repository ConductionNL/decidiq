# ADR-002: CalDAV-First Storage Architecture

**Status:** accepted
**Date:** 2026-04-16

## Context

DecideDesk manages meetings (scheduling, lifecycle, attendance) and action items (tasks
assigned from decisions). The initial design stored everything in OpenRegister and synced
to Nextcloud Calendar via a CalendarEventService. This created:

1. **A sync layer** that must be maintained, debugged, and kept consistent
2. **Duplicate data** — meeting data in OpenRegister AND in Calendar
3. **Poor user experience** — meetings don't appear in Calendar until sync runs
4. **Missed integration** — Nextcloud Tasks app can't see action items

The previous design referenced a `CalendarEventService` for syncing — this service is
eliminated entirely by the CalDAV-first approach.

Meanwhile, Nextcloud already has a full CalDAV server (sabre/dav) that stores VEVENTs
and VTODOs natively, supports RFC 5545 X-properties for custom metadata, and preserves
them in round-trip (raw ICS blob stored in `calendarobjects` table).

## Decision

**CalDAV is the primary storage for meetings and action items.** OpenRegister stores
only governance-specific entities that have no CalDAV equivalent.

### What lives in CalDAV

| Entity | CalDAV Type | Standard Fields | X-DECIDESK-* Fields |
|---|---|---|---|
| Meeting | VEVENT | SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION, ATTENDEE, STATUS | LIFECYCLE, MEETING-TYPE, MEETING-MODE, QUORUM-REQUIRED, SERIES, BODY-UID |
| ActionItem | VTODO | SUMMARY, DESCRIPTION, DUE, STATUS, COMPLETED, ATTENDEE | MOTION-UID, MEETING-UID |

### What lives in OpenRegister

Everything else: Motion, Amendment, VotingRound, Vote, GovernanceBody, Person,
Membership, Post, ContactDetail, Area, AgendaItem, Minutes, Speech.

### OpenRegister wrapper objects

For relational queries (e.g. "all agenda items for meeting X"), OpenRegister holds thin
wrapper objects that store the CalDAV UID as a reference. The wrapper contains:
- `caldavUid` — the VEVENT/VTODO UID
- `calendarId` — the Nextcloud calendar ID
- Relations to other OpenRegister entities

The wrapper does NOT duplicate CalDAV data. To get meeting details, the app reads the
VEVENT via CalDAV. The wrapper exists solely for OpenRegister's relational query engine.

### CalDAV service layer

A `CalDavService` PHP class wraps Nextcloud's `\OCA\DAV\CalDAV\CalDavBackend` for:
- Creating/updating/deleting VEVENTs and VTODOs
- Reading X-DECIDESK-* properties from ICS blobs via sabre/vobject
- Managing a dedicated "DecideDesk" calendar per governance body
- ATTENDEE management mapped from Person/Membership entities

### X-DECIDESK-* property registry

All extended properties use the `X-DECIDESK-` prefix per RFC 5545 Section 3.8.8.2:

| Property | VEVENT/VTODO | Values | Description |
|---|---|---|---|
| X-DECIDESK-LIFECYCLE | VEVENT | draft, scheduled, opened, paused, adjourned, closed | Meeting state machine |
| X-DECIDESK-MEETING-TYPE | VEVENT | regular, extraordinary, committee, public-hearing | Meeting classification |
| X-DECIDESK-MEETING-MODE | VEVENT | in-person, digital, hybrid | Attendance mode |
| X-DECIDESK-QUORUM-REQUIRED | VEVENT | integer | Minimum attendees |
| X-DECIDESK-SERIES | VEVENT | string | Series identifier |
| X-DECIDESK-BODY-UID | VEVENT | uuid | GovernanceBody reference |
| X-DECIDESK-MOTION-UID | VTODO | uuid | Source motion reference |
| X-DECIDESK-MEETING-UID | VTODO | string | Source meeting CalDAV UID |

## Consequences

- **No sync layer** — meetings are native Calendar events, tasks are native Tasks
- **Users see meetings immediately** in their Nextcloud Calendar alongside personal events
- **Action items appear in Nextcloud Tasks** app without any integration code
- **CalDAV interop** — meetings sync to any CalDAV client (Thunderbird, iOS, Android)
- **X-properties are preserved** by any CalDAV-compliant client (RFC 5545 requirement)
- **OpenRegister queries** still work via wrapper objects for governance-specific joins
- **Migration needed** for existing Meeting/ActionItem data → CalDAV objects
