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

## Corrections at build (2026-09-29, read at development 4e5ff04f)

- Fragment 104 declares AgendaTemplate (slug agenda-template: name, description, items with title, kind, description, planned duration, configured type, formality) and MeetingType.defaultAgendaTemplate (MeetingType 0.2.0). The Agenda widget preselects the template the meeting's type names.
- One button, "Add from template or meeting", opens src/dialogs/AgendaCopyDialog.vue with both sources; with no item picked from an earlier meeting the whole agenda is copied. Items land after the last item (nextOrderNumber). "Save as template" on the Agenda widget saves the current agenda as a template named after the meeting; the settings foldout gets an Agenda templates list (manifest.d/agenda-templates.json) to rename or remove them.
- A copied item keeps title, kind, description, planned duration, configured type with its field values, and formality; it leaves its outcome, adoption time and measured durations behind. Sub-items are copied as top-level items. lib/Service/AgendaItemCopier.php and src/utils/agendaCopy.js keep the same field list.
- Papers (documents attached to an item) are not copied. Proposal item 3 ("papers are copied only when asked") is not built; no task or scenario asked for it.
- MeetingSeriesService::generateSeries copies the template meeting's agenda onto every generated meeting.
