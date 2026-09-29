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

## Design correction (29 Sep, at development 5ab7aeef)

- The commitment schema had no progress field. Fragment `107-commitment-progress.json` adds `progress` (array of `{date, note}`, Commitment 0.2.0), and a Progress widget on the commitment page lets the chair or secretary of the commitment's meeting add an entry through `POST /api/commitments/{id}/progress`. OpenRegister's update rule on commitments is administrators only, so the service checks the meeting role itself and writes with `_rbac: false`.
- The ORI `commitments` resource is gated by the schema's own published predicate (`publicationDate <= now`, not depublished), not by `lifecycle`: a commitment's lifecycle (open, in-execution, disposed, lapsed) is its progress, not its visibility. The allow-list lives in `OriSerializer` (text, status, deadline, progress date and note, settlement note, related motion, publication date).
- PublicationService does not gain a `commitment` sourceType: the ORI commitments resource is the public feed, and a PublicationPayload would duplicate it. A motion's execution status is its existing `status` (`enacted` once carried out); its progress is followed through the commitments whose `motion` names it.
