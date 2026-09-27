---
kind: code
depends_on: []
---

# Proposal: minutes-in-unified-search

## Summary

A member who types a word into Nextcloud's search bar finds decidiq's decisions and meetings, but not its minutes. What was said about a subject is in the minutes. This change adds minutes to decidiq's unified search section, with the meeting and the approval date on each hit, and opens the minutes page when clicked.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### min-15, search across all past decisions and minutes

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`. Matrix note: "Decisions are searchable across meetings from Nextcloud search, minutes are not; minutes can only be searched on their own index."

No demand row (origin `code`).

Competitor cells rated yes:

- notubiz: "https://www.kennisbank.notubiz.nl/handleidingen/zoeken-in-politiek-portaal states full text search across documents, agenda items, speaking moments and module items with filters"
- ibabs: "https://support.ibabs.com/docs/zoeken-in-ibabs.md states all documents are indexed and agendas, decisions and minutes are searchable with operators"
- go-raadsinformatie: "https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._search/ states GO. search covers all searchable data including video, with filters set by the griffie"
- openslides: "source read at 4.3.4, not driven: openslides-backend/meta/search.yml:81 the search service indexes motion number, title, text, reason and amendments, plus agenda items, polls, topics and files, and openslides-client/client/src/app/site/modules/global-headbar/components/global-search/global-search.component.html:29 searches this meeting or all meetings. Past decisions are searchable as motions; there are no minutes to search."

## Why

Four of five competitors search decisions and minutes together. decidiq specified this once: archived `2026-05-11-p2-minutes-and-decisions-core-t2`, REQ-NSP-002 "Minutes appear in Nextcloud unified search". The provider that exists today, `lib/Search/DecidiqSearchProvider.php:59-62`, searches two schemas, `decision` and `meeting`. Minutes were never added, so the capability shipped half.

## What changes

1. `DecidiqSearchProvider` also searches the `minutes` schema, which covers the minutes' title, content and per-item notes.
2. A minutes hit shows "Minutes", the lifecycle and the approval date, and links to `/minutes/{id}`.
3. The subline separator becomes a middle dot, because the current em-dash breaks the house style for user-facing text.
4. Results stay limited to what the searcher may read, through OpenRegister's own read rules.

## Out of scope

- Boolean operators, accent folding and highlighting (matrix row pub-19, openregister's `search-quality-operators-and-facets`, `search-accent-insensitive` and `zoeken-filteren`).
- Searching inside attached paper files.

## Risks

- Five more queries per search keystroke burst. Unified search already debounces, and each schema stays at a limit of five hits.
