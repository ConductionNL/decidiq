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

1. Register fragment 105: Meeting.currentAgendaItem. EngagementRecord keeps one record per meeting and participant with free `speeches` and `questionsRaised` event lists, so the agenda item goes on each event (`agendaItem`), not on the record.
2. LiveMeeting's activateItem() calls PUT /api/agendas/{meetingId}/current-item (AgendaController::currentItem, chair, secretary or admin; AgendaService::setCurrentItem checks the item is on the meeting and patches the meeting). Every live screen re-reads the meeting every 5 seconds; isChair from my-roles.
5. The speaker queue lists the contributions on the current item (speeches with their length, questions), read from GET /api/engagement?meeting=.

Design corrected at build (29 Sep): a meeting save from the browser would need write access to the whole meeting for the chair and would let any member with that access move the meeting on, so the current item goes through a guarded endpoint. LiveDecisionService saved to schema `Decision` with an undeclared `relations` key and no required `decisionType`; it now writes `meeting`, `agendaItem`, `decisionType` (default resolution) and an outcome only when one was taken.
3. A manifest page for the screen view reading the meeting and the projection state.
4. Record decision dialog posting to the existing live decision route.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- vitest: activateItem saves currentAgendaItem; a member view follows a changed value (red before).
- PHPUnit: EngagementService stores the agenda item; recordLiveDecision payload validates against the real Decision schema.
