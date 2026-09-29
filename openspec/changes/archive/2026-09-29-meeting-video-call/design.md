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

## Corrections at build (2026-09-29, read at development f1913ba2)

- A new custom widget src/components/tabs/MeetingVideoCallTab.vue on MeetingDetail, not the generic mi-talk integration widget: the manifest has no per-widget condition on an object field, and the generic widget has no Join button. The widget renders nothing for an in-person meeting (hasVideoCall in src/utils/videoCall.js).
- The room is created and linked through OpenRegister's Talk integration (POST /api/objects/decidiq/meeting/{id}/talk/new, and POST .../talk with a roomToken to link one), the same link the MeetingIntegrations page and its mi-talk widget read. OpenRegister adds only the creator to a new room, so the widget then invites the body's current members through Talk's own API (POST /ocs/v2.php/apps/spreed/api/v4/room/{token}/participants); a member who cannot be added does not stop the others.
- Create and Link are offered to the chair or secretary (the meeting's my-roles answer, canManageAgenda); everyone sees Join video call once a room is linked.
- The row's owner moves from nextcloud/spreed to ConductionNL/decidiq: the missing half was the meeting page, not Talk.
