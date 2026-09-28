# Design: live-meeting-shared-current-item

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Live page | `src/views/LiveMeeting.vue` activateItem() local state; isChair from participants relation |
| Projection | `lib/Controller/ProjectionController.php:80` publicState() |
| Live decision | `lib/Controller/LiveMeetingController.php:122` recordLiveDecision() |
| Engagement | `lib/Service/EngagementService.php:84` captureEngagement(); `src/components/liveMeeting/SpeakerQueuePanel.vue:307` |
| Roles | GET /api/meetings/{meetingId}/my-roles (#1441) |

## Approach

1. Register fragment: Meeting.currentAgendaItem, EngagementRecord.agendaItem.
2. LiveMeeting saves activateItem() and polls the meeting every 5 seconds for members; isChair from my-roles.
3. A manifest page for the screen view reading the meeting and the projection state.
4. Record decision dialog posting to the existing live decision route.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- vitest: activateItem saves currentAgendaItem; a member view follows a changed value (red before).
- PHPUnit: EngagementService stores the agenda item; recordLiveDecision payload validates against the real Decision schema.
