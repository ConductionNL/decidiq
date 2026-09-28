# Design: motions-technical-questions-to-officials

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Type seed | `lib/Settings/profiles/municipality.json:800` Technische vraag fields |
| Fields tab | `src/components/tabs/AgendaItemTypeFieldsTab.vue` |
| Window | `lib/Settings/register.d/82-planning-cycle-in-plain-words.json:385` |

## Approach

1. Add the two fields to the seeded type; a listener on agenda item update notifies on assignment and on answer through NotificationPreferenceService.
2. An index page over agenda items of that type with a computed overdue state.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: listener notifies the assigned official once (red before).
- vitest: the list marks a question past its deadline as overdue.
