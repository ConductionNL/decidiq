# Design: agenda-templates-and-copy

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Recurring dialog | `src/dialogs/RecurringItemsDialog.vue`, `src/components/AgendaBuilder.vue:346,795-801` |
| Series | `lib/Service/MeetingSeriesService.php` |
| Agenda widget | `src/components/tabs/MeetingAgendaTab.vue` |

## Approach

1. Register fragment for AgendaTemplate; a dialog `src/dialogs/AgendaCopyDialog.vue` with two sources (template, earlier meeting) creating agenda-item objects with orderIndex after the last one.
2. MeetingSeriesService copies agenda items onto each generated meeting.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- vitest: copy from meeting builds item payloads that validate against the real AgendaItem schema.
- PHPUnit: MeetingSeriesService copies agenda items (red before).
