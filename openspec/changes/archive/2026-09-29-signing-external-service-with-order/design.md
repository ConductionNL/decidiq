# Design: signing-external-service-with-order

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Service | `lib/Service/EIDASSignatureService.php:99` initiate, `:302` stores the result, `:410-416` and `:560` look up integriq Db\SourceMapper |
| Signers | `src/components/tabs/MinutesSignersTab.vue` |
| Connections | `lib/Settings/connections.json:14-19` eidas reportedOnly |

## Found while building task 1 (fix/eidas-integriq-source-lookup)

- integriq has no `Db\SourceMapper`: its sources are OpenRegister objects (register `integriq`, schema `source`), found by slug through ObjectService, as integriq's own `ConnectionStore::findSourceBySlug()` does. Both lookups (eidas-qes and docudesk-signing) now go that way.
- `CallService::call()` returns the call log as an OpenRegister object whose data holds `response.body`; decidiq read `getResponse()`, which that object does not have, so every answer would have decoded as empty. The body is now read from `getObject()['response']['body']`, with `getResponse()` kept as a fallback.
- For task 2: `finalizeMinutes()` writes `pdfArchiveReference`, `hashSha256`, `signingCompletionDate`, `eidasSignatureLevel` and `version` onto the minutes, and the Minutes schema declares only `signedBy`. Task 2 has to declare them (or a signing record) before a signed copy can be stored back.

## Built in task 2 (feat/signing-order-and-send)

- Register fragment 97 declares `signers` (participant, order, signedAt), `signingRequestId`, `signingStatus` (sent, signed, failed), `signedCopy`, `signedCopyHash` and `signedAt` on Minutes, Meeting and Decision. `finalizeMinutes()` now writes those fields; it wrote `version: signed` into the integer revision and signature tuples into the list of names, so OpenRegister refused the save.
- `SigningRoundService` maps minutes, decision-list (the meeting) and motion (a decision) to their schema, sorts the signers on `order`, sends through `IEIDASSignatureService::initializeSigningRequest()` with `subjectType` and `signingOrder: sequential`, and records the request on the record. `collect()` asks the signing service's `status` action (`fetchSigningResult()`); on signed it stores the base64 `document` in the record's files through OpenRegister's `FileService::addFile()` and links it as `signedCopy`. `SignedCopyCollectorJob` runs `collectAllSent()` every fifteen minutes, which is what makes the store-back automatic.
- `SigningController` (`POST /api/signing/{subjectType}/{subjectId}/send|collect`) is guarded by `GovernanceScopeGuard::isSignatoryForSubject()`: the body's signatory scope, reached through the record's meeting.
- The Signers widget (`MinutesSignersTab`) takes `schema` and `subjectType`; `MotionSignersTab` and `DecisionListSignersTab` point it at motions and meetings. Proposals are motions in this register (a Decision of decisionType motion), so the motion page covers them.
- Contract with the signing source, for integriq's connection: `status` takes `{requestId}` and answers `{status, document (base64), fileName}`.

## Approach

1. Read integriq development for the call service it ships today and use it; a test against the real integriq interface name.
2. Generalise the service to subjectType (minutes, decision-list, motion); routes for each.
3. Order field on the signer entries.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: the service resolves integriq's real call class (red before: SourceMapper missing).
- PHPUnit: the request lists signers in order.
