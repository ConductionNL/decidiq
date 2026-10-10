---
kind: code
depends_on: []
---

# Proposal: platform-full-data-export

## Summary

decidiq has a read API and a regulator export of resolutions and minutes, but no way to take all data along when leaving. This change adds an administrator export of every decidiq register object with its files in one archive, in a documented open format.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pub-15, take all your data with you when you leave the supplier

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> appinfo/routes.php:263-264 /api/v1/{resource} GET list/show; lib/Service/RegulatorExportService.php (resolutions and minutes to PDF/CSV only); all records are OpenRegister objects in the customer's own Nextcloud

Matrix note, verbatim:

> Data sits in the customer's own OpenRegister, so it stays with them; decidiq itself has no full export, only a read API and a regulator export of resolutions and minutes. OpenRegister's export was not checked here.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/data-act-addendum.md (updated 2026-08-31) states customers may request transfer of their data to on-premises infrastructure or another provider at contract end under the Data Act Addendum
- openslides: source read at 4.3.4, not driven: openslides-backend/openslides_backend/presenter/export_meeting.py exports a complete meeting as JSON (called from committee-meeting-preview.component.html:170) that meeting.import reads back, lists export to CSV, PDF and XLSX (motions/services/export), and the whole system is MIT licensed source you can run yourself (LICENSE:1). Data can be taken along.

## Why

Rated yes by two competitors.

## What is built today

- Read API /api/v1/{resource}; RegulatorExportService (resolutions and minutes to PDF and CSV).

## What changes

1. An admin settings action Export all data that builds a ZIP with one JSON file per schema (all objects) and the files of each meeting, agenda item and decision folder.
2. A manifest.json in the ZIP describes the schemas and relations.
3. Runs as a background job and notifies the admin with the download link.

## Out of scope

- Importing an export into another system.
