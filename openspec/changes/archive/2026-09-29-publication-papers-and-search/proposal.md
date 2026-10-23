---
kind: code
depends_on: []
---

# Proposal: publication-papers-and-search

## Summary

Agendas and minutes are published to OpenCatalogi as titles only: the agenda payload strips every document reference, so citizens never get the papers, and there is nothing to filter on. This change publishes the public papers with each agenda item and adds the metadata citizens filter on.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pub-01, publish meetings, agendas and papers on a public website

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/PublicationService.php:105 publish() builds payload and calls OpenCatalogiPublisher; lib/Service/OpenCatalogiPublisher.php:72 publish() saves an opencatalogi 'publication' object (title/summary/reference only); lib/Service/PublicationPayloadService.php:177 buildAgendaPayload() emits meeting + agenda item titles, strips confidential items and ALL document references; src/manifest.json:580 meeting-publication tab -> AgendaPublicationTab -> PublicationActionsTab.vue:357 POST /api/publications

Matrix note, verbatim:

> Meeting agendas (titles and order) and minutes are published to a per-body OpenCatalogi catalog set in admin PublicationSettings. The papers themselves are not published: the agenda payload carries no documents, and the public website is OpenCatalogi's, not decidiq's.

Competitor cells rated `yes`, verbatim:

- notubiz: https://amsterdam.raadsinformatie.nl/vergadering/1528626 is a public meeting page with agenda, documents and recording; https://www.notubiz.nl/onze-diensten/vergadermanagement states information is published on the Politiek Portaal
- ibabs: https://support.ibabs.com/docs/introductie-5.md states the Publieksportaal publishes public agendas and list items at sitenaam.bestuurlijkeinformatie.nl; https://utrecht.bestuurlijkeinformatie.nl shows meetings and papers
- openslides: source read and driven at 4.3.4 on 2026-09-26 (lab-decidiq-openslides): with organization and meeting public access switched on, the login page's 'Public access' button opened the meeting as a guest and showed motion 1 and its poll status without an account; the Public group starts with no permissions, so staff must grant them first (group_t id 5 was empty). Source: openslides-client/client/src/app/site/pages/organization/pages/settings/modules/settings-detail/components/organization-settings/organization-settings.component.html:162 organization public access plus meeting.yml:82 enable_anonymous let visitors enter as guests (login-mask.component.html:43 guestLogin) and see the agenda, motions and files their anonymous group may see (meeting.yml:1167 anonymous_group_id). Public, but inside the OpenSlides app, not a separate website.

### pub-09, let citizens search public council documents with filters

Own rating `partial`, built.state `building`, owner `ConductionNL/opencatalogi`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/OpenCatalogiPublisher.php:72 creates an opencatalogi publication (title, summary, catalog, reference); src/views/settings/PublicationSettings.vue:25 'Anonymous read access is served exclusively through OpenCatalogi / OpenRegister'; lib/Controller/OriController.php:237 buildFilters() offers no citizen filters

Matrix note, verbatim:

> Search and filters are OpenCatalogi's and were not checked here. decidiq only feeds it title-level publications of decisions, agendas and minutes, without the documents.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/zoeken-in-politiek-portaal states public search with filters on object, period, module, vergadercategorie, document type, party, beleidsveld and rubriek
- ibabs: https://support.ibabs.com/docs/introductie-5.md states the Publieksportaal search with advanced filters on date, agendatype and type of overview

## Why

pub-01 is rated yes by three competitors and pub-09 by two.

## What is built today

- PublicationService and OpenCatalogiPublisher publish title, summary and reference; buildAgendaPayload() strips confidential items and all documents.

## What changes

1. buildAgendaPayload() includes the non-confidential files of each public agenda item as publication attachments.
2. Each publication carries body, meeting date, document type and theme, the fields OpenCatalogi filters on.
3. Confidential items and documents stay out.

## Out of scope

- The public search page itself (OpenCatalogi).
- Woo categories (woo-diwoo-publication).
