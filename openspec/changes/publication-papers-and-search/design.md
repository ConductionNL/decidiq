# Design: publication-papers-and-search

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Payload | `lib/Service/PublicationPayloadService.php:177` buildAgendaPayload() |
| Publisher | `lib/Service/OpenCatalogiPublisher.php:72` |
| Tab | `src/components/tabs/PublicationActionsTab.vue:357` |

## Approach

1. Collect item files through the files leaf, filter out confidential ones, attach as OpenCatalogi publication attachments.
2. Extend the payload with the filter metadata.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: the agenda payload lists the public files and none of a confidential item (red before).
