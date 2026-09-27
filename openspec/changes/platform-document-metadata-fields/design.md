# Design: platform-document-metadata-fields

Kind: code. One new configurable type, one schema patch, one settings page,
one widget with a dialog, one save guard. Read against decidiq `development`
at 4d7430ff.

## What exists today

- **Agenda item type fields.** `lib/Settings/register.d/70-configurable-types.json:215`
  `AgendaItemType.fields`: an array of `{key, label, fieldType, required,
  enumValues}` with `fieldType` one of `string`, `text`, `number`, `boolean`,
  `date`, `enum`, `reference`. Values land on `AgendaItem.typeFields`.
  `src/components/AgendaItemTypeFields.vue` renders them, with the logic in
  `src/utils/agendaItemTypeFields.js` and tests in
  `tests/vitest/agendaItemTypeFields.spec.js`. The settings page is
  `AgendaItemTypes` in `src/manifest.d/configurable-types.json:167`.
- **Document metadata.** `lib/Settings/decidesk_register.json:3457`
  `DigitalDocument` (`schema:DigitalDocument`): `name`, `documentType` (free
  text), `description`, `encodingFormat`, `contentSize`. Other schemas
  reference it (for example `lib/Settings/register.d/79-one-consultation-schema.json:327`).
  Nothing links it to a Nextcloud file.
- **Files.** A meeting's files live in its Nextcloud folder
  (`lib/Listener/MeetingFolderListener.php`) and show in the `meeting-files`
  widget, openregister's `files` integration (`src/manifest.json:578`). The
  agenda item has the same leaf. openregister lists an object's files at
  `GET /api/objects/{register}/{schema}/{id}/files` (openregister
  `appinfo/routes.php:1458`).
- **Pages.** `MeetingDetail` (`src/manifest.json:561`, edited directly per its
  note) and `AgendaItemDetail` (`src/manifest.json:821`, `agenda-files` at `:835`,
  `agenda-type-fields` at `:836`).

## D1. A document type is a configurable type

`DocumentType` (slug `document-type`) in a new fragment
`lib/Settings/register.d/92-document-metadata-fields.json`, attached to the
register's `schemas` list:

| Property | Type | Purpose |
|---|---|---|
| `name` | string | what a clerk picks, "Raadsvoorstel" |
| `description` | string | what documents of this kind are |
| `fields` | array | the same item shape as `AgendaItemType.fields` |
| `appliesTo` | array of enum | `meeting`, `agenda-item` |
| `active` | boolean | offered when picking a type |

Why a copy of the field shape rather than a shared `$ref`: the register
dialect has no shared definitions across schemas today, and the two may drift
apart on purpose later (a document field may want a file-specific type).

## D2. A document record points at its file

`DigitalDocument` gains, in the same fragment:

- `fileId` (integer): the Nextcloud file id, unique per record;
- `meeting` (uuid, `$ref: meeting`) or `agendaItem` (uuid, `$ref: agenda-item`):
  where the file is attached;
- `type` (uuid, `$ref: document-type`);
- `typeFields` (object): the values.

`documentType` stays for the schemas that already use it. A record is created
the first time a clerk saves details for a file, never in bulk.

## D3. The widget and the dialog

`DocumentMetadataTab.vue` (custom widget), placed on `MeetingDetail` next to
`meeting-files` and on `AgendaItemDetail` next to `agenda-files`:

1. reads the object's files from openregister's files API;
2. reads the `digital-document` records with that `meeting` or `agendaItem`;
3. shows each file with its type name and the first two field values, and a
   "Details" button.

`src/modals/DocumentMetadataModal.vue` (a modal in its own file, per the
modal isolation gate) holds an `NcSelect` for the type (only types whose
`appliesTo` fits) and the fields. The field renderer is
`AgendaItemTypeFields.vue` generalised into `TypeFieldsForm.vue`, taking the
field list and the values as props; the agenda item tab keeps its behaviour and
its vitest.

## D4. Required fields are enforced on save

`DocumentTypeFieldsGuardListener` on openregister's `ObjectCreatingEvent` and
`ObjectUpdatingEvent` for `digital-document`: when the record has a `type` that
declares a required field, an empty value refuses the save, naming the field.
The form enforces it too; the guard is what makes it hold for the API.

## Declarative or imperative

- `DocumentType`, the `DigitalDocument` links and their RBAC: declared.
- The settings pages: declared in `src/manifest.d/configurable-types.json`.
- The widget: a custom component, because it joins files from openregister's
  files API with records by `fileId`, which no declared widget does.
- The required rule: an imperative guard, because the rule lives on another
  object (the type), which a schema `required` cannot express.

## Seed data

In `lib/Settings/profiles/municipality.json`:

1. Document type "Raadsvoorstel", `appliesTo: [agenda-item]`, fields
   `zaaknummer` (string, required), `portefeuillehouder` (string), `status`
   (enum `concept`, `definitief`).
2. Document type "Presentatie", `appliesTo: [meeting]`, field `spreker`
   (string).
3. One `DigitalDocument` for the file "Raadsvoorstel omgevingsvisie.pdf" on the
   agenda item "Vaststelling omgevingsvisie", type "Raadsvoorstel",
   `typeFields: {zaaknummer: "Z-2026-00412", status: "definitief"}`.

## Risks

- Orphans: a deleted file leaves its record. openregister's file events do not
  include delete in its File Action Events requirement (rename, copy, move,
  lock, unlock, version restore), so a clean-up needs either that event or a
  sweep; neither is in this change.
- A moved file keeps its id in Nextcloud, so a record follows a move within
  the same storage.
