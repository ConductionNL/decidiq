---
status: partial
status-note: 2026-06-12 audit — 2/4 requirements built backend-only via p2-minutes-and-decisions* + board-meeting-resolutions capabilities (ResolutionService, WrittenResolutionService, MinutesGenerationService, MinutesAuthorizationService). Missing — real-time minute-taking editor, digital approval workflow UI, Docudesk PDF generation, notarial proof package.
---

# Resolution and Minutes Specification

## Purpose
@e2e exclude V1 feature — resolution generation (trigger from adopted decision), real-time minute-taking editor inside live meeting, digital approval workflow, and Docudesk PDF generation are not yet implemented in the SPA. The Minutes list renders existing objects but the generation/editing/approval UI described in this spec does not exist.

Resolutions and minutes are the formal output of the decision-making process. A resolution is the legal text of an adopted decision, suitable for archival and external communication. Minutes (notulen) are the structured record of a meeting including attendance, discussions, decisions, votes, and action items. The system supports real-time minute-taking during meetings, automated generation from meeting data, review/approval workflows, and integration with Docudesk for professional document rendering.

**Standards**: Akoma Ntoso (`act`, `minutes`), Schema.org (`CreativeWork`, `DigitalDocument`), OpenRaadsinformatie (`Besluit`, `Verslag`), MDTO (metadata for archival)
**Feature tier**: V1
**Legal reference**: BW 2:10 (minutes of board meetings), Gemeentewet 23 (council minutes), Awb 3:46-3:47 (formal decision documentation)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Resolution and Minutes entity definitions including property tables and standards mappings.

## Requirements

---

### Requirement: Resolution Generation

The system MUST support generating formal resolution texts from adopted decisions. Resolutions MUST include the decision text, voting results, legal basis, date of adoption, and governing body. Resolutions MUST be stored as OpenRegister objects and optionally rendered as documents via Docudesk.

**Feature tier**: V1

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

#### Scenario: Provide proof of proper adoption for notarial deed

- GIVEN a statute amendment resolution adopted with qualified majority
- WHEN the notary requests proof of proper adoption
- THEN the system MUST generate a complete package including: convocation proof, quorum verification, voting results, and the resolution text
- AND the package MUST be verifiable and tamper-evident

---

### Requirement: Real-Time Minute Taking

The system MUST support structured minute-taking during meetings using a digital template. Minutes MUST be pre-populated with meeting metadata (date, body, attendees, agenda). The secretary MUST be able to record notes, decisions, and action items per agenda item in real-time.

**Feature tier**: V1

#### Scenario: Take structured minutes during a meeting

- GIVEN an active meeting with agenda items
- WHEN the secretary opens the minutes editor
- THEN the template MUST be pre-populated with meeting date, body name, attendees, and agenda items
- AND for each agenda item, the secretary MUST be able to enter discussion notes, decisions, and action items
- AND voting results MUST be automatically inserted from the voting system

#### Scenario: Record action items during minute-taking

- GIVEN the secretary is recording minutes for an agenda item
- WHEN they add an action item "Prepare budget proposal" with owner "CFO" and deadline "2026-05-01"
- THEN the action item MUST be linked to the agenda item and meeting
- AND the action item MUST appear in the action tracking system (see decision-management spec)

---

### Requirement: Minutes Approval Workflow

The system MUST support a review and approval workflow for minutes. Draft minutes MUST be distributed to participants for review. Participants MUST be able to suggest corrections. The chair or designated approver MUST formally approve the minutes.

**Feature tier**: V1

#### Scenario: Distribute draft minutes for review

- GIVEN minutes have been drafted for a completed meeting
- WHEN the secretary marks the minutes as "ready for review"
- THEN all meeting participants MUST receive a notification with a link to the draft minutes
- AND participants MUST be able to submit correction suggestions

#### Scenario: Approve board minutes digitally

- GIVEN draft minutes with tracked changes from reviewers
- WHEN the chair reviews and approves the minutes
- THEN the minutes status MUST change to "approved"
- AND the approved minutes MUST be locked against further editing
- AND the approval MUST be recorded with timestamp and approver identity

---

### Requirement: Minutes Document Generation

The system MUST support generating professional minutes documents via Docudesk. The minutes MUST include all meeting metadata, attendance, per-item discussions, decisions with voting results, and action items.

**Feature tier**: V1

#### Scenario: Generate minutes document from meeting data

- GIVEN an approved set of minutes
- WHEN the secretary triggers "Generate Document"
- THEN the system MUST send the minutes data to Docudesk for rendering
- AND the generated document MUST be stored in Nextcloud Files linked to the meeting
- AND the document MUST be available in PDF and ODT formats

## User Stories

1. **Secretary taking digital minutes during AGM**: As a board secretary, I want to take structured minutes during the AGM using a digital template, so that all resolutions, votes, and key discussions are accurately captured. (Source: intelligence DB #11)

2. **CEO approving board minutes digitally**: As a CEO, I want to review and approve board minutes digitally with tracked changes, so that minutes are finalized quickly without email ping-pong. (Source: intelligence DB #20)

3. **Secretary drafting and distributing ALV minutes**: As secretary, I want to draft the ALV minutes including all decisions, voting results, and attendance and distribute them to members so that there is a formal record of the meeting. (Source: intelligence DB #75)

4. **Notary receiving proof of proper adoption**: As notary, I want to receive complete proof that the statute amendment was properly decided (quorum, qualified majority, proper convocation) so that I can execute the notarial deed. (Source: intelligence DB #78)

5. **Management assistant generating minutes from notes**: As a management assistant, I want to generate structured minutes from the notes and decisions captured during the meeting, so that minutes are available for review within hours instead of days. (Source: intelligence DB #93)

## Acceptance Criteria

- Resolutions are generated from adopted decisions with sequential numbering
- Resolutions include decision text, voting results, legal basis, and adoption date
- Real-time minute-taking is pre-populated from meeting metadata
- Voting results are automatically inserted into minutes from the voting system
- Minutes follow a review/approval workflow with tracked changes
- Approved minutes are locked against further editing
- Document generation is delegated to Docudesk (PDF/ODT)
- Notarial proof packages include convocation, quorum, votes, and resolution
- MDTO metadata is attached for archival compliance
- OpenRaadsinformatie `Besluit`/`Verslag` mapping is available
