# Design: live-recording-jump-to-item

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Alignment | `lib/Service/TranscriptionService.php:344` align() |
| Widget | `src/components/tabs/MeetingTranscriptionTab.vue:130-146` |
| Sources | `lib/Service/TranscriptionSourceResolver.php` recordings in the meeting folder or Talk |

## Approach

1. Segment start times already sit on each segment (`startTime`); the widget takes the earliest per agenda item (`src/utils/recordingJump.js` itemStartTimes).
2. The player streams the source file from GET /api/transcripts/{transcriptId}/recording (TranscriptionController::recording), not a WebDAV URL: the source lives in the app's meeting folder, which members cannot reach over WebDAV. The endpoint answers Range requests with 206 (RecordingRange) so the player can seek, and admits any participant of the meeting (TranscriptionStaffGuard::forTranscriptListener) besides chair, secretary and admins.

Design corrected at build (29 Sep): the proposal's third point, a link on AgendaItemDetail, is left out; the requirement names the meeting Transcription widget, where Play from here sits on each agenda item heading.

## Declarative or imperative

Imperative UI only; no schema change.

## Tests

- PHPUnit: align() output carries a start time per item (red if missing).
- vitest: Play from here seeks to the item start.
