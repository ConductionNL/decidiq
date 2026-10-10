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

## Design corrections (29 Sep, at development 5ab7aeef)

- **Confidentiality was read from fields nobody writes.** `isConfidentialItem()` reads `isConfidential`, `confidential`, `confidentiality` and `visibility` on the agenda item; the agenda-item schema has none of them. Confidentiality lives in `confidentiality-restriction` objects (scope item, `targetAgendaItem`, lifecycle imposed / ratified / dissolved), which nothing on the publication path read, so a confidential item's title was published. New `lib/Service/AgendaPapers.php` reads them in system context (`_rbac: false`, `_multitenancy: false`) and fails closed: when they cannot be read, the agenda is not published (REQ-PPS-002). The old field check stays as a second net for inline items.
- **The agenda had no items.** `resolveAgendaItems()` passed `register` and `schema` beside `filters`; OpenRegister's `ObjectService::prepareFindAllConfig()` only reads them inside `filters`, so every agenda read from the register was published empty. Fixed.
- **Papers are Nextcloud files on the agenda item**, published through OpenRegister's `FileService::publishFile()` (a share link); the payload lists title, download link and type. A file labelled confidential (`confidential`, `vertrouwelijk`, `geheim`) is skipped. Document-scope restrictions target `digital-document` objects, not these files, so they are not matched here.
- **No OpenCatalogi attachments.** The payload object is what OpenCatalogi and the public API serve, so the papers ride in the payload rather than as separate catalogue attachments. The record keeps `publishedPapers` (agenda item + file id), so a withdrawal unpublishes them; a rectification keeps the papers its new version still publishes.
- **Filter metadata**: `documentType` on every payload (agenda, decision, minutes); `bodyName`, `meetingDate` and `documentType` are facetable (fragment 108). Theme is not added: agendas carry no theme; decisions carry `themes` since motions-stages-and-themes.
