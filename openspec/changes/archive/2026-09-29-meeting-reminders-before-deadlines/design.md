# Design: meeting-reminders-before-deadlines

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Preferences | `lib/Service/NotificationPreferenceService.php:51,56` meetingCreated, meetingReminder defaults; dispatch() |
| Jobs | `lib/BackgroundJob/VotingDeadlineReminderJob.php` pattern |
| Deadline | `lib/Listener/SubmissionDeadlineListener.php` enforces the deadline |
| Notifier | `lib/Notification/Notifier.php` |

## Approach

1. A listener on meeting lifecycle to scheduled dispatches meetingCreated.
2. A `MeetingReminderJob` (hourly, registered in info.xml) finds meetings starting within the window and deadlines within 48 hours; a stamp on the meeting (reminderSentAt, deadlineReminderSentAt) keeps it to once.
3. Notifier subjects with the meeting link.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: job test with the real NotificationPreferenceService signature sends once and honours an off switch (red before).
- PHPUnit: NotifierTest renders the subjects.

## As built (2026-09-29, read at development `41d43d26`)

- One hourly `MeetingReminderJob` (info.xml) runs `MeetingReminderService::run()` over the scheduled meetings. It sends the scheduled notice too, instead of a listener on the transition. That covers every way a meeting becomes scheduled: the stage button, the API, a type whose first stage is scheduled, or the MCP tool. The cost is up to an hour of delay. The first run after deploy announces the scheduled meetings that have not started yet, once each.
- Every notice goes through `NotificationPreferenceService::dispatch()`, so the member's switch, delegate and delivery choice apply. `meetingCreated` covers the scheduled notice; `meetingReminder` covers the start reminder and the submission deadline reminder. No switch exists for deadlines alone, and adding one was not asked for.
- The start reminder follows each member's own `reminderTimes` (settings: 1h, 4h, 24h, 48h, 1w; default 24h and 1h). It goes out once per time. A run that finds several times due sends one reminder and stamps them all. The deadline reminder goes out once, 48 hours before `submissionDeadline`.
- Stamps on Meeting (register fragment 101, Meeting 1.8.0): `scheduledNoticeSentAt`, `reminderSentTo` (member to times sent) and `deadlineReminderSentAt`.
- The same fragment sets `enabled: false` on the declarative OpenRegister notices `meetingScheduled` and `meetingReminder`. They reached every reader of the meeting and ignored the switches; the daily one repeated for as long as the meeting was scheduled. `meetingStartingSoon` (15 minutes, web push) is left on.
- Notifier renders `meeting_scheduled`, `meeting_reminder` and `submission_deadline` with the meeting name, a second line with the start or the deadline, and a link to the meeting.
