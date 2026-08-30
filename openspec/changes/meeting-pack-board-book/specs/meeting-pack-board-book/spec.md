# meeting-pack-board-book Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- meeting-pack-board-book

## Purpose

Provides the compiled meeting pack (vergaderbundel / board book): a single versioned PDF per meeting containing a cover page, table of contents, the agenda in order, and all agenda-item attachments merged with continuous pagination and per-item bookmarks. Complements the existing folder-based package (`agenda-management` "Agenda Document Package") and the per-item attachments of `agenda-publication` REQ-PUB-003. Document rendering is delegated to Docudesk with an honest fallback, following the `resolution-minutes` "Minutes Document Generation" pattern. A `MeetingPack` is a `schema:DigitalDocument` (a `schema:CreativeWork` with `version`); for public meetings it maps to the ORI `Verslag`-adjacent document attachment of the meeting (`ori:agenda` documents), though ORI publication itself stays in the `public-publication` capability.

## ADDED Requirements

### Requirement: REQ-001 One-click compilation of a complete meeting pack

The system SHALL let the secretary or chair compile a meeting pack for a Meeting in one action. The compiled pack SHALL be a single PDF containing, in order: a cover page (governance body name, meeting title, `scheduledDate` in Dutch format, location), a table of contents listing each AgendaItem with its starting page number, and one section per AgendaItem in `orderNumber` order containing the item's title, `itemType`, `description`, followed by the item's attachments (stored via the OpenRegister `FileService`, per `agenda-publication` REQ-PUB-003) merged into the document. Pagination SHALL be continuous across the whole pack and each AgendaItem section SHALL carry a PDF bookmark (outline entry) named `"<orderNumber>. <title>"`. Rendering and merging SHALL be delegated to Docudesk's `PdfService` (resolved lazily via the DI container, same pattern as `MinutesDocumentService`).

#### Scenario: Secretary compiles the pack for a published agenda

- GIVEN a Meeting "Raadsvergadering 15 januari 2025" with a published agenda of 5 AgendaItems, three of which have PDF attachments
- WHEN the secretary clicks "Vergaderbundel samenstellen" on the meeting detail page
- THEN a single PDF is produced with a cover page, a table of contents with page numbers, the 5 items in `orderNumber` order, and the attachments merged in place
- AND the PDF has continuous page numbers from cover to last attachment page
- AND the PDF outline contains one bookmark per agenda item

#### Scenario: Compilation without Docudesk falls back honestly

- GIVEN an instance where the Docudesk app is not installed
- WHEN the secretary triggers pack compilation
- THEN the system MUST NOT pretend a PDF was produced; it SHALL assemble the existing folder-based package (`MeetingPackageService`) with its markdown table of contents instead
- AND the response SHALL state that Docudesk is unavailable and a folder package was produced instead of a compiled PDF

#### Scenario: Compilation blocked for an empty agenda

- GIVEN a Meeting with no AgendaItems
- WHEN the secretary triggers pack compilation
- THEN a validation error is shown ("Een vergaderbundel vereist minimaal één agendapunt") and no pack is created

### Requirement: REQ-002 Defensive attachment merging with placeholder pages

Attachment merging SHALL be defensive, following the skip-report contract of `MeetingPackageService`. An attachment that cannot be merged (non-PDF format such as Word/Excel, an encrypted or unparsable PDF, or an attachment exceeding the configured size guard) MUST NOT fail the compilation; instead the pack SHALL contain a placeholder page in that attachment's position naming the file and stating that it is available separately per agenda item, and the compilation result SHALL list the skipped attachments.

#### Scenario: Unparsable PDF attachment is skipped with a placeholder

- GIVEN an AgendaItem whose attachment is a PDF the merge engine cannot import
- WHEN the pack is compiled
- THEN the compilation completes successfully
- AND the pack contains a placeholder page at that attachment's position naming the file
- AND the compilation result lists the file under `skipped`

#### Scenario: Word attachment gets a placeholder page

- GIVEN an AgendaItem with a `.docx` attachment
- WHEN the pack is compiled
- THEN the pack contains a placeholder page naming the document and referring to the agenda item's Files tab
- AND the document itself remains downloadable via the item's attachments

### Requirement: REQ-003 MeetingPack version tracking

Each compilation SHALL create a new `MeetingPack` OpenRegister object (`schema:DigitalDocument`) carrying at minimum: `meeting` (relation), `version` (sequential integer per meeting, starting at 1), `changeNote` (free text; MUST be required for versions > 1 and MAY be empty for version 1), `generatedAt`, `generatedBy`, `fingerprint` (content fingerprint of the compiled agenda state), `filePath` (pack file in the meeting's Files folder), `pageCount`, and `skipped` (list of skipped attachments). Prior `MeetingPack` versions and their files MUST be kept; compilation SHALL never overwrite an earlier version's file.

#### Scenario: Recompilation creates version 2 with a change note

- GIVEN a Meeting with an existing MeetingPack version 1
- WHEN the secretary adds an attachment to an agenda item and recompiles with change note "Bijlage begroting toegevoegd aan punt 3"
- THEN a MeetingPack object with `version: 2` and that `changeNote` is created
- AND version 1 and its PDF file remain available
- AND the meeting detail page lists both versions with their change notes, newest first

#### Scenario: Change note required on recompilation

- GIVEN a Meeting with an existing MeetingPack version 1
- WHEN the secretary triggers recompilation without entering a change note
- THEN the compilation is refused with a validation message asking for a change note

### Requirement: REQ-004 Pack outdated indicator

The system SHALL detect when the latest MeetingPack no longer matches the current agenda. The fingerprint stored on the pack SHALL cover the ordered set of AgendaItem ids, their `orderNumber` and `title`, and the identity/etag of each item attachment. The meeting detail page SHALL show a "Vergaderbundel verouderd" indicator when the current fingerprint differs from the latest pack's fingerprint, and no indicator when they match.

#### Scenario: Agenda change after compilation marks the pack outdated

- GIVEN a Meeting whose latest MeetingPack was compiled from the current agenda
- WHEN the secretary reorders two agenda items
- THEN the meeting detail page shows the "Vergaderbundel verouderd" indicator next to the pack section

#### Scenario: Fresh pack shows no outdated indicator

- GIVEN a Meeting whose agenda and attachments have not changed since the latest compilation
- WHEN a participant opens the meeting detail page
- THEN the pack section shows the latest version without an outdated indicator

### Requirement: REQ-005 Access control and confidential item exclusion

The `AgendaItem` schema SHALL gain an optional `confidentiality` property (enum: `public`, `internal`, `confidential`; default `internal`). A compiled pack MUST exclude the `description` and attachments of every AgendaItem with `confidentiality: confidential`, replacing the section body with a placeholder page stating "Behandeling achter gesloten deuren — stukken separaat beschikbaar" while keeping the item's title line in the table of contents. This exclusion applies to every compiled pack — the system SHALL NOT produce per-user pack variants. MeetingPack objects and their files SHALL inherit the meeting's access (OpenRegister RBAC): only users who can read the Meeting can read its packs.

#### Scenario: Confidential item excluded from the compiled pack

- GIVEN a Meeting with 4 AgendaItems of which item 3 has `confidentiality: confidential` and two attachments
- WHEN the pack is compiled
- THEN the pack's table of contents lists all 4 items
- AND the section for item 3 contains only the closed-doors placeholder page, not the description or attachments
- AND item 3's attachments remain accessible only via the agenda item's own RBAC-guarded Files tab

#### Scenario: User without meeting access cannot read the pack

- GIVEN a Meeting readable only by its GovernanceBody participants
- WHEN a Nextcloud user who is not a participant requests the MeetingPack object or its file
- THEN access is denied by OpenRegister RBAC (object not found / file not accessible)

### Requirement: REQ-006 Offline delivery into attendees' own Files

After successful compilation the system SHALL deliver the pack PDF to each active attendee (Participant of the meeting with `leftAt` null and a resolvable Nextcloud account) as a read-only share into their personal Files space, so that native Nextcloud desktop and mobile clients sync the pack for offline reading. Delivery failures for individual attendees MUST NOT fail the compilation and SHALL be reported per attendee. When a newer version is compiled, the newly delivered file SHALL be distinguishable by version in its file name (e.g. `Vergaderbundel Raadsvergadering 2025-01-15 v2.pdf`).

#### Scenario: Attendee receives the pack in their own Files

- GIVEN a compiled pack for a Meeting with attendee "j.jansen"
- WHEN delivery runs
- THEN "j.jansen" finds the pack PDF read-only in their own Files
- AND marking it for offline availability in the Nextcloud mobile client syncs it locally

#### Scenario: One failing delivery does not block the others

- GIVEN a Meeting with 5 attendees of which one has no resolvable Nextcloud account
- WHEN delivery runs
- THEN the 4 resolvable attendees receive the pack
- AND the compilation result reports the failed delivery for the fifth attendee

### Requirement: REQ-007 Pack availability notification (declarative)

The `MeetingPack` schema SHALL declare an `x-openregister-notifications` entry (canonical dialect, ADR-031) with trigger `created`, channel `nc-notification`, recipients `object-acl` with `read` permission, and a bilingual subject (e.g. nl: "Vergaderbundel beschikbaar: {{title}}", en: "Meeting pack available: {{title}}"). The app SHALL NOT dispatch pack-availability notifications imperatively.

#### Scenario: Attendees are notified when a pack is created

- GIVEN a Meeting whose attendees have read access to its MeetingPack objects
- WHEN a MeetingPack object is created by compilation
- THEN each user with read access receives a Nextcloud notification naming the meeting and linking to the meeting detail page

### Requirement: REQ-008 Pack section on the meeting detail page

The meeting detail page (`meeting-detail-view`) SHALL show a "Vergaderbundel" `CnDetailCard` section containing: a compile/recompile action (secretary or chair only, via the section's `#header-actions` slot), the version list (version, `generatedAt`, `generatedBy`, `changeNote`, `pageCount`, download link per version), the outdated indicator of REQ-004, and the skip report of the latest compilation when non-empty. For users who may not compile, the section is read-only.

#### Scenario: Participant downloads the latest pack from the detail page

- GIVEN a Meeting with two MeetingPack versions
- WHEN a participant opens the meeting detail page and clicks the download link of version 2
- THEN the version 2 PDF downloads
- AND no compile button is shown to the participant

#### Scenario: Secretary sees compile action and skip report

- GIVEN a secretary viewing a meeting whose latest compilation skipped one attachment
- WHEN the detail page renders
- THEN the Vergaderbundel section shows the "Vergaderbundel samenstellen" action and the skipped attachment listed with its reason

## Non-Functional Requirements

- **Performance:** compiling a pack of 20 agenda items with 50 MB of PDF attachments completes within 60 seconds; compilation runs server-side and never blocks the browser beyond the initial request/poll.
- **Accessibility:** the generated PDF has a document title, language metadata (`nl`), and a bookmark outline; the pack section UI meets WCAG 2.1 AA (labels on all actions, status conveyed by text + color).
- **Internationalization:** Dutch and English MUST be supported for all UI strings and notification subjects (ADR-005); generated pack headings use the meeting's language (Dutch default).

## Acceptance Criteria

- [ ] One click produces a single PDF with cover, TOC with page numbers, ordered agenda sections, merged attachments, continuous pagination, per-item bookmarks
- [ ] Docudesk-absent instances get the folder package plus an honest message, never a fake PDF
- [ ] Unmergeable attachments produce placeholder pages and a skip report, never a failed compilation
- [ ] Every compilation creates a kept, versioned MeetingPack object; recompilation requires a change note
- [ ] The meeting detail page shows versions, downloads, and an outdated indicator driven by the stored fingerprint
- [ ] Confidential agenda items never appear in a compiled pack body; pack access follows meeting RBAC
- [ ] Active attendees receive the pack read-only in their own Files; per-attendee delivery failures are reported, not fatal
- [ ] Pack availability notification is declared via `x-openregister-notifications` (no imperative dispatch)

## Notes

- Rendering/merging is delegated to Docudesk `PdfService` (mPDF 8.2 + FPDI import); Decidiq itself takes no PDF library dependency. The `PdfService::mergePdfs()` extension lives in the docudesk project.
- The existing folder-based package (`agenda-management` "Agenda Document Package", `MeetingPackageService`) is unchanged and doubles as the non-Docudesk fallback.
- In-app annotations on packs are the sibling change `document-annotations`; bookmarks are named stably per agenda item so annotations can anchor to them later.
- OCP surfaces used: `OCP\Share\IManager` (per-attendee read-only shares), `OCP\BackgroundJob\IJobList` (compilation job); notifications flow through OpenRegister's declarative dialect, not `OCP\Notification\IManager` directly.
- ORI: packs of public meetings correspond to the meeting's document attachments in OpenRaadsinformatie; actual ORI/Woo publication remains in `public-publication` / `ori-api`.
