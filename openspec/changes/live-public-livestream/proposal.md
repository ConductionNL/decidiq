---
kind: code
depends_on: []
---

# Proposal: live-public-livestream

## Summary

Residents can follow a public meeting live and watch it back with subtitles.
decidiq does not become a streaming server. An outside streaming service
produces the stream, integriq talks to that service, decidiq ties the stream to
the meeting and says when it is live, and portaliq shows it to people without a
Nextcloud account. Before the meeting the clerk runs a test broadcast that only
staff can see. After the meeting the clerk turns the transcript decidiq already
makes into subtitles for the recording, reviews them and releases them.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`. Three rows, one
cluster: all three share the broadcast on the meeting page.

### liv-06, stream the meeting live to the public

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Demand origin
`competitor`, no originUrl.

Competitor cells rated yes, verbatim:

- notubiz = yes | evidence: https://www.notubiz.nl/onze-diensten/live-uitzenden states fully managed live broadcasts in a player on the organisation's Politiek Portaal
- ibabs = yes | evidence: https://support.ibabs.com/docs/ibabs-stream-veelgestelde-vragen.md states a stream is linked to a meeting and shown live on the Publieksportaal
- go-raadsinformatie = yes | evidence: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/webcasting/ states meetings are broadcast live in audio and video and can be followed on the internet

### liv-10, show subtitles on the livestream or the recording

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Demand origin
`competitor`, no originUrl.

Competitor cells rated yes, verbatim:

- notubiz = yes | evidence: https://www.notubiz.nl/onze-diensten/live-uitzenden states live automatic captions via speech recognition; https://www.notubiz.nl/onze-diensten/videotulen states captions afterwards on the recording
- ibabs = yes | evidence: https://support.ibabs.com/docs/ibabs-stream-release-notes-januari-2026.md states the Subtitle Manager and per-stream captions; https://support.ibabs.com/docs/ibabs-stream-release-notes-februari-2026.md resizable captions
- go-raadsinformatie = yes | evidence: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/webcasting/ lists ondertiteling as a webcast option and https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/ondertiteling_vergroot_de_toegankelijkheid/ describes automatic and editorial subtitling

### liv-19, let the clerk run a test broadcast before the meeting

Name in the matrix: "Let the clerk run a test broadcast before the meeting to
check picture, sound and connection." Own rating `no`, built.state `none`, owner
`ConductionNL/decidiq`.

Demand row: origin `featureRequest`, originUrl
https://www.notubiz.nl/nieuws/samen-werken-aan-een-geintegreerd-systeem-voor-raad-en-college
(notubiz.nl news of 2025-11-03: the griffie of Schagen hopes soon to be able to
test broadcasts themselves).

Competitor cells rated yes, verbatim:

- ibabs = yes | evidence: https://support.ibabs.com/docs/ibabs-stream-veelgestelde-vragen.md states a test broadcast checking picture, sound and connection is possible beforehand

## Why

A council meeting is public by law (Gemeentewet article 23), and most residents
follow it on a screen, not in the public gallery. Every Dutch competitor that
rated yes streams the meeting into its public portal. decidiq has no broadcast
at all: `grep -i livestream` in `lib/` and `src/` finds only file stream
responses for exports.

Subtitles are the accessibility half. A recording without captions fails WCAG
2.1 success criterion 1.2.2 (captions, prerecorded), which a Dutch public body
must meet. decidiq already transcribes meetings into timed segments, so the
subtitles for the recording are a derivation, not a new pipeline.

The test broadcast is what a clerk asks for once streaming exists: the one
moment a clerk can find a dead microphone is before the chair opens the
meeting.

## What changes

1. A `MeetingBroadcast` schema in a new register fragment: one broadcast per
   meeting, a declared lifecycle (planned, testing, live, paused, ended), the
   public player address, the staff preview address, the test result, the
   public windows and the caption tracks. Anonymous read follows the same
   published predicate `PublicationPayload` already uses.
2. A `streaming` connection in `lib/Settings/connections.json`, so the
   streaming service is an integriq connection with a status an administrator
   can see. integriq holds the adapter for the streaming service.
3. Staff actions on the meeting page: run a test broadcast, go live, pause for
   a closed session, resume, and stop. Only the chair or secretary may act,
   through the guard the transcription endpoints already use.
4. A Broadcast widget on `MeetingDetail` that shows the state, the staff
   preview during a test, and the test result.
5. An anonymous collection in decidiq's portaliq contribution, so residents see
   live and recent broadcasts of public meetings without an account.
6. Subtitles for the recording: a WebVTT file derived from a finished,
   agenda-aligned transcript, limited to the public windows, reviewed by the
   clerk and only then released.
7. A narrow change to the transcript confidentiality rule: the transcript stays
   unpublishable, and a released caption track of a public broadcast is the one
   derived artefact that may be public.

## What this supersedes

This change supersedes the livestream and subtitle part of the open change
`raadsvergadering-livestream-transcript`. That change proposed `lib/Entity`
classes (`Livestream`, `Transcript`, `TranscriptSegment`, `Spreker`), migrations
under `lib/Migration` and four new database tables. That breaks the thin-client
rule in `openspec/config.yaml` ("Decidiq owns no database tables"), and none of
its 67 tasks were done. The transcript itself has since shipped as the
OpenRegister `transcript` schema (`lib/Settings/decidesk_register.json:3278`).

The rest of that change is not taken over here: speaker recognition from the
room's microphones belongs to `live-room-conference-system-link` (liv-22), and
transcript search and timestamp deep links stay unplanned.

## Out of scope

- Producing or hosting video. The streaming service encodes, hosts and plays;
  decidiq never touches video bytes.
- Live captions made by decidiq. Nextcloud's SpeechToText providers work on a
  finished file. Live captions are the streaming service's, requested through
  the adapter when the service offers them.
- A video player block in portaliq. The contribution declares the player
  address as a link column today; an embedded player block is portaliq's
  change, to be specified in portaliq.
- Speaker names in subtitles. Transcript speaker labels stay neutral, as
  `meeting-transcription` requires.

## Risks

- **Publishing derived text from a confidential transcript.** The caption track
  is released only for a meeting flagged `isPublic`, only for the windows the
  broadcast was live, and only after the clerk reviewed it. The transcript
  object and the NC recording file stay on the deny-list.
- **A test broadcast that leaks.** The test runs on the staff preview address,
  which is never in the portal field whitelist, and a broadcast gets its
  publication date only when it goes live.
- **The streaming service is an outside dependency.** Without the connection,
  the widget says so and offers nothing, rather than a Go live button that does
  nothing.
- **A closed session on a live stream.** Pausing is one action on the widget,
  and the caption track leaves out everything outside the public windows.
