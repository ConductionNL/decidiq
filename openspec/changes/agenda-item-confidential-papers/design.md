# Design: agenda-item-confidential-papers

Read at decidiq development `a13c5382`.

## Screen

Board **DcAgendapunt** on the Zuiddrecht design canvas (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a, page dcb81aee8d83).

| Board element | This change |
|---|---|
| Kerngegevens: "Soort", "Fase", "Duur", "Formaliteit", "Openbaar: Ja" | "Openbaar" is the item's `public` field; the griffier edits it through Bewerken. When it is Nee, a line "Alleen voor leden van {orgaan} en wie de griffie toevoegt" follows, with "Lezers toevoegen" for the secretariat |
| Stukken list: per paper the badge "Openbaar" | the badge is the paper's `public` field; on a non-public item every paper shows "Besloten" |
| Header: "Agendapunt 7", type, phase | unchanged for authorised readers; others see "Besloten punt" and the number only |

The geheimhouding itself is recorded in the Geheimhoudingen register (board DcRegisters) and shown on the decision (DcBesluit); this change links to it from the item when one exists.

## What exists

| Piece | Where |
|---|---|
| AgendaItem | `lib/Settings/decidesk_register.json` and fragments 70, 98, 111, 112, 122 |
| DigitalDocument | `lib/Settings/decidesk_register.json`, fragments 110, 115 (`securityClassification` for archiving) |
| ConfidentialityRestriction | `lib/Settings/register.d/83-confidentiality-in-plain-words.json` (scope, targetAgendaItem, lifecycle) |
| Conditional read rule dialect | `lib/Settings/register.d/119-paper-summaries.json` (`{ "group": ..., "match": {...} }`) |
| Meeting pack | `lib/Service/MeetingPackageService.php` |
| Public publication | `lib/Service/PublicationService.php`, OpenRegister published predicate |

## Approach

- **Fields.** A new fragment `lib/Settings/register.d/124-agenda-item-confidential-papers.json` adds `public` (boolean, default true) and `authorisedReaders` (array of user ids) to AgendaItem and `public` to DigitalDocument.
- **Read rules.** The same fragment gives AgendaItem and DigitalDocument an `authorization.read` list: secretariat and administrator groups unconditionally; `{ "group": "decidesk-members", "match": { "public": true } }`; and a rule for the body's member group and `authorisedReaders` on non-public objects. The body-scoped group comes from `GovernanceRoleScopeProjector`, which already projects body roles into OpenRegister scopes (`authorization-via-or-rbac` REQ-RBAC-001). If OpenRegister's rule dialect cannot express "user is in `authorisedReaders`", that is an OpenRegister ask, recorded in tasks.md; decidiq does not filter in PHP instead.
- **Inheritance.** A paper of a non-public item must not be readable on its own. `public` on a DigitalDocument is set false by a listener when its item is or becomes non-public (`lib/Listener/AgendaItemConfidentialityListener.php`); the read rule then holds without a join.
- **Restriction imposes.** The same listener sets `public` false on the item when a ConfidentialityRestriction with scope item reaches `imposed`.
- **Pack and publication.** `MeetingPackageService` skips papers it cannot see as an anonymous public reader and lists the item as "Besloten punt". `PublicationService` refuses a non-public item, as `embargo-geheimhouding` REQ-EMB-009 does for an active geheimhouding.
- **View log.** Reading a paper of a non-public item writes an audit entry through OpenRegister's audit trail (`AuditLogService::append()`), not a decidiq table.

## Declarative or imperative

Fields and read rules are declarative. The listener is the one imperative piece, because the item-to-paper inheritance is a write on another object.

## Tests

- Register test: the fragment loads, the read rules parse in OpenRegister's real RBAC evaluator, and a member of another body cannot read a non-public paper while a member of the meeting's body can.
- `AgendaItemConfidentialityListenerTest`: making an item non-public sets its papers' `public` false; imposing a restriction sets the item's.
- `MeetingPackageServiceTest`: a non-public item's papers are not in the pack.
- Playwright `tests/e2e/confidential-agenda-item.spec.ts`: a member of the body opens the paper; a user outside it sees "Besloten punt".
