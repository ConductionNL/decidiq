# Design: followup-public-progress

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| ORI | `lib/Controller/OriController.php:67,180-183` |
| Publication | `lib/Service/PublicationService.php:97` sourceType decision, agenda, minutes |

## Approach

1. Map the commitment schema into ORI with an allow-list of public fields.
2. Extend the sourceType allow-list and payload builder.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: GET /api/ori/v1/commitments returns only public fields (red before: 404).
