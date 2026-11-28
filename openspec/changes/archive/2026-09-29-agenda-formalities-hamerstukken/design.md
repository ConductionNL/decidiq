# Design: agenda-formalities-hamerstukken

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Live screen | `src/views/LiveMeeting.vue` hamerstuk tag handling |
| Service | `lib/Service/AgendaService.php` processHamerstukken() |
| Schema | `lib/Settings/decidesk_register.json:1255` AgendaItem, no tags or status |

## Built (feat/agenda-formalities)

- Register fragment 98 declares `isFormality`, `formalityOutcome` (adopted-without-debate) and `adoptedAt` on AgendaItem. `processHamerstukken()` adopts every pending formality (and any item still carrying the older `hamerstuk` tag) by patching those two fields, skips one already adopted, and answers the count. It wrote `status: completed`, which AgendaItem does not declare.
- Marking goes through `PUT /api/agendas/{meetingId}/items/{itemId}/formality` (`AgendaService::setFormality()`), guarded like reorder (chair, secretary or admin), refusing an item of another meeting and an adopted formality. A plain object save would have let anyone with write rights on agenda items mark one.
- MeetingAgendaTab: Mark as formality / Discuss this item row actions for chair and secretary, and a Formality column (Formality, or Adopted without debate). LiveMeeting lists the pending formalities (`src/utils/formalities.js`) and its Remove button uses the same endpoint.
- Not built: proposal item 4 (a member asks to discuss a formality before the meeting, clearing it with a note). It has no task and no scenario in this change; the chair's Discuss this item covers the chair side. A member-side request needs its own change.

## Approach

1. Register fragment declares isFormality, outcome and adoptedAt on AgendaItem.
2. MeetingAgendaTab gets a Formality toggle row action; LiveMeeting filters on isFormality.
3. processHamerstukken() patches the declared fields; test validates the payload against the real fragment.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: processHamerstukken payload validates against the real AgendaItem schema (red before: status undeclared).
- vitest: the toggle writes isFormality.
