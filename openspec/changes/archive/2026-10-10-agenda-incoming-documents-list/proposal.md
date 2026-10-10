---
kind: code
depends_on: []
---

# Proposal: agenda-incoming-documents-list

## Summary

Incoming letters are agenda items of an incoming document type since the documents-as-agenda-items change, but the meeting's routed documents widget still reads the retired schemas and shows nothing, and there is no list of incoming documents waiting for a meeting. This change points the widget at agenda items of the incoming document types and adds an Incoming documents list with a Put on agenda action.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### age-13, put incoming letters and documents on the agenda to be dealt with

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> Incoming documents are AgendaItems of a configurable type since register.d/85-documents-as-agenda-items.json (IngekomenStuk superseded, x-openregister active:false); seeded type 'Ingekomen stuk' in lib/Settings/profiles/municipality.json:771; migration lib/Migration/MigrateDocumentsToAgendaItems.php:78-81; added through the agenda form (src/components/tabs/MeetingAgendaTab.vue, Type column :229); src/components/tabs/MeetingRoutedDocumentsTab.vue:168-174 still reads the retired raadsinformatiebrief and ingekomen-stuk schemas

Matrix note, verbatim:

> An incoming letter can be put on an agenda as an agenda item of an 'incoming document' type, with its own fields. There is no intake register of incoming mail to route from, and the meeting's 'routed documents' widget reads the retired schemas, so it shows nothing new.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.notubiz.nl/onze-diensten/gekoppelde-modules lists the Ingekomen stukken module; https://amsterdam.raadsinformatie.nl/vergadering/1528626 has agenda item Mededeling van de ingekomen stukken with raadsadressen as items
- ibabs: https://support.ibabs.com/docs/inrichting-7.md lists the standard overview Ingekomen stukken and states list items can be added from and linked to an agenda item

## Why

age-13 is rated yes by notubiz and ibabs (two competitors).

## What is built today

- Incoming documents as AgendaItems of a configurable type (register.d/85), seeded type Ingekomen stuk.
- Adding one through the agenda form.

## What changes

1. MeetingRoutedDocumentsTab reads the meeting's agenda items whose type is an incoming document type instead of the retired raadsinformatiebrief and ingekomen-stuk schemas.
2. An Incoming documents index page lists agenda items of those types that have no meeting yet, with a Put on agenda action that picks a meeting.

## Out of scope

- An intake register of all incoming mail (that is a case system).
