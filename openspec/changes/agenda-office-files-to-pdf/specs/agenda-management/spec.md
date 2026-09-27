# agenda-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-office-files-to-pdf](../../) (this delta)

## Purpose

Converts Office papers to PDF when they are added to a meeting or an agenda item, through filinq's conversion service. Closes decidiq matrix row age-17.

**Standards**: PDF (ISO 32000), Schema.org `DigitalDocument` (`encodingFormat`).

## ADDED Requirements

### Requirement: REQ-OPDF-001 An Office paper added to a meeting or agenda item is converted to PDF

When a Word, Excel, PowerPoint or OpenDocument file is added to the files of a decidiq agenda item or meeting, the app SHALL queue a background conversion to PDF through filinq's `PdfConversionService`. The PDF SHALL be written beside the original, and the object SHALL record the source, the PDF and the backend that converted it in `paperRenditions`. The upload itself SHALL never wait for the conversion.

#### Scenario: A clerk adds a Word paper
- GIVEN the agenda item Begroting 2027 of the council meeting
- WHEN the griffier adds "Programmabegroting 2027.docx" to its documents
- THEN within a few minutes "Programmabegroting 2027.pdf" appears beside it and the item records the pair

#### Scenario: A file outside decidiq is left alone
- GIVEN a user saves a spreadsheet in his own Files folder
- WHEN the file is created
- THEN decidiq queues no conversion

### Requirement: REQ-OPDF-002 A failed or impossible conversion is visible and the original stays

When filinq cannot convert a file, the app SHALL keep the original, record the failure with the reason filinq gives, and show it to the secretariat with a Try again action. When filinq is not installed the app SHALL convert nothing and SHALL not report an error on the item.

#### Scenario: A spreadsheet cannot be converted
- GIVEN filinq reports that no backend could convert "Bijlage investeringen.xlsx"
- WHEN the griffier opens the agenda item
- THEN she sees the spreadsheet with "Not converted: no backend could convert this file" and a Try again action

### Requirement: REQ-OPDF-003 Members read and download the PDF

Where a paper has a PDF rendition, the agenda item page SHALL show the PDF as the paper, and the meeting package SHALL bundle the PDF instead of the original. The secretariat SHALL still be offered the original.

#### Scenario: A member opens the paper
- GIVEN "Programmabegroting 2027.docx" was converted
- WHEN council member Pieter opens the agenda item
- THEN he sees one entry, Programmabegroting 2027, that opens the PDF

#### Scenario: The package carries the PDF
- GIVEN the same item
- WHEN the griffier assembles the meeting package
- THEN the package folder holds the PDF and not the Word file

### Requirement: REQ-OPDF-004 An administrator can switch automatic conversion off

The admin settings page SHALL offer a switch for automatic conversion, on by default, and SHALL say when filinq is not installed.

#### Scenario: Conversion is switched off
- GIVEN an administrator turns automatic conversion off
- WHEN a clerk adds a Word file to an agenda item
- THEN no PDF is made and the Word file is shown as the paper
