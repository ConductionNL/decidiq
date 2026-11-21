---
kind: code
depends_on: []
---

# Proposal: followup-public-progress

## Summary

Motions are exposed on the public ORI API, but commitments are not published at all and there is no public progress view. This change publishes commitments and adopted motions with their progress through the ORI API and the publication machinery.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### fol-06, show the public how commitments and motions are progressing

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> lib/Controller/OriController.php:180-183 #[PublicPage] index() serves /api/ori/v1/{resource} (routes.php:270) incl. motions => decision (:67); decisions/minutes/agenda publish to OpenCatalogi via PublicationActionsTab (/api/publications). No commitment resource in OriController or ApiController RESOURCE_MAP, and PublicationService.php:97 sourceType is decision|agenda|minutes only.

Matrix note, verbatim:

> Motions are exposed on a public ORI API and decisions can be published, but there is no public page and commitments are not published at all.

Competitor cells rated `yes`, verbatim:

- notubiz: https://amsterdam.raadsinformatie.nl/modules/6/moties_en_amendementen/view publicly shows result and afdoening dates and documents of motions; https://www.notubiz.nl/onze-diensten/gekoppelde-modules LTA gives insight in commitments and actions from motions
- ibabs: https://utrecht.bestuurlijkeinformatie.nl Publieksportaal lists Toezeggingen and Moties overviews; https://support.ibabs.com/docs/stand-van-zaken-veld.md adds dated progress entries and https://support.ibabs.com/docs/workflow-op-overzichten.md allows shielding internal fields
- go-raadsinformatie: https://lta.nu/woerden is a public list of motions and commitments for Woerden with planning and details per item

## Why

Rated yes by three competitors.

## What is built today

- OriController public motions resource; decision, agenda and minutes publication to OpenCatalogi.

## What changes

1. OriController and the ApiController RESOURCE_MAP gain a commitments resource with status, deadline and progress entries, public fields only.
2. PublicationService accepts commitment as a sourceType.
3. The motions resource includes execution status and progress entries.

## Out of scope

- A decidiq-hosted public web page (the public site is OpenCatalogi or portaliq).
