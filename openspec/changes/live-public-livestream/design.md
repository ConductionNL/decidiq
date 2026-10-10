# Design: live-public-livestream

Kind: code. One schema fragment, one connection declaration, one thin service
with its controller, one widget, one portal collection, one caption derivation.
Read against decidiq `development` at 4d7430ff.

## What exists today

- **Meeting.** `lib/Settings/decidesk_register.json:757`, schema `meeting`.
  `isPublic` (`:953`) is the staff intent flag for public visibility. `location`
  (`:838`) holds the physical address or a video-conference link, and
  `virtualLocation` (`:936`) is deprecated and hidden. Nothing on the meeting
  names a stream.
- **Transcript.** `lib/Settings/decidesk_register.json:3278`, schema
  `transcript`. It holds file references and timed `segments` with neutral
  speaker labels and an `agendaItem` per segment, never audio. It is marked
  `x-decidesk-publishable: false`. `TranscriptionService::process()`
  (`lib/Service/TranscriptionService.php:256`) writes the segments, and
  `TranscriptAlignmentService::buildTimeline()` and `alignSegments()`
  (`lib/Service/TranscriptAlignmentService.php:181`, `:146`) join them to agenda
  windows in seconds from the meeting start.
- **The deny-list.** `lib/Service/PublicationEligibilityService.php:77`
  (`DENY_TYPES`, includes `transcript` and `recording`), `:103`
  (`DENIED_SCHEMAS`) and `:113` (`DENIED_FILE_MARKERS`). The spec is
  `openspec/specs/meeting-transcription/spec.md`, requirement "Confidentiality
  and retention of recordings and transcripts".
- **Staff guard.** `TranscriptionStaffGuard::forMeeting()`
  (`lib/Service/TranscriptionStaffGuard.php:100`) lets the chair or secretary
  of the meeting act and fails closed for everyone else.
- **Public read.** `PublicationPayload` (`lib/Settings/decidesk_register.json`,
  `authorization` at `:5285`) grants the `public` group read when
  `publicationDate <= $now`. That is the one anonymous-read predicate decidiq
  uses today.
- **Portal.** `lib/Portal/PortalContributionProvider.php:136`
  `getContribution()` serves the `citizen` audience with four collections
  (`:219`). None is anonymous. portaliq's contract accepts `anonymous: true` on
  a collection (portaliq `lib/Contribution/IPortalContributionProvider.php`,
  portal-page-provisioning), surfaced by `aggregateAnonymous()`.
- **Connections.** `lib/Settings/connections.json` declares `ori`, `eidas` and
  `translation` for integriq's connection registry (`adopt-connection-registry`).
- **Meeting page.** `src/manifest.json:561` `MeetingDetail`, widgets from
  `:569`, the Transcription widget at `:582` and its slot at `:622`, registry
  entry `src/registry.js:219`. The page note says it is edited directly, not
  through a `manifest.d` fragment, because `mergePages` replaces a page whole.

## D1. A broadcast is its own schema, not fields on the meeting

`MeetingBroadcast` (slug `meeting-broadcast`, `schema:BroadcastEvent`) in a new
fragment `lib/Settings/register.d/92-meeting-broadcast.json`, listed in the
register's `schemas` the way `90-attach-every-declared-schema.json` requires.

Why not fields on `Meeting`: the anonymous read predicate is per schema. Putting
`playerUrl` on the meeting would make the meeting row anonymously readable or
leave the stream unreachable. A separate object carries exactly what a resident
may see.

Properties:

| Property | Type | Purpose |
|---|---|---|
| `meeting` | uuid, `$ref: meeting` | one broadcast per meeting |
| `title`, `bodyName`, `scheduledDate` | string | copied at creation, so the public row needs no join to a private meeting |
| `lifecycle` | enum | `planned`, `testing`, `live`, `paused`, `ended` |
| `previewUrl` | uri | staff preview of a test, never in the portal whitelist |
| `playerUrl` | uri | public player, written only when the broadcast goes live |
| `recordingUrl` | uri | the service's public recording, written after `ended` |
| `testedAt`, `testedBy`, `testResult`, `testNote` | | the last test and what the clerk found |
| `liveCaptions` | enum | `requested`, `unavailable`, `off` |
| `publicWindows` | array of `{start, end, recordingStart}` | seconds from the meeting start while live, and where each window begins in the recording |
| `captionTracks` | array | `{language, filePath, shareUrl, reviewedBy, releasedAt}` |
| `publicationDate`, `depublicationDate` | date-time | the anonymous-read predicate |

`authorization.read` copies the `PublicationPayload` rule: `public` when
`publicationDate <= $now`, plus `authenticated`. Create and update stay with the
body's staff through OpenRegister RBAC.

## D2. The lifecycle is declared

`x-openregister-lifecycle` on the schema, canonical dialect (`initial`):
`planned -> testing -> planned` (a test ends back in planned), `planned ->
live`, `testing -> live`, `live -> paused -> live`, `live -> ended`, `paused ->
ended`. `ended` is terminal. The service never sets `lifecycle` by hand.

## D3. The streaming service sits behind integriq

A new connection `streaming` in `lib/Settings/connections.json` with a
`sourceTemplate`, so an administrator links the organisation's streaming
service in integriq's Connections overview (hydra `connection-registry`).
integriq holds the adapter: create an event, start a test, start, pause,
resume, stop, fetch the recording, attach a caption file. That adapter is
integriq's half, to be specified in integriq.

decidiq calls integriq's call service against the linked source. It does not
use the `Db\SourceMapper` lookup `EIDASSignatureService` uses
(`lib/Service/EIDASSignatureService.php:415`), which integriq no longer ships.
Without a linked source every broadcast action answers 409 with "No streaming
service is connected", and the widget shows that text instead of buttons.

## D4. The staff actions

`BroadcastController` with six routes in `appinfo/routes.php`, each guarded by
`TranscriptionStaffGuard::forMeeting()`:

- `POST /api/meetings/{meetingId}/broadcast/test`: the adapter starts a test,
  decidiq writes `previewUrl`, `lifecycle: testing`.
- `POST /api/meeting-broadcasts/{id}/test-result`: `testResult` (`ok` or
  `problems`), `testNote`, `testedBy`, `testedAt`; back to `planned`.
- `POST /api/meeting-broadcasts/{id}/start`: refused unless the meeting is
  `isPublic`. Writes `playerUrl`, `publicationDate` (now) and opens a public
  window.
- `.../pause` and `.../resume`: close and open a public window.
- `.../stop`: closes the last window, `lifecycle: ended`, and asks the adapter
  for the recording address.

`MeetingBroadcastService` holds the adapter calls and the window arithmetic.
The windows are seconds from `Meeting.openedAt`, the same origin
`TranscriptAlignmentService` uses, so a transcript segment and a public window
compare directly.

## D5. Subtitles for the recording

Transcript segment offsets are seconds from the meeting start: that is the
origin `TranscriptAlignmentService::buildTimeline()` normalises agenda windows
to (`lib/Service/TranscriptAlignmentService.php:181`). Each public window stores
`start` and `end` in that same origin, plus `recordingStart`, the second in the
service's recording where the window begins. The adapter reports it; when the
service cuts paused time, it is the sum of the earlier windows.

`BroadcastCaptionService::derive()` takes a transcript in status `done` with
`alignedAt` set, keeps only the segments that start inside a public window, and
writes one WebVTT cue per segment at `segment.start - window.start +
window.recordingStart`, without the speaker label. It refuses a transcript
without `alignedAt`, because then nothing proves the offsets share an origin.
The file goes to the meeting folder as `captions-<language>.vtt`, a name
without the `recording` and `transcript` markers the deny-list matches on
(`lib/Service/PublicationEligibilityService.php:113`).

The clerk reviews the file in the Broadcast widget, corrects it in Nextcloud
Text if needed, and releases it. Release creates a read-only public link share
through `OCP\Share\IManager` and asks the adapter to attach the file to the
recording when the service can. `captionTracks[].shareUrl` records the link.

The transcript object, its text file and the NC recording file stay on the
deny-list. The only new public artefact is the released caption file, and the
transcript retention job (`TranscriptRetentionJob`) does not delete it: it
follows the public recording, not the transcript.

## D6. Residents see it through portaliq

The `citizen` contribution (`citizenCollections()`,
`lib/Portal/PortalContributionProvider.php:219`) gains one collection flagged
`anonymous: true`. portaliq's `aggregateAnonymous()` asks each declared
audience for its contribution and keeps only the anonymous entries, so no new
audience is needed (portaliq `lib/Contribution/PortalContributionRegistry.php`,
`anonymousContributionsFor()`):

```
{ id: 'publicBroadcasts', register: 'decidiq', schema: 'meeting-broadcast',
  anonymous: true, listable: true, label: 'Live and recent meetings',
  fields: ['title', 'bodyName', 'scheduledDate', 'lifecycle', 'playerUrl',
           'recordingUrl', 'captionTracks'],
  columns: [{field: 'playerUrl', render: 'link'}, ...],
  defaultSort: {field: 'scheduledDate', direction: 'desc'} }
```

`previewUrl`, `testNote`, `testedBy` and `publicWindows` are never whitelisted.
A planned or testing row has no `playerUrl` and no `publicationDate`, so even
an unfiltered read shows no stream. The provider stays duck-typed and inert
without portaliq (ADR-046).

## Declarative or imperative

- Lifecycle: declared (`x-openregister-lifecycle`), D2.
- Anonymous read: declared (`authorization.read` published predicate), D1.
- Portal surface: declared contribution (ADR-046), D6.
- The adapter calls: imperative, under the ADR-031 exception for external API
  integrations, and the adapter itself lives in integriq.
- The caption file: imperative, under the ADR-031 exception for rendered
  document output. It is a pure derivation over stored segments.

## Seed data

Three objects in `lib/Settings/profiles/municipality.json` (ADR-001):

1. The council meeting of 12 March, `lifecycle: ended`, `playerUrl` and
   `recordingUrl` at `https://stream.example.org/...`, two public windows
   (0 to 5400 and 6300 to 10800 seconds, a closed session between), one
   released Dutch caption track.
2. The committee meeting of 19 March, `lifecycle: planned`, a test run the day
   before with `testResult: problems` and the note "Microphone 4 had no sound,
   replaced".
3. The council meeting of 26 March, `lifecycle: planned`, no test yet, no
   `publicationDate`.

## Risks

- A service that cannot report its recording address leaves `recordingUrl`
  empty. The widget says so, and captions can still be released as a file.
- Offsets: a transcript made from a recording that did not start at the
  meeting start drifts. The derivation refuses an unaligned transcript, and the
  clerk reviews every track before release.
