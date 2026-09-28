# Design: live-recording-jump-to-item

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Alignment | `lib/Service/TranscriptionService.php:344` align() |
| Widget | `src/components/tabs/MeetingTranscriptionTab.vue:130-146` |
| Sources | `lib/Service/TranscriptionSourceResolver.php` recordings in the meeting folder or Talk |

## Approach

1. Expose segment start times per item in the transcript payload (they exist on segments).
2. Player component streaming the source file through a WebDAV URL.

## Declarative or imperative

Imperative UI only; no schema change.

## Tests

- PHPUnit: align() output carries a start time per item (red if missing).
- vitest: Play from here seeks to the item start.
