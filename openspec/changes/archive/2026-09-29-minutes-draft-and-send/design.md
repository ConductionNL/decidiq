# Design: minutes-draft-and-send

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Draft | `lib/Service/MinutesGenerationService.php:90`, route `appinfo/routes.php:98` |
| AI draft | `lib/Service/MinutesDraftService.php:117,163`, `src/components/tabs/MeetingTranscriptionTab.vue:549` |
| Distribute | `lib/Service/ALVMinutesService.php:173`, route `appinfo/routes.php:104` |

## Approach

1. A minutes actions widget with the two buttons, shown by lifecycle.
2. MinutesDraftRenderer adds an attendance section.
3. A write-back endpoint (or object store save) that copies the AI draft into the minutes record.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: the rendered draft contains the attendance section (red before).
- vitest: Use as minutes saves content onto the minutes; Send to members calls distribute.

## Corrections at build (2026-09-29, read at development 74e07a5b)

- Draft from the meeting: MinutesDraftRenderer gains an "Aanwezigheid" section (present, sent apologies, absent, represented by proxy, each with its count) from the meeting's meeting-attendance records (MeetingAttendanceReader). generateDraft read the meeting id off getObject(), which carries no id, so every related list came back empty; it now falls back to the minutes' own meeting reference. The widget MinutesActionsTab saves the rendered text into the minutes' content; when the minutes already have text it asks once before replacing it.
- Use as minutes: a client-side write (object store), not a new endpoint. The kept sections become the content (one heading per section) and each section's summary becomes the notes of its agenda item in itemNotes; other items' notes stay. It writes into the meeting's draft minutes, creates them when there are none, and refuses minutes past the draft stage.
- Send to members: ALVMinutesService::distribute notified nobody. ParticipantNotifier looks up a container service `OpenRegisterNotificationService` that OpenRegister does not register, MinutesContextResolver::linkedMeetingId read a capitalised `Meeting` relation real minutes do not carry, and activeParticipants filtered on `_relations.governance-body`, which matches nothing for objects made through the object API. distribute now reads the meeting from the minutes' `meeting` property, takes the members from ParticipantResolver::resolveMeetingParticipants (both relation shapes, leftAt skipped) and sends each the notice "The minutes of <meeting> are available" (subject minutes_available, links /minutes/<id>) through NotificationPreferenceService (event decisionPublished, the member's own bell or email choice). Published minutes can be sent too.
- Not built: attaching the minutes PDF to the email. The notice links the minutes page, whose Documents widget holds the generated PDF; attaching it needs the file read from the meeting folder and is left for a follow-up.
