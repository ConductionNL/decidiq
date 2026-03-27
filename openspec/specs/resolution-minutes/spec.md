---
status: idea
---

# Resolution and Minutes Specification

## Purpose

Resolutions and minutes are the formal output of the decision-making process. A resolution is the legal text of an adopted decision, suitable for archival and external communication. Minutes (notulen) are the structured record of a meeting including attendance, discussions, decisions, votes, and action items. The system supports real-time minute-taking during meetings, automated generation from meeting data, review/approval workflows, action item extraction and tracking, integration with Docudesk for professional document rendering, Archiefwet/MDTO compliance for archival, and Akoma Ntoso for resolution document structure.

**Standards**: Akoma Ntoso (`act`, `minutes`), Schema.org (`CreativeWork`, `DigitalDocument`), OpenRaadsinformatie (`Besluit`, `Verslag`), MDTO (metadata for archival)
**Feature tier**: V1
**Legal reference**: BW 2:10 (minutes of board meetings), Gemeentewet 23 (council minutes), Awb 1:3 (formal decision definition), Awb 3:46-3:47 (formal decision documentation and motivation), Archiefwet 2021 (10-year transfer, expanded scope), Woo Art. 3.3 (active publication obligation)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Resolution and Minutes entity definitions including property tables and standards mappings.

## Evidence Base

This specification is informed by **53 user stories**, **20 requirements** from Dutch government tenders, and **26 external sources** from the intelligence database. Key evidence clusters:

- **Requirement cluster #22**: "Archival destruction" -- 685 requirements across 189 tenders (Source: intelligence DB cluster #22)
- **Requirement cluster #37**: "Digital archiving" -- 379 requirements across 143 tenders (Source: intelligence DB cluster #37)
- **Requirement cluster #54**: "e-Depot (digital archive)" -- 247 requirements across 117 tenders (Source: intelligence DB cluster #54)
- **Awb Art. 1:3**: Defines "besluit" as a written decision containing a public law legal act; requires formal motivation (Source: intelligence DB ext #291)
- **Archiefwet 2021**: 10-year transfer to permanent archive (was 20), expanded scope to ALL government information including email and chat (Source: intelligence DB insight #19)
- **Akoma Ntoso 1.0 (OASIS)**: Standard for XML representation of legislative/parliamentary documents including debates, amendments, voting records (Source: intelligence DB ext #289)
- **Competitor analysis**: Diligent (AI Smart Minutes), OnBoard (AI minutes builder), Fellow.app (AI meeting notes), iBabs (AI minutes), Meeting Decisions (one-click minutes), Sherpany (signed minutes), BoardPro (decision register) (Source: intelligence DB competitors)
- **Market evidence**: AI meeting minutes market is rapidly expanding; 44% of action items never completed (Source: intelligence DB ext #342); Diligent processes minutes for 700K+ directors (Source: intelligence DB ext #180)

## Requirements

---

### Requirement: Resolution Generation

The system MUST support generating formal resolution texts from adopted decisions. Resolutions MUST include the decision text, voting results, legal basis, date of adoption, and governing body. Resolutions MUST be stored as OpenRegister objects and optionally rendered as documents via Docudesk. Resolutions MUST follow the Awb definition of "besluit" (written decision containing a public law legal act with formal motivation).

**Feature tier**: V1
**Legal reference**: Awb 1:3 (besluit definition), Awb 3:46-3:47 (motivation requirement)
**Evidence**: Automated decision list generation from voting results is a top-priority council requirement (Source: intelligence DB story #175). Diligent and BoardPro both feature automated resolution tracking (Source: intelligence DB competitors #642, ext #114)

#### Scenario: Generate a resolution from an adopted decision

- GIVEN a decision that has been adopted with voting results (14 for, 5 against, 1 abstain)
- WHEN the secretary triggers "Generate Resolution"
- THEN the system MUST create a resolution object with the decision text, voting results, adoption date, and governing body
- AND the resolution MUST have a unique sequential number per body (e.g., "2026-BES-042")
- AND the resolution MUST be available for export as PDF via Docudesk

#### Scenario: Generate a resolution with legal basis references

- GIVEN an adopted decision referencing Gemeentewet article 160
- WHEN the resolution is generated
- THEN the resolution MUST include the legal basis ("Gelet op artikel 160 van de Gemeentewet")
- AND the resolution text MUST follow Akoma Ntoso structure (preface, body, conclusions)
- AND the resolution MUST include the formal motivation (overwegingen) per Awb 3:46 (Source: intelligence DB ext #289)

#### Scenario: Provide proof of proper adoption for notarial deed

- GIVEN a statute amendment resolution adopted with qualified majority
- WHEN the notary requests proof of proper adoption
- THEN the system MUST generate a complete package including: convocation proof, quorum verification, voting results, and the resolution text
- AND the package MUST be verifiable and tamper-evident
- AND the package MUST be exportable as a single PDF/A document (Source: intelligence DB story #78)

#### Scenario: Auto-generate decision list from voting results

- GIVEN a council meeting has concluded with 12 agenda items voted on
- WHEN the clerk requests the decision list (besluitenlijst)
- THEN the system MUST automatically generate a list of all decisions with: agenda item number, subject, vote result, and outcome (adopted/rejected)
- AND the list MUST be publishable on the council website per Woo Art. 3.3 (Source: intelligence DB story #175, req #131443)

---

### Requirement: Real-Time Minute Taking

The system MUST support structured minute-taking during meetings using a digital template. Minutes MUST be pre-populated with meeting metadata (date, body, attendees, agenda). The secretary MUST be able to record notes, decisions, and action items per agenda item in real-time.

**Feature tier**: V1
**Evidence**: AI-powered minutes generation saves up to 60% of minuting work (Source: intelligence DB ext #179). Sherpany reports 45% productivity boost from end-to-end meeting lifecycle management (Source: intelligence DB ext #108). Dutch "notulen software" market offers 33% meeting time reduction with AI transcription (Source: intelligence DB ext #137)

#### Scenario: Take structured minutes during a meeting

- GIVEN an active meeting with agenda items
- WHEN the secretary opens the minutes editor
- THEN the template MUST be pre-populated with meeting date, body name, attendees, and agenda items
- AND for each agenda item, the secretary MUST be able to enter discussion notes, decisions, and action items
- AND voting results MUST be automatically inserted from the voting system
- AND the minutes MUST be structured by agenda item for easy navigation (Source: intelligence DB story #174)

#### Scenario: Record action items during minute-taking

- GIVEN the secretary is recording minutes for an agenda item
- WHEN they add an action item "Prepare budget proposal" with owner "CFO" and deadline "2026-05-01"
- THEN the action item MUST be linked to the agenda item and meeting
- AND the action item MUST appear in the action tracking system (see decision-management spec)
- AND the action item MUST automatically become a CalDAV VTODO task assigned to the responsible person (Source: intelligence DB stories #1834-1837)

#### Scenario: Auto-create structured folder for meeting dossier

- GIVEN a meeting has concluded with decisions, minutes, and related documents
- WHEN the meeting is finalized
- THEN the system MUST automatically create a folder structure in Nextcloud Files containing: agenda, minutes, all proposals, amendments, voting results, and attachments
- AND the folder MUST be linked to the meeting via _files metadata in OpenRegister (Source: intelligence DB stories #1841-1844)

---

### Requirement: AI-Assisted Minutes Generation

The system SHOULD support AI-assisted meeting transcription and minutes generation. The AI MUST produce structured output (decisions, action items, discussion summaries) that the secretary can review and edit.

**Feature tier**: V2
**Evidence**: Diligent AI auto-generates minutes from agendas, notes, and transcripts (Source: intelligence DB ext #180). Otter.ai achieves 93-95% transcription accuracy with real-time summaries (Source: intelligence DB ext #328). Board Intelligence Minute Writer produces governance-ready minutes from notes/transcripts (Source: intelligence DB ext #178)

#### Scenario: Generate AI-powered meeting transcription and summary

- GIVEN a meeting that was recorded or transcribed
- WHEN the secretary triggers AI-assisted minutes generation
- THEN the system MUST produce a draft with: (1) per-agenda-item discussion summary, (2) extracted decisions, (3) extracted action items with suggested owners
- AND the secretary MUST be able to review, edit, and approve the AI-generated draft
- AND the system MUST clearly mark AI-generated content for review (Source: intelligence DB story #345)

#### Scenario: AI extraction of action items from transcription

- GIVEN a meeting transcript containing phrases like "Jan will prepare the budget report by next Friday"
- WHEN the AI processes the transcript
- THEN the system MUST extract: action ("prepare budget report"), owner ("Jan"), deadline ("next Friday" resolved to date)
- AND present the extracted items for secretary confirmation before creating formal action items
- AND track action item completion rate over time (44% of action items are never completed per research) (Source: intelligence DB ext #342)

---

### Requirement: Minutes Approval Workflow

The system MUST support a review and approval workflow for minutes. Draft minutes MUST be distributed to participants for review. Participants MUST be able to suggest corrections. The chair or designated approver MUST formally approve the minutes.

**Feature tier**: V1
**Evidence**: Digital minutes approval eliminates "email ping-pong" (Source: intelligence DB story #20). 71% of Dutch municipal clerks want digital meeting tools to remain permanent (Source: intelligence DB ext #140)

#### Scenario: Distribute draft minutes for review

- GIVEN minutes have been drafted for a completed meeting
- WHEN the secretary marks the minutes as "ready for review"
- THEN all meeting participants MUST receive a notification with a link to the draft minutes
- AND participants MUST be able to submit correction suggestions with tracked changes
- AND the review period MUST have a configurable deadline

#### Scenario: Approve board minutes digitally

- GIVEN draft minutes with tracked changes from reviewers
- WHEN the chair reviews and approves the minutes
- THEN the minutes status MUST change to "approved"
- AND the approved minutes MUST be locked against further editing
- AND the approval MUST be recorded with timestamp and approver identity
- AND the approved minutes MUST be available for the next meeting's consent agenda

#### Scenario: Approve council minutes at next meeting

- GIVEN draft council minutes from the previous meeting
- WHEN the minutes are placed on the consent agenda of the next meeting
- WHEN council members have submitted no corrections or all corrections are processed
- THEN the chair puts the minutes to a formal adoption vote
- AND if adopted, the minutes MUST be marked as "vastgesteld" (adopted) with the adoption date (Source: intelligence DB story #177)

---

### Requirement: Minutes Document Generation via Docudesk

The system MUST support generating professional minutes documents via Docudesk. The minutes MUST include all meeting metadata, attendance, per-item discussions, decisions with voting results, and action items.

**Feature tier**: V1
**Evidence**: Document generation is a core integration point; competitors Diligent and Sherpany offer professionally formatted governance documents (Source: intelligence DB competitors #632, ext #108)

#### Scenario: Generate minutes document from meeting data

- GIVEN an approved set of minutes
- WHEN the secretary triggers "Generate Document"
- THEN the system MUST send the minutes data to Docudesk for rendering
- AND the generated document MUST be stored in Nextcloud Files linked to the meeting
- AND the document MUST be available in PDF/A and ODT formats
- AND the document MUST include the organizational logo and styling per NL Design System tokens

#### Scenario: Generate resolution document with Akoma Ntoso structure

- GIVEN an adopted resolution with legal basis, motivation, and decision text
- WHEN the secretary triggers resolution document generation
- THEN the system MUST produce a document following Akoma Ntoso structure: (1) preface (header, parties), (2) preamble (considerations, legal basis), (3) body (articles/decision points), (4) conclusions (date, signature block)
- AND the document MUST include MDTO metadata for archival compliance (Source: intelligence DB ext #289)

---

### Requirement: Action Item Tracking and Follow-Up

The system MUST support capturing, assigning, and tracking action items from meetings. Each action item MUST have an owner, deadline, status, and link to the originating meeting and agenda item. The system MUST support automatic follow-up workflows.

**Feature tier**: V1
**Evidence**: 44% of action items never completed; 71% of meetings fail objectives due to poor follow-through (Source: intelligence DB ext #342). Named owner, realistic deadline, and clear verb-based description are essential (Source: intelligence DB ext #142). Fellow.app and Decisions both offer centralized action item tracking (Source: intelligence DB competitors #672, ext #106)

#### Scenario: Capture action items during meeting

- GIVEN the secretary is recording minutes for a meeting
- WHEN they capture an action item: "Prepare quarterly report" assigned to the CFO with deadline 2026-06-01
- THEN the action item MUST be stored as a CalDAV VTODO task via _todos metadata
- AND the task MUST be assigned to the responsible person
- AND the task MUST have a reminder set before the deadline
- AND the action item MUST appear in both the minutes and the action item dashboard (Source: intelligence DB story #337)

#### Scenario: Track action item completion across meetings

- GIVEN multiple meetings have produced 25 action items over the past quarter
- WHEN the secretary views the action item dashboard
- THEN the system MUST show: total items, completed, overdue, completion rate
- AND items MUST be filterable by owner, meeting, deadline, and status
- AND overdue items MUST be automatically highlighted and optionally escalated (Source: intelligence DB story #91)

#### Scenario: Report action item completion rate

- GIVEN historical action item data for a governing body
- WHEN the manager views the meeting effectiveness scorecard
- THEN the system MUST calculate: completion rate (completed/total x 100), average time-to-completion, overdue rate
- AND these KPIs MUST be displayed per person and per meeting body (Source: intelligence DB story #331, ext #323)

---

### Requirement: Archival Compliance (Archiefwet/MDTO)

The system MUST support automatic archival of decisions and minutes with MDTO-compliant metadata. The system MUST support generation of Submission Information Packages (SIPs) for eDepot transfer. Records MUST have configurable retention periods based on the selectielijst.

**Feature tier**: V1
**Legal reference**: Archiefwet 2021 (10-year transfer, expanded scope), MDTO metadata standard
**Evidence**: 685 requirements across 189 tenders reference archival destruction processes; 379 reference digital archiving; 247 reference eDepot (Source: intelligence DB clusters #22, #37, #54). Notubiz Schiedam case study shows archiving from within the political portal preserves political context (Source: intelligence DB ext #100)

#### Scenario: Auto-archive council decisions with MDTO metadata

- GIVEN a council decision has been adopted and the resolution generated
- WHEN the archival process is triggered
- THEN the system MUST automatically populate MDTO metadata fields: creator, date, classification, decision type, retention period (from selectielijst), and governing body
- AND the record MUST be linked to its parent dossier (zaakdossier)
- AND the system MUST validate metadata completeness before archiving (Source: intelligence DB stories #182, #259, #262)

#### Scenario: Generate SIP package for eDepot transfer

- GIVEN a set of council records whose retention period triggers transfer to permanent archive
- WHEN the archivist initiates eDepot transfer
- THEN the system MUST generate a SIP (Submission Information Package) conforming to eDepot specifications
- AND the SIP MUST include: the documents (in PDF/A or ODF format), MDTO metadata XML, file checksums for integrity verification
- AND pre-transfer validation MUST check metadata completeness, format compliance, and integrity (Source: intelligence DB stories #273, #274)

#### Scenario: Verify archive completeness for council meeting

- GIVEN a council meeting has concluded 4 weeks ago
- WHEN the archivist runs a completeness check
- THEN the system MUST verify that all required records exist: agenda, minutes, all proposals, amendments, voting results, recordings, and attachments
- AND any missing items MUST be flagged with the responsible party for remediation (Source: intelligence DB story #183)

---

### Requirement: Record Retention and Destruction

The system MUST support configurable retention periods per record type based on the VNG selectielijst. The system MUST generate destruction lists when retention periods expire and enforce multi-step authorization for destruction.

**Feature tier**: V1
**Legal reference**: Archiefwet 2021, VNG selectielijst
**Evidence**: Archival destruction is the largest requirement cluster in Dutch government tenders (685 requirements, 189 tenders) (Source: intelligence DB cluster #22)

#### Scenario: Import selectielijst and map to classification scheme

- GIVEN the archivist wants to configure retention periods
- WHEN they import the VNG selectielijst
- THEN the system MUST map selectielijst categories to the organizational classification scheme (ordeningsplan)
- AND retention periods MUST be automatically applied to new records based on their classification (Source: intelligence DB story #268)

#### Scenario: Multi-step destruction authorization

- GIVEN records whose retention period has expired
- WHEN the system generates a destruction list
- THEN the records manager MUST first propose destruction
- AND the archivist MUST review and approve the destruction list
- AND only after both approvals MUST the system execute destruction
- AND a tamper-evident log of the destruction MUST be retained (Source: intelligence DB story #271)

#### Scenario: Legal hold preventing destruction

- GIVEN records involved in ongoing litigation
- WHEN the legal department places a legal hold
- THEN the system MUST prevent destruction of those records even if the retention period has expired
- AND the legal hold MUST be visible in the record's metadata and audit trail (Source: intelligence DB story #306)

---

### Requirement: Active Publication (Woo Compliance)

The system MUST support active publication of meeting documents, decisions, and minutes per Woo Art. 3.3. Published documents MUST be accessible to the public and maintained in a publication register.

**Feature tier**: V1
**Legal reference**: Wet open overheid (Woo) Art. 3.3
**Evidence**: Woo requires government organizations to actively publish 17 categories of information including meeting documents of councils and provincial states (Source: intelligence DB ext #296). Nearly half of Dutch council information systems are inadequate for public access (Source: intelligence DB ext #73)

#### Scenario: Publish council meeting documents automatically

- GIVEN a council meeting has been finalized with approved minutes and decision list
- WHEN the clerk triggers publication
- THEN the system MUST publish: the agenda, all proposals, voting results, decision list, and approved minutes
- AND each document MUST be added to the publication register with publication date
- AND published documents MUST be accessible via a public-facing search interface
- AND the system MUST track which documents have been published and which are pending (Source: intelligence DB story #280)

---

### Requirement: Cross-System Search and Linking

The system MUST provide unified search across decisions, motions, minutes, and related documents. The system MUST support cross-domain record linking for related cases.

**Feature tier**: V1
**Evidence**: Council members and citizens need natural language search across all council information (Source: intelligence DB story #186). Nextcloud unified search integration provides zero-effort discoverability (Source: intelligence DB stories #1869-1873)

#### Scenario: Search across all council information

- GIVEN a citizen wanting to find information about housing policy decisions
- WHEN they search for "woningbouw" in the public interface
- THEN the system MUST return matching: decisions, motions, minutes, proposals, and related documents
- AND results MUST be faceted by: document type, date range, governing body, and status
- AND each result MUST show a preview with highlighted matching text (Source: intelligence DB story #186)

#### Scenario: Search from Nextcloud unified search bar

- GIVEN a staff member working in Nextcloud
- WHEN they use the unified search bar to search for a decision reference
- THEN the system MUST return matching decisions, motions, and meeting minutes
- AND clicking a result MUST navigate to the Decidesk detail view (Source: intelligence DB stories #1869-1873)

---

### Requirement: Meeting Recording Integration

The system SHOULD support linking audio/video recordings to agenda items and indexing them for search. The system SHOULD support automatic captioning for accessibility.

**Feature tier**: V2
**Evidence**: Notubiz and GO Raadsinformatie both offer webcasting with speaker indexing (Source: intelligence DB competitors #596, #586). WCAG accessibility requires captions on meeting recordings (Source: intelligence DB story #185)

#### Scenario: Index video recording by agenda item

- GIVEN a council meeting that was video-recorded
- WHEN the recording is processed
- THEN the system MUST create index points at each agenda item transition
- AND viewers MUST be able to jump directly to a specific agenda item in the recording
- AND the system SHOULD integrate with the AV/webcast system for automatic speaker identification (Source: intelligence DB stories #184, #200)

#### Scenario: Add captions to meeting recordings

- GIVEN a council meeting recording
- WHEN the recording is post-processed
- THEN the system MUST generate accurate captions (via speech-to-text)
- AND captions MUST be reviewable and editable by the secretary
- AND the captioned recording MUST be accessible to hearing-impaired citizens (Source: intelligence DB story #185)

## User Stories

### Priority: Must Have

1. **Secretary taking structured minutes during AGM**: As a board secretary, I want to take structured minutes during the AGM using a digital template so that all resolutions, votes, and key discussions are accurately captured. (Source: intelligence DB #11)
2. **Raadsadviseur auto-generating decision list**: As a raadsadviseur, I want the decision list to be automatically generated from the recorded voting results so that publication is faster and more accurate. (Source: intelligence DB #175)
3. **Griffier linking minutes to agenda items**: As a commissiegriffier, I want minutes to be automatically structured by agenda item so that they are easy to navigate and search. (Source: intelligence DB #174)
4. **Griffier submitting draft minutes for approval**: As a griffier, I want to submit draft minutes for approval at the next meeting with tracked corrections so that the approval process is transparent. (Source: intelligence DB #177)
5. **Archivaris auto-archiving council decisions**: As an archivaris, I want council decisions to be automatically archived with complete metadata according to the retention schedule so that compliance is ensured without manual intervention. (Source: intelligence DB #182)
6. **Archivaris verifying archive completeness**: As an archivaris, I want to verify that all council meeting records (agenda, minutes, decisions, recordings, documents) are complete in the archive so that no gaps exist in the democratic record. (Source: intelligence DB #183)
7. **Secretary capturing action items with owner and deadline**: As a secretary, I want to capture action items during the meeting with an assigned owner and deadline and automatically create follow-up tasks. (Source: intelligence DB #337)
8. **Secretary auto-creating tasks from action items**: As a secretary, I want action items recorded in meeting minutes to automatically become CalDAV VTODO tasks assigned to the responsible person so that nothing falls through the cracks. (Source: intelligence DB #1834)
9. **Records manager creating automatic dossier folders**: As a records manager, I want each decision to have an automatically structured folder in Nextcloud Files containing all related documents. (Source: intelligence DB #1841)
10. **Any user searching across decisions and minutes**: As any user, I want to search for decisions, motions, meeting minutes, and related documents from the Nextcloud unified search bar. (Source: intelligence DB #1869)
11. **Records manager creating archival records automatically**: As a Records Manager, I want the system to automatically create an archival record when a formal decision is registered so that no decision goes unarchived. (Source: intelligence DB #259)
12. **Records manager auto-populating MDTO metadata**: As a Records Manager, I want the system to auto-populate as many MDTO metadata fields as possible from context so that manual entry is minimized. (Source: intelligence DB #262)

### Priority: Should Have

13. **CEO approving board minutes digitally**: As a CEO, I want to review and approve board minutes digitally with tracked changes so that minutes are finalized quickly without email ping-pong. (Source: intelligence DB #20)
14. **Notary accessing meeting information**: As a notary, I want secure access to the meeting agenda, attendee list, and voting results so that I can prepare accurate notarial minutes for contentious meetings. (Source: intelligence DB #12)
15. **Notary receiving finalized resolution texts**: As a notary, I want to receive finalized resolution texts and supporting documents digitally so that I can prepare and execute the notarial deed efficiently. (Source: intelligence DB #34)
16. **Management assistant generating minutes from notes**: As a management assistant, I want to generate structured minutes from the notes and decisions captured during the meeting so that minutes are available for review within hours instead of days. (Source: intelligence DB #93)
17. **Secretary generating AI-powered transcription and summaries**: As a secretary, I want automatic meeting transcription with AI-generated summaries (key decisions, action items, discussion points) so that minutes creation is automated and nothing is missed. (Source: intelligence DB #345)
18. **Raadsadviseur indexing video by agenda item**: As a raadsadviseur, I want video recordings to be automatically indexed by agenda item so that viewers can jump directly to specific topics. (Source: intelligence DB #184)
19. **Accessibility officer adding captions to recordings**: As a toegankelijkheidsmedewerker, I want meeting recordings to have accurate captions so that hearing-impaired citizens can follow council proceedings. (Source: intelligence DB #185)
20. **Records manager tracking archival backlog KPIs**: As an Archivist, I want KPIs tracking the archival backlog (unclassified records, overdue transfers, pending destructions) so that I can report to management. (Source: intelligence DB #301)
21. **Council secretary tracking BOB phases in minutes**: As a council secretary, I want to tag each meeting with its BOB phase and track how topics progress through phases so that the decision-making process is transparent. (Source: intelligence DB #341)
22. **Records manager archiving Talk conversations**: As a records manager, I want Talk conversations linked to decisions to be exportable and archivable as part of the decision record so that we comply with Archiefwet requirements. (Source: intelligence DB #1854)

### Priority: Could Have

23. **Secretary drafting and distributing ALV minutes**: As secretary, I want to draft the ALV minutes including all decisions, voting results, and attendance and distribute them to members so that there is a formal record of the meeting. (Source: intelligence DB #75)
24. **Notary receiving proof of proper adoption**: As notary, I want to receive complete proof that the statute amendment was properly decided (quorum, qualified majority, proper convocation) so that I can execute the notarial deed. (Source: intelligence DB #78)
25. **New board member accessing decision history**: As a new board member, I want to access all historical decisions, current action items, financial status, and governance documents so that I can quickly become effective in my role. (Source: intelligence DB #84)
26. **Member accessing association documents**: As a member, I want to access meeting minutes, financial reports, and decision history through a self-service portal so that I can stay informed about association governance. (Source: intelligence DB #80)
27. **Hearing secretary producing structured report**: As a hearing secretary, I want to produce a structured hearing report from minutes so that the decision-maker has a clear overview of all arguments. (Source: intelligence DB #239)
28. **Journalist accessing meeting recordings immediately**: As a journalist, I want to access meeting recordings as soon as possible after the meeting ends so that I can report accurately with direct quotes. (Source: intelligence DB #194)
29. **Journalist searching voting history**: As a journalist, I want to search voting records by topic, member name, or faction so that I can research political positions for my reporting. (Source: intelligence DB #187)
30. **Archivist generating SIP packages for eDepot**: As an Archivist, I want to generate Submission Information Packages (SIPs) conforming to eDepot specifications so that records can be transferred digitally. (Source: intelligence DB #273)

## Competitor Analysis

| Competitor | Minutes/Resolution Features | Strengths | Gaps |
|---|---|---|---|
| **Diligent Boards** | AI Smart Minutes from agendas/notes/transcripts; action item tracking; voting & resolutions | Enterprise leader (700K+ directors); AI-powered governance-grade output | Expensive; no legislative features; no archival compliance; closed platform |
| **OnBoard** | AI meeting minutes; shared annotation; minutes builder; analytics | Strong board governance UX; SOC 2/ISO 27001 certified | No council/municipal features; no MDTO/eDepot; no public-facing publication |
| **iBabs** | AI minutes; decision tracking with action items; document management | Strong Dutch municipal presence; ISO certified; affordable | Limited AI capabilities; no Akoma Ntoso; no resolution document generation |
| **Meeting Decisions** | AI notetaker; one-click minutes; secure voting with audit trail | Good for Microsoft Teams integration; fast minute generation | No governance-grade formatting; no archival compliance; no legislative features |
| **Fellow.app** | AI transcription; action item tracking; collaborative notes | Best action item workflow; centralized tracking across meetings | No formal decision/resolution handling; no voting; no archival compliance |
| **Notubiz** | Minutes creation/publication; audio/video recording and archiving; speech-to-text | Dominant Dutch council market; video+agenda item indexing | Poor search UX; limited document generation; closed platform |
| **Sherpany** | End-to-end meeting lifecycle; digitally signed minutes; 45% productivity boost | Swiss quality; strong security; covers prep through follow-up | No Dutch market presence; no legislative features; expensive |
| **BoardPro** | Automated decision register from meetings, votes, and flying minutes | Searchable by keyword/date; automatic categorization and audit trail | Limited to board governance; no council features; no archival |
| **GO Raadsinformatie** | Document management; webcasting; search and archive | Council document publishing; video integration | Aging platform; limited automation; no AI capabilities |

(Sources: intelligence DB competitors #632, #659, #607, #678, #683, #668, #594, #585, ext #108, #180, #114)

## Acceptance Criteria

- Resolutions are generated from adopted decisions with sequential numbering per body
- Resolutions include decision text, voting results, legal basis, formal motivation (overwegingen), and adoption date
- Decision lists (besluitenlijsten) are auto-generated from voting results
- Resolution documents follow Akoma Ntoso structure (preface, preamble, body, conclusions)
- Real-time minute-taking is pre-populated from meeting metadata and structured by agenda item
- Voting results are automatically inserted into minutes from the voting system
- Action items are captured with owner, deadline, and automatically created as CalDAV VTODO tasks
- Action item completion rate is tracked and reportable as a KPI
- AI-assisted minutes generation produces reviewable drafts with extracted decisions and action items
- Minutes follow a review/approval workflow with tracked changes and configurable deadline
- Approved minutes are locked against further editing with timestamp and approver identity
- Document generation is delegated to Docudesk (PDF/A and ODT formats)
- Automatic dossier folder structure per decision in Nextcloud Files via _files metadata
- MDTO metadata is auto-populated and validated for archival compliance
- SIP packages are generated for eDepot transfer with pre-transfer validation
- Selectielijst-based retention periods are configurable with automated destruction lists
- Multi-step destruction authorization (propose, review, approve, execute) is enforced
- Legal holds prevent destruction during litigation
- Active publication of meeting documents per Woo Art. 3.3 with publication register
- Unified search across decisions, motions, and minutes via Nextcloud Search
- Faceted search on MDTO metadata fields (date, creator, classification, type, status)
- Video recordings are indexed by agenda item with caption support
- Notarial proof packages include convocation, quorum, votes, and resolution
- Archive completeness checks verify all required meeting records exist
- OpenRaadsinformatie `Besluit`/`Verslag` mapping is available
- Every lifecycle event is logged in Nextcloud Activity stream
