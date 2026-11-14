# Design: meeting-attendance-per-meeting

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Participant | `lib/Settings/decidesk_register.json:1224` attendanceStatus |
| Widget | `src/components/tabs/MeetingParticipantsTab.vue:224,240` writes an undeclared meetings array |
| Report | MeetingsReport meet-absent, meet-by-attendance |

## Approach

1. Register fragment with the attendance schema; widget writes one object per meeting and person.
2. Fix the undeclared meetings array by linking through Attendance.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: the attendance fragment validates in the register walk.
- vitest: setting absent writes an attendance object for that meeting only.

## Corrections at build (2026-09-29, read at development 9fd0aa28)

- The schema slug is `meeting-attendance`, not `attendance`: schema slugs are global across the fleet (gate-106) and `attendance` is too generic. The record names a `participant` (the Participant the widget lists), not a person.
- The widget's rows are the members of the meeting's body plus any guest who has an attendance record for the meeting. Adding a guest writes a present record; removing a guest deletes it; body members cannot be removed. The undeclared `meetings` array is no longer read or written.
- The quorum check (MeetingRuleSource::quorumMet) and the meeting cost (MeetingCostService) read this meeting's records through MeetingAttendanceReader. A meeting with no records keeps the participant's own attendanceStatus, so meetings held before this change read as before; on a meeting with records, a member without one reads as not recorded. A failed read also falls back to the participant value (logged).
- The report widgets meet-absent and meet-by-attendance count meeting-attendance records (filter status absent, group by status), so each meeting's absentees are counted once for that meeting.
