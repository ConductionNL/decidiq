# Design: motions-technical-questions-to-officials

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Type seed | `lib/Settings/profiles/municipality.json:800` Technische vraag fields |
| Fields tab | `src/components/tabs/AgendaItemTypeFieldsTab.vue` |
| Window | `lib/Settings/register.d/82-planning-cycle-in-plain-words.json:385` |

## Design correction at build time

- No default deadline: an agenda item save cannot write back into the item it was fired for without a second save, and the griffier sets the deadline when assigning. The spec no longer promises five working days.
- The list is a widget on the meeting page (`MeetingTechnicalQuestionsTab`), which is the per-meeting filter the proposal asked for, instead of a new index page and menu entry.
- An agenda item type field could only hold text, a date, a choice or a free reference. Register fragment 96 adds the field type `user`: a person with an account, picked by name through the sharee search and stored as the user id, so the notice reaches the right account.
- The notices ride the existing task assigned switch rather than a new one.

## Approach

1. Add the two fields to the seeded type; a listener on agenda item update notifies on assignment and on answer through NotificationPreferenceService.
2. An index page over agenda items of that type with a computed overdue state.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: listener notifies the assigned official once (red before).
- vitest: the list marks a question past its deadline as overdue.
