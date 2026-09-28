# Design: agenda-formalities-hamerstukken

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Live screen | `src/views/LiveMeeting.vue` hamerstuk tag handling |
| Service | `lib/Service/AgendaService.php` processHamerstukken() |
| Schema | `lib/Settings/decidesk_register.json:1255` AgendaItem, no tags or status |

## Approach

1. Register fragment declares isFormality, outcome and adoptedAt on AgendaItem.
2. MeetingAgendaTab gets a Formality toggle row action; LiveMeeting filters on isFormality.
3. processHamerstukken() patches the declared fields; test validates the payload against the real fragment.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: processHamerstukken payload validates against the real AgendaItem schema (red before: status undeclared).
- vitest: the toggle writes isFormality.
