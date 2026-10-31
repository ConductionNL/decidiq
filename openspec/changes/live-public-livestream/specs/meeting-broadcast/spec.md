# meeting-broadcast Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [live-public-livestream](../../) (this delta)
- supersedes the livestream and subtitle part of [raadsvergadering-livestream-transcript](../../../raadsvergadering-livestream-transcript/)

## Purpose

Lets the clerk broadcast a public meeting through the organisation's streaming
service, test the broadcast beforehand, pause it for a closed session, and
release subtitles for the recording. Residents follow the broadcast through
portaliq without a Nextcloud account. Closes matrix rows liv-06, liv-10 and
liv-19.

**Standards**: Schema.org `BroadcastEvent`, W3C WebVTT, WCAG 2.1 success
criteria 1.2.2 (captions, prerecorded) and 1.2.4 (captions, live), Gemeentewet
article 23 (public council meetings).

## ADDED Requirements

### Requirement: REQ-LSTR-001 A public broadcast is readable without an account once it goes live

decidiq SHALL hold one `MeetingBroadcast` per meeting, in the decidiq register,
with a declared lifecycle of `planned`, `testing`, `live`, `paused` and `ended`.
The schema SHALL grant the `public` group read access only when
`publicationDate <= $now`, and `publicationDate` SHALL be written only when the
broadcast first goes live. `playerUrl` SHALL be empty until then.

#### Scenario: A resident sees nothing before the meeting goes live

- GIVEN a planned broadcast for the council meeting of 26 March with no `publicationDate`
- WHEN an anonymous resident reads `meeting-broadcast` objects through the OpenRegister API
- THEN the response holds no row for that meeting
- @e2e exclude RBAC contract; covered by a Newman request without a session

#### Scenario: A resident sees the player once the broadcast is live

- GIVEN the clerk started the broadcast of the council meeting of 12 March
- WHEN an anonymous resident reads the same list
- THEN the row for that meeting carries `lifecycle: live` and a `playerUrl`
- @e2e exclude RBAC contract; covered by a Newman request without a session

### Requirement: REQ-LSTR-002 The clerk runs a test broadcast that only staff can see

The chair or secretary of the meeting SHALL be able to start a test broadcast
from the Broadcast widget on `MeetingDetail` through
`POST /api/meetings/{meetingId}/broadcast/test`. The test SHALL run on the
streaming service's staff preview address, stored as `previewUrl`, and SHALL
NOT write `playerUrl` or `publicationDate`. The clerk SHALL record the result
as `ok` or `problems` with a note, and the widget SHALL show the last result
with its date and author.

#### Scenario: The clerk checks picture and sound before the meeting

- GIVEN the committee meeting of 19 March with a planned broadcast and a connected streaming service
- WHEN the secretary presses "Run a test broadcast" in the Broadcast widget on the meeting page
- THEN the widget plays the preview, the broadcast is in `testing`, and the portal still shows no row for that meeting

#### Scenario: The clerk records what the test showed

- GIVEN a broadcast in `testing`
- WHEN the secretary chooses "There were problems", types "Microphone 4 had no sound, replaced" and saves
- THEN the widget shows the result, the note, the secretary's name and the time, and the broadcast is back in `planned`

#### Scenario: A member who is not staff cannot start a test

- GIVEN a council member who is neither chair nor secretary of the meeting
- WHEN they call `POST /api/meetings/{meetingId}/broadcast/test`
- THEN the response is 403 and nothing is sent to the streaming service
- @e2e exclude authorization contract; covered by PHPUnit on BroadcastController and a Newman IDOR request

### Requirement: REQ-LSTR-003 Going live needs a public meeting and a connected streaming service

`POST /api/meeting-broadcasts/{id}/start` SHALL be refused with 422 when the
meeting is not flagged `isPublic`, and with 409 when no source is linked to the
`streaming` connection. On success it SHALL write `playerUrl`,
`publicationDate` and open a public window. When no source is linked, the
Broadcast widget SHALL show "No streaming service is connected" and no action
buttons.

#### Scenario: A meeting that is not public cannot go live

- GIVEN a closed board meeting with `isPublic: false`
- WHEN the chair calls the start endpoint
- THEN the response is 422 with "Only a public meeting can be broadcast"
- @e2e exclude API contract; covered by PHPUnit on MeetingBroadcastService

#### Scenario: Without a streaming service the widget says so

- GIVEN no source is linked to the `streaming` connection in integriq
- WHEN the secretary opens the meeting page
- THEN the Broadcast widget reads "No streaming service is connected" and shows no buttons

#### Scenario: Going live makes the stream public

- GIVEN a public meeting with a planned broadcast and a connected service
- WHEN the chair presses "Go live"
- THEN the broadcast is `live`, carries `playerUrl` and `publicationDate`, and has one open public window

### Requirement: REQ-LSTR-004 A closed session pauses the broadcast and closes the public window

The clerk SHALL be able to pause and resume a live broadcast and to stop it.
Each pause SHALL close the open public window and each resume SHALL open a new
one, with `start` and `end` in seconds from the meeting start and
`recordingStart` in seconds into the service's recording. Stopping SHALL close
the last window, move the broadcast to `ended`, and ask the service for the
recording address.

#### Scenario: The council goes into a closed session

- GIVEN a live broadcast with one open window that started at 0 seconds
- WHEN the secretary presses "Pause for a closed session" at 5400 seconds and "Resume" at 6300 seconds
- THEN the broadcast holds a closed window from 0 to 5400 and an open window from 6300
- @e2e exclude service path; covered by PHPUnit on MeetingBroadcastService with a fixed clock

#### Scenario: Stopping ends the broadcast

- GIVEN a live broadcast
- WHEN the chair presses "Stop the broadcast"
- THEN the broadcast is `ended`, its last window is closed, and `recordingUrl` is filled once the service reports it

### Requirement: REQ-LSTR-005 Live captions come from the streaming service

When the broadcast goes live, decidiq SHALL ask the streaming service for live
captions and record `liveCaptions` as `requested` when the service accepts and
`unavailable` when it cannot. decidiq SHALL NOT produce live captions itself.

#### Scenario: A service without live captions

- GIVEN a connected streaming service that does not offer live captions
- WHEN the broadcast goes live
- THEN `liveCaptions` is `unavailable` and the widget reads "Live captions are not available from this streaming service"
- @e2e exclude adapter path; covered by PHPUnit with a stubbed integriq call

### Requirement: REQ-LSTR-006 Subtitles for the recording come from the aligned transcript and cover only the public windows

`BroadcastCaptionService` SHALL derive a WebVTT file from a transcript in status
`done` with `alignedAt` set. It SHALL keep only segments that start inside a
public window, place each cue at `segment.start - window.start +
window.recordingStart`, and leave out speaker labels. It SHALL refuse a
transcript without `alignedAt`. The file SHALL be written to the meeting folder
as `captions-<language>.vtt`.

#### Scenario: A closed session never reaches the subtitles

- GIVEN public windows from 0 to 5400 and from 6300 to 10800 seconds, and a segment at 5800 seconds
- WHEN the secretary presses "Make subtitles" in the Broadcast widget
- THEN the caption file holds no cue for the segment at 5800 seconds, and the cue for a segment at 6400 seconds starts at 5500 seconds
- @e2e exclude derivation; covered by PHPUnit on BroadcastCaptionService

#### Scenario: An unaligned transcript is refused

- GIVEN a transcript in status `done` without `alignedAt`
- WHEN subtitles are requested
- THEN the request is refused with "Align the transcript with the agenda first"
- @e2e exclude derivation; covered by PHPUnit on BroadcastCaptionService

### Requirement: REQ-LSTR-007 A caption track is public only after the clerk releases it

A derived caption file SHALL stay private until the chair or secretary releases
it. Release SHALL be refused unless the meeting is `isPublic` and the broadcast
is `ended`. On release decidiq SHALL create a read-only public link share of the
file, record it in `captionTracks` with `reviewedBy` and `releasedAt`, and ask
the streaming service to attach the file to the recording when it can.

#### Scenario: The clerk releases the reviewed subtitles

- GIVEN an ended broadcast of a public meeting and a derived Dutch caption file
- WHEN the secretary presses "Release subtitles"
- THEN `captionTracks` holds the Dutch track with a share link, the secretary's name and the time, and the portal row shows the track

#### Scenario: Subtitles of a meeting that is not public are never released

- GIVEN a derived caption file for a meeting with `isPublic: false`
- WHEN the release endpoint is called
- THEN the response is 422 and no share is created
- @e2e exclude API contract; covered by PHPUnit on BroadcastCaptionService

### Requirement: REQ-LSTR-008 Residents see live and recent broadcasts through portaliq

`PortalContributionProvider` SHALL contribute an anonymous `publicBroadcasts`
collection over `meeting-broadcast` whose field whitelist holds only `title`,
`bodyName`, `scheduledDate`, `lifecycle`, `playerUrl`, `recordingUrl` and
`captionTracks`. `previewUrl`, `testNote`, `testedBy` and `publicWindows` SHALL
never be whitelisted. Without portaliq installed the provider SHALL stay inert.

#### Scenario: A resident opens the portal during a meeting

- GIVEN portaliq is installed and the council meeting of 12 March is live
- WHEN a resident without an account opens the portal's "Live and recent meetings" page
- THEN they see the meeting with a link to the player, and no staff preview address

#### Scenario: The contribution never whitelists a staff field

- GIVEN the provider's contribution for the anonymous audience
- WHEN its `publicBroadcasts` collection is read
- THEN its `fields` list holds none of `previewUrl`, `testNote`, `testedBy` or `publicWindows`
- @e2e exclude declaration contract; covered by PHPUnit on PortalContributionProvider constructed with portaliq absent
