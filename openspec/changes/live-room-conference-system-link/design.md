# Design: live-room-conference-system-link

Kind: code. One schema fragment, one connection declaration, one listener that
reuses two existing services, one widget. Read against decidiq `development` at
4d7430ff.

## What exists today

- **Speeches.** `EngagementService::captureEngagement()`
  (`lib/Service/EngagementService.php:84`) folds a `speech` event into the
  member's `engagement-record` for the meeting
  (`lib/Settings/decidesk_register.json:4919`), adding to `speeches` and
  `speakingDuration` from `startTime` and `endTime` (`:181`).
- **Votes.** `VoteCastingService::castVote()`
  (`lib/Service/VoteCastingService.php:127`) loads the open round, asserts the
  participant belongs to the meeting (`VoteCastGuard::assertMeetingMembership()`,
  `lib/Service/VoteCastGuard.php:132`) and replaces the member's earlier vote in
  the round. `Vote.castAs` (`lib/Settings/decidesk_register.json:1688`) records
  `in-person`, `remote` or `unknown`. `VotingRound.isSecret` marks a secret
  ballot.
- **Participants.** `participant` (`lib/Settings/decidesk_register.json:1150`)
  has `displayName`, `party`, `governanceBody` and `participantType`, and no
  seat.
- **Agenda.** `AgendaService::publishAgenda()`
  (`lib/Service/AgendaService.php:116`) stamps `agendaPublishedAt` and
  `agendaVersion` on the meeting (fragment
  `lib/Settings/register.d/91-agenda-publication-has-its-own-fields.json`).
- **Room display.** `GET /api/voting-rounds/{id}/public-state`
  (`appinfo/routes.php:184`, `VotingRoundProjection::publicState()`) is a read
  for a screen in the room, not a link with the delegate system.
- **Object listeners.** `AgendaItemChangeListener`
  (`lib/Listener/AgendaItemChangeListener.php:87`) is the pattern: it reacts to
  OpenRegister's `ObjectCreatedEvent` and reads the row with `getObject()`,
  registered in `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`.
- **Connections.** `lib/Settings/connections.json` (`adopt-connection-registry`).

## D1. Outbound needs no decidiq code

The room system needs the published agenda (item order and titles) and the
seat list (member, party, seat). All of it is already in the decidiq register.
integriq's synchronisation reads `meeting`, `agenda-item` and `participant`
from OpenRegister and pushes them to the delegate system. decidiq adds the seat
field and nothing else on the way out. The integriq user that runs the
synchronisation gets read access to those three schemas through OpenRegister
RBAC.

## D2. Inbound lands in an inbox, not in the vote table

`RoomSystemMessage` (slug `room-system-message`) in
`lib/Settings/register.d/92-room-system-link.json`:

| Property | Type | Purpose |
|---|---|---|
| `meeting` | uuid, `$ref: meeting` | the meeting in the room |
| `kind` | enum | `speech`, `vote` |
| `seat` | string | the delegate unit id as the room system sends it |
| `agendaItem` | uuid, `$ref: agenda-item` | for a speech |
| `votingRound` | uuid, `$ref: voting-round` | for a vote |
| `value` | enum | `for`, `against`, `abstain`, for a vote |
| `startedAt`, `endedAt` | date-time | a speech's microphone times |
| `externalId` | string | the room system's message id, unique per meeting |
| `status` | enum | `received`, `applied`, `rejected` |
| `rejectReason` | string | plain words for the clerk |
| `participant` | uuid | the member the seat resolved to, written on apply |

`authorization.create` allows only the integriq synchronisation user.
Staff read. Nobody but decidiq's listener writes `status`.

Why an inbox rather than letting integriq write `vote` objects: a direct write
skips `castVote()`, so a closed round, a non-member or a secret ballot would
all be accepted. The inbox keeps every rule in decidiq and leaves a record of
what the room system sent.

## D3. Applying a message

`RoomSystemMessageListener` on `ObjectCreatedEvent` for `room-system-message`:

1. Resolve `seat` to one participant of the meeting's body with that
   `roomSeat`. None or more than one: `rejected`, "Seat 27 is not linked to a
   member".
2. A `speech`: `captureEngagement(meetingId, participant, 'speech',
   {startTime, endTime, agendaItem, source: 'room-system'})`.
3. A `vote`: refuse when the round `isSecret` ("A secret ballot cannot be read
   from the desk units"); otherwise `castVote(round, participant, value,
   false, null)` and set `castVia: room-system` on the saved vote.
4. Any exception from the services becomes `rejected` with its message.
5. The same `externalId` twice in one meeting is applied once.

`castVia` is a new optional enum on `Vote` (`app`, `email`, `room-system`).
Helper A of this pass writes `voting-named-paper-vote-entry` (vot-21), which
may add a channel field for paper votes. Whichever lands first owns the field,
and the other adds its value to it rather than a second field.

A manual "Apply again" on a rejected message (after the clerk fixed a seat)
re-runs the same steps: `POST /api/room-system-messages/{id}/apply`, guarded by
`TranscriptionStaffGuard::forMeeting()`
(`lib/Service/TranscriptionStaffGuard.php:100`), the chair-or-secretary guard.

## D4. The widget

`MeetingRoomSystemTab` on `MeetingDetail` (`src/manifest.json:561`, edited
directly per the page note), listing this meeting's messages with kind, seat,
member, status and reason, rejected ones first, each with "Apply again". When
the connection has no source it reads "No room system is connected".

## Declarative or imperative

- The inbox schema, its authorization and the seat field: declared.
- The widget: a thin custom component. A declared `object-list` could show the
  rows, but not the "Apply again" action on a rejected one.
- Applying a message: imperative, a listener that calls two existing services.
  ADR-031 has no extension that calls an app's own voting guard, and the rule
  it protects (`castVote()`) is already PHP.
- The delegate system protocols: integriq, under the ADR-031 exception for
  external systems.

## Seed data

In `lib/Settings/profiles/municipality.json`:

1. Two council members with `roomSeat` `12` and `14`.
2. A `speech` message from seat 12 on agenda item "Vaststelling
   omgevingsvisie", 19:42 to 19:46, `applied`.
3. A `vote` message from seat 27 on the round for motion "Meer groen in de
   wijk", `rejected`, "Seat 27 is not linked to a member".

## Risks

- A delegate system that sends votes before the round opens in decidiq:
  rejected with "The voting round is not open", visible in the widget, and
  applied again once the chair opens the round.
- Clock skew between the room system and Nextcloud shifts speech times. The
  times are the room system's, recorded as sent.
