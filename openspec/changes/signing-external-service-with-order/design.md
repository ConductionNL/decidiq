# Design: signing-external-service-with-order

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Service | `lib/Service/EIDASSignatureService.php:99` initiate, `:302` stores the result, `:410-416` and `:560` look up integriq Db\SourceMapper |
| Signers | `src/components/tabs/MinutesSignersTab.vue` |
| Connections | `lib/Settings/connections.json:14-19` eidas reportedOnly |

## Approach

1. Read integriq development for the call service it ships today and use it; a test against the real integriq interface name.
2. Generalise the service to subjectType (minutes, decision-list, motion); routes for each.
3. Order field on the signer entries.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: the service resolves integriq's real call class (red before: SourceMapper missing).
- PHPUnit: the request lists signers in order.
