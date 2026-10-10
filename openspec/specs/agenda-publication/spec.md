---
status: done
---

# agenda-publication Specification

## Purpose
Lets the secretary or chair publish a complete agenda package for a meeting and make it available to participants ahead of time. Publication validates the agenda, notifies all active participants, and updates the meeting's calendar event; supporting files can be attached to individual items, the published agenda is browsable online sorted by order number, and the full agenda can be exported to CSV.

## Requirements

### Requirement: REQ-PUB-001 Secretary publishes a complete agenda package
The app SHALL allow the secretary or chair to publish the agenda for a Meeting. Publication SHALL validate that all required items are present, send Nextcloud notifications to all Participants of the GovernanceBody, and update the calendar event for the meeting via `CalendarEventService`.

#### Scenario: Secretary publishes agenda
- **GIVEN** a Meeting with lifecycle `scheduled` and at least one AgendaItem with `orderNumber ≥ 1`
- **WHEN** the secretary clicks "Agenda publiceren" and confirms
- **THEN** `AgendaService::publishAgenda()` is called, all Participants receive a Nextcloud notification, and the Meeting's calendar event is updated with the agenda summary
- **AND** the Meeting status badge on the detail page shows "Gepubliceerd"

#### Scenario: Publication blocked when agenda is empty
- **GIVEN** a Meeting with lifecycle `scheduled` and no AgendaItems
- **WHEN** the secretary clicks "Agenda publiceren"
- **THEN** a validation error is shown: "Een agenda moet minimaal één agendapunt bevatten"
- **AND** the publication is not executed

#### Scenario: Meeting already published cannot be re-published
- **GIVEN** a Meeting whose agenda has already been published
- **WHEN** the secretary navigates to the agenda builder
- **THEN** the "Agenda publiceren" button is replaced by "Agenda herzien" (which creates a revision)

---

### Requirement: REQ-PUB-002 Participants receive notification on agenda publication
Upon agenda publication, all active Participants (leftAt is null) of the GovernanceBody SHALL receive a Nextcloud notification linking directly to the Meeting detail page.

#### Scenario: Notification sent to all active participants
- **GIVEN** a GovernanceBody with 5 active Participants and 1 former Participant (leftAt set)
- **WHEN** the agenda is published
- **THEN** exactly 5 Nextcloud notifications are sent via `NotificationService`
- **AND** the former Participant does not receive a notification

#### Scenario: Notification includes meeting title and date
- **WHEN** a Participant receives the agenda publication notification
- **THEN** the notification body contains the Meeting `title` and `scheduledDate` in Dutch format (e.g., "Raadsvergadering 14 april 2025 — Agenda gepubliceerd")

---

### Requirement: REQ-PUB-003 Supporting files are attached to agenda items
The app SHALL allow the secretary to attach files (PDF, Word, Excel) to individual AgendaItems via the OpenRegister `FileService`. Attachments SHALL be visible in both the agenda builder and the public-facing meeting agenda view.

#### Scenario: Secretary attaches a document to an agenda item
- **GIVEN** an AgendaItem in the agenda builder
- **WHEN** the secretary uploads a PDF via the `CnObjectSidebar` Files tab or a dedicated upload button
- **THEN** the file is stored via `FileService` and a file attachment count badge appears on the AgendaItem row ("2 bijlagen")

#### Scenario: Participants can download attachments
- **GIVEN** an AgendaItem with a published agenda and an attached PDF
- **WHEN** a Participant opens the AgendaItem detail page
- **THEN** the attached files are listed with download links in the `CnObjectSidebar` Files tab

#### Scenario: File type validation
- **WHEN** the secretary attempts to upload an executable file (.exe, .sh)
- **THEN** the upload is rejected with an error: "Bestandstype niet toegestaan"

---

### Requirement: REQ-PUB-004 Agenda is accessible online before the meeting
Published AgendaItems for a Meeting SHALL be accessible to all Participants via the Meeting detail page. The list SHALL be sortable by `orderNumber` and SHALL show `itemType`, `estimatedDuration`, and attachment count for each item.

#### Scenario: Participant views published agenda
- **GIVEN** a Meeting with published agenda
- **WHEN** any Participant (or Nextcloud user with meeting access) navigates to the Meeting detail page
- **THEN** the "Agenda" tab or section shows all AgendaItems ordered by `orderNumber`
- **AND** each item shows its type badge (Informatief / Discussie / Besluit), estimated duration, and attachment count

#### Scenario: Agenda item description shown on expand
- **WHEN** a Participant clicks on an AgendaItem row in the published agenda
- **THEN** the item detail page opens showing the full `description` and all attachments

---

### Requirement: REQ-PUB-005 Agenda items can be exported to CSV
The app SHALL allow export of the full agenda for a meeting to CSV via `ExportService` and `CnMassExportDialog`. The export SHALL include: orderNumber, title, itemType, estimatedDuration, spokesperson, and attachment count. The `title` column SHALL be exported without the orderNumber prefix.

#### Scenario: Secretary exports agenda to CSV
- **GIVEN** a Meeting with published agenda items
- **WHEN** the secretary clicks "Exporteren" and selects CSV in `CnMassExportDialog`
- **THEN** a CSV file is downloaded with columns: Nummer, Titel, Type, Duur (min), Spreker, Bijlagen

#### Scenario: Title exported without numbering
- **GIVEN** an AgendaItem with `orderNumber: 3` and `title: "Bestemmingsplan Centrum"`
- **WHEN** the CSV export is downloaded
- **THEN** the Titel column contains "Bestemmingsplan Centrum" (not "3. Bestemmingsplan Centrum")

### Requirement: REQ-APIM-001 Publishing the agenda invites the members

The chair or secretary SHALL be able to publish the agenda from the meeting page; each member SHALL receive an invitation with the date, place and agenda items by the delivery they chose.

#### Scenario: Members are invited
- GIVEN a council meeting with five agenda items and 30 members
- WHEN the secretary presses Publish agenda
- THEN each member finds an invitation in the bell, and members who chose email get one listing the five items

### Requirement: REQ-APIM-002 A published agenda of a public meeting can go public

Publishing the agenda SHALL record when the convocation was sent, and a public meeting SHALL then be publishable to the public catalogue.

#### Scenario: The public sees the agenda
- GIVEN a public meeting whose agenda was published
- WHEN the clerk presses Publish in the Publication widget
- THEN the agenda appears in the public catalogue

### Requirement: REQ-PPS-001 Public papers are published with the agenda

Publishing an agenda SHALL publish the non-confidential papers of its public items, with the body, date and document type citizens filter on.

#### Scenario: A resident reads the papers
- GIVEN the council agenda of 14 October has papers on three items, one confidential
- WHEN the clerk publishes the agenda
- THEN a resident finds the papers of the two public items in the public catalogue and can filter on the council and the date
- AND each publication names its document type (agenda, decision or minutes), and body and meeting date are facetable

#### Scenario: Withdrawing the agenda takes its papers offline
- GIVEN the agenda of 14 October was published with the papers of two items
- WHEN the clerk withdraws the publication
- THEN those papers are no longer public, and a rectified version keeps online the papers it still publishes

### Requirement: REQ-PPS-002 A confidential item never reaches the public

An agenda item under an imposed or ratified confidentiality restriction (scope item) SHALL NOT appear on any public surface: not its title in the published agenda, not its papers, not in what the catalogue indexes. A paper labelled confidential on a public item SHALL NOT be published. Restrictions are read in system context, so the answer does not depend on what the publishing clerk may read. When the restrictions cannot be read, the agenda SHALL NOT be published.

#### Scenario: A confidential item stays out
- GIVEN item 2 of the agenda is under an imposed restriction and item 4 under a ratified one
- WHEN the clerk publishes the agenda
- THEN neither title nor any paper of items 2 and 4 is in the stored payload, the publication record or the catalogue publication

#### Scenario: A dissolved restriction no longer keeps the item out
- GIVEN the restriction on item 3 is dissolved
- WHEN the clerk publishes the agenda
- THEN item 3 is on the published agenda with its papers

#### Scenario: Unreadable restrictions stop the publication
- GIVEN the confidentiality restrictions cannot be read
- WHEN the clerk publishes the agenda
- THEN the publication is refused with a message, no payload is stored, nothing reaches the catalogue and no paper is made public
