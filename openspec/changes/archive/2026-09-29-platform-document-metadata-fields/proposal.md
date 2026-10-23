---
kind: code
depends_on: []
---

# Proposal: platform-document-metadata-fields

## Summary

An administrator can already give each kind of agenda item extra fields, and
letters and incoming documents carry them because they are agenda items. The
files attached to a meeting or an agenda item carry nothing beyond their name.
This change lets an administrator declare document types with their own extra
fields, the same way agenda item types work, and lets a clerk fill them in for
each file on the meeting page and the agenda item page. No supplier is needed
to add a field.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### plt-20, add extra metadata fields to documents without the supplier's help

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`.

Demand row: origin `tender`, originUrl
https://www.tenderned.nl/aankondigingen/overzicht/384605 (TenderNed 384605).

No competitor is rated yes. The cells: notubiz `unknown`, ibabs `no` (custom
fields need support@ibabs.eu), go-raadsinformatie `unknown`, diligent-boards
`unknown`, openslides `partial` (extra fields on motions, fixed fields on
documents).

The matrix note, verbatim: "An administrator can declare extra fields per
agenda item type, and they appear as inputs on the agenda item form and its
detail page, so letters, incoming documents and questions carry them. Files
attached to meetings carry no extra metadata."

The missing half, as the lane decided: extra metadata on the files attached to
meetings and agenda items.

## Why

A tender asked for it. A griffie records things about a document that are not
in its name: the kind of stuk, the author department, the zaaknummer, whether
it is a concept or final, the date it was sent. Today that knowledge lives in
file names or in someone's head. The agenda item type fields already prove the
pattern works without supplier help; this change applies it to files.

## What changes

1. A `DocumentType` schema: a name, the fields a document of this kind carries
   (the same field definition shape as `AgendaItemType.fields`), and whether it
   is offered on meeting files, agenda item files or both.
2. `DigitalDocument`, the existing document metadata schema, gains a link to
   the file (`fileId`), to its meeting or agenda item, to its `type`, and a
   `typeFields` object for the values.
3. A "Document types" page under the settings gear, next to agenda item types.
4. A "Document details" widget on the meeting page and the agenda item page
   that lists the object's files with their type and opens a details dialog to
   fill in the fields.
5. A required field is enforced on save, not only in the form.

## Out of scope

- Filtering or searching files by the values of extra fields.
- Changing openregister's own file metadata (labels, description, category).
  decidiq's fields sit beside it in a `DigitalDocument`.
- Fields on files outside meetings and agenda items.

## Risks

- **A renamed field key orphans its values**, exactly as with agenda item types.
  The field definition text says so, copied from `AgendaItemType.fields`.
- **A deleted file leaves its metadata record.** The widget lists files, not
  records, so an orphan never shows; a clean-up is left for later and named in
  the design.
