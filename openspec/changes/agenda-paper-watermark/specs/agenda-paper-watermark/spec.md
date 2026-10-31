# agenda-paper-watermark Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-paper-watermark](../../) (this change)

## Purpose

Stamps meeting papers with the reader's name and the date when a clerk wants it, and by default for closed meetings and confidential agenda items. Closes decidiq matrix row age-19, re-specifying what archived `2026-06-12-board-meeting-resolutions` had on the retired `BoardMaterial` schema.

**Standards**: PDF (ISO 32000), Gemeentewet art. 87-89 (geheimhouding).

## ADDED Requirements

### Requirement: REQ-WMK-001 A meeting says whether its papers are watermarked

A meeting SHALL carry `watermarkPapers` with the values `automatic`, `on` and `off`, and an optional `watermarkText`. With `automatic`, papers SHALL be stamped when the meeting is not public or when an active confidentiality restriction targets the agenda item or the paper.

#### Scenario: A closed session stamps by default
- GIVEN the griffier creates a meeting that is not public and leaves watermarking on automatic
- WHEN a member opens one of its papers
- THEN the paper is stamped

#### Scenario: A clerk switches it off
- GIVEN a public meeting with a restricted agenda item and watermarking set to off
- WHEN a member opens the restricted item's paper
- THEN the paper is not stamped

### Requirement: REQ-WMK-002 A paper opened through decidiq carries the reader's name and the date

When a paper must be stamped, decidiq SHALL serve it only as a PDF with the reader's display name, the date and time, and the meeting's watermark text on every page. It SHALL check the reader may read the agenda item or meeting first. When the stamping service is not available it SHALL refuse with a reason and SHALL NOT serve the unstamped paper.

#### Scenario: A member reads a confidential paper
- GIVEN council member Aisha may read the agenda item Grondtransactie Noord, which is under a confidentiality restriction
- WHEN she opens its paper on 3 October at 20:15
- THEN every page shows "Aisha Bakker, 2026-10-03 20:15, Vertrouwelijk"

#### Scenario: No stamping service
- GIVEN filinq is not installed
- WHEN Aisha opens the same paper
- THEN she sees "This paper can only be shown with a watermark, and the watermark service is not installed" and no file is sent

### Requirement: REQ-WMK-003 The meeting package is stamped for the person who takes it

When decidiq assembles the meeting package for a user and a paper must be stamped, each such paper in the package SHALL be stamped for that user.

#### Scenario: A member takes the package of a closed session
- GIVEN the closed session Besloten vergadering grondzaken with three papers
- WHEN council member Jan assembles the meeting package
- THEN the three papers in his package carry his name and the date
