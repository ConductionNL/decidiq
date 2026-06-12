---
status: draft
---

# Spec Delta: Resolution and Minutes (minutes-ui-v1)

## Purpose

Closes the four gaps recorded in the seeded spec's status note: real-time
minute-taking editor in the live meeting view, digital approval workflow UI with
participant correction suggestions, minutes document generation persisted to the
meeting's Files folder (optional Docudesk PDF), and the notarial proof package.
Resolution generation from adopted decisions itself shipped earlier
(decision-state-machine-v1) and is not duplicated here. On archive, the main spec's
whole-spec `@e2e exclude` purpose note is replaced by per-scenario annotations.

## MODIFIED Requirements

---

### Requirement: Resolution Generation

The system MUST support generating formal resolution texts from adopted decisions. Resolutions MUST include the decision text, voting results, legal basis, date of adoption, and governing body. Resolutions MUST be stored as OpenRegister objects and optionally rendered as documents via Docudesk.

**Feature tier**: V1

#### Scenario: Generate a resolution from an adopted decision

@e2e exclude resolution records are generated server-side by the decision enact transition (decision-state-machine-v1); the triggering UI is the DecisionLifecycleTab covered by the decision-management spec's e2e suite — no separate minutes-side surface exists by design

- GIVEN a decision that has been adopted with voting results (14 for, 5 against, 1 abstain)
- WHEN the secretary triggers "Generate Resolution"
- THEN the system MUST create a resolution object with the decision text, voting results, adoption date, and governing body
- AND the resolution MUST have a unique sequential number per body (e.g., "2026-BES-042")
- AND the resolution MUST be available for export as PDF via Docudesk

#### Scenario: Generate a resolution with legal basis references

@e2e exclude backend template rendering with no UI surface of its own (PHPUnit-covered in MinutesGenerationServiceTest / ResolutionServiceTest); the legal-basis text appears inside the generated document verified server-side

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

#### Scenario: Reject minutes back to draft with a comment

- GIVEN minutes in the "review" state
- WHEN the chair rejects them with the comment "Attendance list incomplete"
- THEN the minutes MUST return to the "draft" state
- AND the rejection comment, the rejecting user, and the timestamp MUST be recorded on the minutes record
- AND a rejection without a comment MUST be refused

---

### Requirement: Minutes Document Generation

The system MUST support generating professional minutes documents via Docudesk. The minutes MUST include all meeting metadata, attendance, per-item discussions, decisions with voting results, and action items. When Docudesk is not installed, the system MUST still persist a plain (markdown) document and state honestly that PDF rendering was unavailable; it MUST NOT fail or silently pretend a PDF was produced. (Documented limitation: ODT output is not implemented — no ODT renderer exists in the stack.)

**Feature tier**: V1

#### Scenario: Generate minutes document from meeting data

- GIVEN an approved set of minutes
- WHEN the secretary triggers "Generate Document"
- THEN the system MUST send the minutes data to Docudesk for rendering
- AND the generated document MUST be stored in Nextcloud Files linked to the meeting
- AND the document MUST be available in PDF and ODT formats

#### Scenario: Generate minutes document without Docudesk installed

@e2e exclude graceful-degradation branch is environment-dependent (Docudesk present or absent on the test instance); the fallback contract is locked by PHPUnit (MinutesDocumentServiceTest) and the Newman minutes collection, and the same UI button is exercised by the generate-document e2e test above

- GIVEN an approved set of minutes on an instance without the Docudesk app
- WHEN the secretary triggers "Generate Document" with the PDF format
- THEN the system MUST persist the markdown document into the meeting's Files folder
- AND the response MUST state that Docudesk was unavailable and a markdown fallback was produced
