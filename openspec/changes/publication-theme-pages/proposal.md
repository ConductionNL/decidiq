---
kind: code
depends_on: []
---

# Proposal: publication-theme-pages

## Summary

Some subjects run for years: a new swimming pool, the energy transition, a reorganisation. Residents and council members want one page that tells the story: what it is about, which meetings discussed it, which papers and motions came out, and what happens next. This change lets the griffie create a theme, link the meetings, agenda items, decisions, motions, commitments and long-term agenda items that belong to it, and publish it as a theme page with a timeline.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### pub-20, publish a theme page that gathers the meetings, documents and motions on one long-running topic with a timeline

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "There is no public page that gathers meetings, documents and motions on one topic with a timeline. Staff can relate decisions to an organisation goal, which is a record with a Related list, not a published theme page."

Demand: origin `changelog`, https://support.ibabs.com/docs/themapaginas.md

Competitor cells rated yes:

- notubiz: "https://www.notubiz.nl/onze-diensten/themadossiers states Themadossiers pages with text blocks, a timeline of meetings and documents and linked motions, questions and commitments"
- ibabs: "https://support.ibabs.com/docs/themapaginas.md (updated 2026-02-27) states theme pages link agenda items and list items such as motions and are shown on the Publieksportaal"
- go-raadsinformatie: "https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._dossiers/ gathers documents, votes, meetings and council instruments per topic with a timeline view"

## Why

NotuBiz Themadossiers, iBabs theme pages and GO. dossiers all do this, and all three put a timeline at its heart. decidiq has the objects a theme gathers and a publication path for them (`PublicationService::publish()` writes a payload with a publication date that OpenRegister serves anonymously, and routes it to the body's OpenCatalogi catalog), but no object that says "these belong together" and no public page for it. The nearest thing, an organisation goal (`GoalDetail`, `/goals/:id`), is an internal record with a Related list, not a published page.

## What changes

1. A new `Theme`: title, introduction, text blocks, status (active, concluded), responsible body and portfolio holder, and links to meetings, agenda items, decisions (motions included), commitments and long-term agenda items.
2. A theme page for staff with the links and a timeline built from the linked items' dates, newest last, and an Add to theme action on the agenda item and decision pages.
3. A Publish action that publishes the theme through the existing publication path as a new publication type `theme`. Its payload carries the introduction, the blocks and a timeline of only those linked items that are published themselves, each with a link to its own publication.
4. Republishing a theme after a new link or a new publication refreshes its timeline; a scheduled refresh does the same nightly for active themes.

## Out of scope

- How OpenCatalogi draws a timeline. The payload carries a `timeline` array; OpenCatalogi's publication page shows the payload, and a dedicated timeline block there is OpenCatalogi's to build (named in this lane's hand-back).
- Automatic suggestions of what belongs to a theme.
- Themes spanning several organisations.

## Risks

- A theme could reveal an unpublished item's existence. The payload only ever carries items that have their own publication, checked by `PublicationEligibilityService` at build time.
- A long-running theme grows stale. Concluded themes stop refreshing, and active themes refresh nightly.
