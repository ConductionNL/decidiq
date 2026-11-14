# document-metadata-fields Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [platform-document-metadata-fields](../../) (this delta)
- follows the pattern of `AgendaItemType.fields` (#1393, configurable-types-domain-model)

## Purpose

Lets an administrator declare document types with extra fields and lets a
clerk fill them in for each file attached to a meeting or an agenda item.
Closes the missing half of matrix row plt-20.

**Standards**: Schema.org `DigitalDocument`, `additionalProperty`.

## ADDED Requirements

### Requirement: REQ-DMF-001 An administrator declares document types and their fields

decidiq SHALL hold `DocumentType` records with a name, a description, a list
of field definitions of the same shape as `AgendaItemType.fields` (`key`,
`label`, `fieldType`, `required`, `enumValues`), the places the type applies to
(`meeting`, `agenda-item`) and an `active` flag. An administrator SHALL manage
them on a "Document types" page under the settings gear.

#### Scenario: The administrator adds a field without the supplier

- GIVEN the document type "Raadsvoorstel" with the field "Zaaknummer"
- WHEN an administrator opens it under Document types, adds a field "Status" of type pick from a list with "concept" and "definitief", and saves
- THEN the type carries both fields and the next details dialog for a raadsvoorstel shows both

### Requirement: REQ-DMF-002 A document record links a file to its type and values

`DigitalDocument` SHALL carry `fileId` (the Nextcloud file id, unique across
records), `meeting` or `agendaItem`, `type` referencing a `DocumentType`, and
`typeFields` with the values. A record SHALL be created only when a clerk first
saves details for a file.

#### Scenario: Two records for one file are refused

- GIVEN a `digital-document` with `fileId` 4711
- WHEN a second record with `fileId` 4711 is created through the OpenRegister API
- THEN the save is refused
- @e2e exclude uniqueness contract; covered by PHPUnit testASecondRecordForOneFileIsRefused on DocumentTypeFieldsGuardListener

### Requirement: REQ-DMF-003 The clerk fills in document details on the meeting and agenda item pages

The meeting page and the agenda item page SHALL show a "Document details"
widget that lists every file attached to the object with its document type and
the first two field values, and a "Details" button opening a dialog to pick a
type (only types that apply to that place) and fill in its fields.

#### Scenario: The clerk records the zaaknummer of a raadsvoorstel

- GIVEN the agenda item "Vaststelling omgevingsvisie" with the file "Raadsvoorstel omgevingsvisie.pdf" and no details
- WHEN the griffier presses "Details" on that file in the Document details widget, picks "Raadsvoorstel", types "Z-2026-00412" as Zaaknummer and saves
- THEN the widget shows "Raadsvoorstel" and "Z-2026-00412" beside the file

#### Scenario: A type for meetings is not offered on an agenda item

- GIVEN the type "Presentatie" that applies to meetings only
- WHEN the griffier opens the details dialog for a file on an agenda item
- THEN "Presentatie" is not among the types offered

### Requirement: REQ-DMF-004 A required field is enforced on save

Saving a `digital-document` whose type declares a required field that is empty
SHALL be refused with a message naming the field, whether the save comes from
the dialog or from the API.

#### Scenario: A required zaaknummer left empty

- GIVEN the type "Raadsvoorstel" with "Zaaknummer" required
- WHEN a record of that type is saved through the API without a zaaknummer
- THEN the save is refused with "The details were not saved: fill in Zaaknummer."
- @e2e exclude save guard; covered by PHPUnit on DocumentTypeFieldsGuardListener with the real ObjectCreatingEvent
