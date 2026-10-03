# Design: meeting-ad-hoc-with-guests

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Authorization | `lib/Settings/decidesk_register.json:62` register authorization create authenticated, update and delete administrators |
| Participant dialog | `src/dialogs/MeetingParticipantAddDialog.vue:23` |

## Approach

1. Meeting schema authorization: update and delete also for the object owner (OpenRegister owner rule).
2. MeetingParticipantAddDialog guest tab; a GuestInvitationService sends the mail through IMailer with a public share of the meeting folder.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: RegisterAuthorizationTest asserts the owner rule on Meeting (red before).
- PHPUnit: GuestInvitationService sends one mail with the share link.
