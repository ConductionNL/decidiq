# Design: meeting-video-call

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Mode | `lib/Settings/decidesk_register.json:845` meetingMode; `:936` virtualLocation deprecated |
| Talk widget | `src/manifest.json` MeetingIntegrations mi-talk |
| Leaf | talk integration leaf declared for meeting |

## Approach

1. Move the mi-talk widget onto MeetingDetail with a visibility condition on meetingMode.
2. Room creation goes through the existing talk leaf (OpenRegister integration), participants from the meeting's participants.

## Declarative or imperative

Manifest only where possible (widget placement and condition); the room creation is the talk leaf's.

## Tests

- manifest validation: the widget is on MeetingDetail with the condition.
- vitest: the Join button shows only with a linked room.
