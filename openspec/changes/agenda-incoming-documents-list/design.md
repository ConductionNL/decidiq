# Design: agenda-incoming-documents-list

Read at decidiq development `ace9aa2a`.

## What exists

| Piece | Where |
|---|---|
| Widget | `src/components/tabs/MeetingRoutedDocumentsTab.vue:167-174` reads retired schemas |
| Types | `lib/Settings/register.d/85-documents-as-agenda-items.json`, seed `lib/Settings/profiles/municipality.json` Ingekomen stuk |
| Agenda | `src/components/tabs/MeetingAgendaTab.vue` |

## Approach

1. Helper src/utils/incomingDocuments.js decides which agenda item types count as incoming (seed key or a type flag declared in a new fragment).
2. Widget and new manifest page in src/manifest.d/incoming-documents.json; Put on agenda through a dialog under src/dialogs/.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that carries the behaviour.

## Tests

- vitest: widget lists an incoming document item of the meeting; the list shows unplanned ones; the agenda item payload is valid against the agenda-item schema.

## Built (5 Oct 2026)

- Incoming means: the item's type has `incomingDocument: true` (new property on AgendaItemType, `lib/Settings/register.d/122-incoming-documents.json`), or, for a type stored before that property existed, its slug is one of the two seeded kinds (`type-ingekomen-stuk`, `type-raadsinformatiebrief`). Both seeds now carry the flag.
- `MeetingRoutedDocumentsTab` reads the meeting's agenda items and the agenda item types; the old two-hop join (`routedDocumentsJoin.js`) and its test are removed. Rows open the agenda item.
- The Incoming documents page is `type: custom` (`IncomingDocumentsView`), re-homed under Meetings by `src/menu-layout.json`. Put on agenda only offers upcoming meetings and saves the item with the meeting and the position after the meeting's last item; `orderNumber` is required on AgendaItem.
- The payload is validated in vitest against the merged agenda-item schema (`tests/vitest/helpers/registerSchema.js`).
