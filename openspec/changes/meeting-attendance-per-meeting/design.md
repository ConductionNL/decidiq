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
