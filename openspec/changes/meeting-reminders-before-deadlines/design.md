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
