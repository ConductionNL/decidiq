# motion-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [motions-export-with-attachments](../../) (this delta)

## Purpose

Exports a selection of motions or decisions, or every one matching the list's filter, with their attachments in one go. Closes decidiq matrix rows mot-16 and pub-17.

**Standards**: PDF (ISO 32000) with bookmarks, ZIP.

## ADDED Requirements

### Requirement: REQ-MXP-001 Motions export as one PDF with their attachments

The Motions and Decisions lists SHALL offer a bulk action Export with attachments. For the PDF format the app SHALL produce one PDF that holds, per decision in list order, a page with its text followed by its attachments, with one bookmark per decision, through filinq's merge service. The file SHALL land in the requester's Files under "Decidiq exports". When filinq is not installed the PDF format SHALL be unavailable.

#### Scenario: The griffier exports three motions
- GIVEN three adopted motions, each with a PDF attachment
- WHEN the griffier selects them on the Motions list and chooses Export with attachments, one PDF
- THEN "Motions 2026-10-20.pdf" appears in her Decidiq exports folder with each motion's text followed by its attachment, bookmarked per motion

#### Scenario: One attachment is out of reach

@e2e exclude needs a file the signed-in test user may not read and filinq installed; covered by PHPUnit ExportBundleServiceTest::testAnUnreadableAttachmentRefusesTheWholePdf
- GIVEN one of the selected motions has an attachment the griffier may not read
- WHEN she asks for the PDF
- THEN the export is refused, naming that file, and no partial PDF is written

### Requirement: REQ-MXP-002 A selection or a filtered set exports as a ZIP of its documents

The export SHALL also offer a ZIP with one folder per decision holding its text and its attachments as they are. The scope SHALL be either the selected rows or all rows matching the list's current filter, not only the visible page, up to 500 decisions.

#### Scenario: All motions of this year
- GIVEN the Motions list filtered on submitted since 1 January 2026 shows 120 motions over five pages
- WHEN the griffier chooses all rows matching the filter and ZIP
- THEN the ZIP holds 120 folders

#### Scenario: Too many at once

@e2e exclude needs 501 seeded decisions; covered by PHPUnit ExportBundleServiceTest::testMoreThanFiveHundredDecisionsIsRefused
- GIVEN a filter that matches 800 decisions
- WHEN the griffier asks for an export
- THEN she is asked to narrow the filter and nothing is built

### Requirement: REQ-MXP-003 A large export runs in the background and says when it is ready

When filinq's merge service says a PDF export should be queued, the app SHALL answer at once that the export is queued and SHALL notify the requester with a link when the file is ready.

#### Scenario: The yearly motions book

@e2e exclude runs through filinq's background merge and a cron run; covered by PHPUnit ExportBundleServiceTest::testALargePdfIsQueuedAndANoticeFollows and ExportBundleNoticeJobTest
- GIVEN 300 motions with attachments
- WHEN the griffier asks for one PDF
- THEN the dialog says the export is being prepared, and later her notification bell links "Motions 2026-10-20.pdf"
