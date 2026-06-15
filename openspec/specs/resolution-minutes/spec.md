---
status: in-progress
status-note: 2026-06-12 minutes-ui-v1 — all 4 requirements built. Real-time minute-taking editor (MinutesPanel in LiveMeeting, itemNotes autosave), approval workflow UI (MinutesApprovalTab — submit/approve/reject-with-comment + participant correction suggestions), document generation persisted to the meeting Files folder via MinutesDocumentService (markdown canonical, Docudesk PDF opportunistic with honest fallback; ODT NOT implemented — no renderer in the stack), and the hash-sealed notarial proof package (ProofPackageService). Earlier backend capabilities from p2-minutes-and-decisions* + board-meeting-resolutions (ResolutionService, WrittenResolutionService, MinutesGenerationService, MinutesAuthorizationService) remain the foundation. Per-scenario @e2e annotations replaced the former whole-spec exclude. In progress 2026-06-14 via unify-decision-supertype (resolutions stored as decisionType=resolution decisions per ADR-005). In progress 2026-06-14 via retire-board-portal (parallel Resolution/BoardMinutes/BoardVote/BoardAuditLogEntry schemas + views retired per ADR-006; corporate minutes folded onto the universal minutes/decision/vote/auditTrail entities).
openspec-changes:
  - unify-decision-supertype
  - retire-board-portal
  - decision-methods
---

# Resolution and Minutes Specification

## Purpose

Resolutions and minutes are the formal output of the decision-making process. A resolution is the legal text of an adopted decision, suitable for archival and external communication. Minutes (notulen) are the structured record of a meeting including attendance, discussions, decisions, votes, and action items. The system supports real-time minute-taking during meetings, automated generation from meeting data, review/approval workflows, and integration with Docudesk for professional document rendering.

**Standards**: Akoma Ntoso (`act`, `minutes`), Schema.org (`CreativeWork`, `DigitalDocument`), OpenRaadsinformatie (`Besluit`, `Verslag`), MDTO (metadata for archival)
**Feature tier**: V1
**Legal reference**: BW 2:10 (minutes of board meetings), Gemeentewet 23 (council minutes), Awb 3:46-3:47 (formal decision documentation)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Resolution and Minutes entity definitions including property tables and standards mappings.
## Requirements

---

### Requirement: Resolution Generation

The system MUST support generating formal resolution texts from adopted decisions. Resolutions MUST include the decision text, voting results, legal basis, date of adoption, and governing body. A generated resolution MUST be stored as a `decision` OpenRegister object with `decisionType = resolution` (the retired standalone `resolution` schema is replaced per ADR-005), carrying the folded resolution fields (`resolutionNumber`, resolution `type`, `voteType`, `voteThreshold`, `fullText`, `background`, `adoptionDate`, `effectiveDate`). Resolutions MAY be rendered as documents via Docudesk.

**Feature tier**: V1

#### Scenario: Generate a resolution as a typed decision

@e2e exclude resolution records are generated server-side by the decision enact transition (decision-state-machine-v1); the triggering UI is the DecisionLifecycleTab covered by the decision-management spec's e2e suite — no separate minutes-side surface exists by design

- GIVEN a decision that has been adopted with voting results (14 for, 5 against, 1 abstain)
- WHEN the secretary triggers "Generate Resolution"
- THEN the system MUST create a `decision` object with `decisionType = resolution` carrying the decision text, voting results, adoption date, and governing body
- AND the resolution decision MUST have a unique sequential `resolutionNumber` per body (e.g., "2026-BES-042")
- AND the resolution MUST be available for export as PDF via Docudesk

#### Scenario: Generate a resolution with legal basis references

@e2e exclude backend template rendering with no UI surface of its own (PHPUnit-covered in MinutesGenerationServiceTest / ResolutionServiceTest); the legal-basis text appears inside the generated document verified server-side

- GIVEN an adopted decision referencing Gemeentewet article 160
- WHEN the resolution is generated
- THEN the resolution decision MUST include the legal basis ("Gelet op artikel 160 van de Gemeentewet")
- AND the resolution text MUST follow Akoma Ntoso structure (preface, body, conclusions)

#### Scenario: Provide proof of proper adoption for notarial deed

- GIVEN a statute amendment resolution adopted with qualified majority
- WHEN the notary requests proof of proper adoption
- THEN the system MUST generate a complete package including: convocation proof, quorum verification, voting results, and the resolution text
- AND the package MUST be verifiable and tamper-evident

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

### Requirement: REQ-RM-CORP-RES — Resolution is a typed decision (mode=corp)
A resolution MUST be a universal `decision` with `decisionType=resolution`
(ADR-005), never a separate schema. Accordingly the parallel `Resolution` schema
(slug `resolution`), the `ResolutionList` /
`ResolutionDetail` Vue views, the resolution routes, the resolution
controller/service, and the `ResolutionLifecycleGuard` are REMOVED. A resolution
is a universal `decision` with `decisionType=resolution` (ADR-005, done in
`unify-decision-supertype`). The `resolution` entry is removed from the unified
search provider; decisions remain searchable.

#### Scenario: Resolution is a decision, not a separate schema
@e2e exclude register-schema-structure invariant — verified by register-import + PHPUnit, not browser-observable
- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no `resolution` schema exists
- AND resolutions are represented as `decision` objects with `decisionType=resolution`

#### Scenario: Decisions remain searchable after resolution removal
@e2e exclude search-provider configuration invariant — verified by PHPUnit on the searched-schemas set, not browser-observable
- GIVEN the unified search provider
- WHEN its searched schemas are inspected
- THEN `resolution` is not listed
- AND `decision` and `meeting` are still searched

### Requirement: REQ-RM-CORP-SUB — Board vote/minutes/material/audit fold into universal entities (mode=corp)
Corporate board votes MUST be `vote`/`voting-round`, board minutes MUST be
`minutes`, board materials MUST be DigitalDocument attachments, and the board
audit log MUST use the OR built-in `auditTrail` — never separate schemas.
Accordingly the parallel `BoardVote` (slug `board-vote`), `BoardMinutes`
(slug `board-minutes`), `BoardMaterial` (slug `board-material`), and
`BoardAuditLogEntry` (slug `board-audit-log-entry`) schemas are REMOVED. Board
votes are `vote`/`voting-round`; board minutes are `minutes`; board materials are
generic DigitalDocument attachments; the board audit log uses the OR built-in
audit trail. The retained governance services (eIDAS, regulator-export,
governance-report, multilingual-reconciliation, proxy-vote, audit-log) are
retargeted onto these unified entities, keeping their auth guards.

#### Scenario: Board sub-entities removed and services retargeted
@e2e exclude register-schema-structure + service-retargeting invariant — verified by register-import + PHPUnit, not browser-observable
- GIVEN the register is imported and the app boots
- WHEN the schemas are listed and the governance services run
- THEN no `board-vote` / `board-minutes` / `board-material` / `board-audit-log-entry` schema exists
- AND the retained governance services query `vote` / `minutes` / `decision` / `audit-trail` instead

### Requirement: Minutes signing resolves a signature-method stage

When a `DecisionStage` has `method=signature`, the eIDAS signing of its `signedDocument` SHALL reuse the existing minutes signing flow — signatories are read from `Minutes.signedBy` and the QES workflow is driven by `EIDASSignatureService`. On signing completion, the service SHALL resolve the related signature stage (link `signedDocument`, set `outcome=adopted` + `decidedAt`). No separate Signature schema SHALL be introduced; the signed artefact remains a `DigitalDocument` and the signatories remain `Minutes.signedBy`, consistent with ADR-006's retirement of parallel board-* entities.

#### Scenario: Signed minutes resolve the ratifying signature stage
@e2e exclude eIDAS QES signing flow — external signing provider not driveable in headless e2e; verified by PHPUnit on EIDASSignatureService stage resolution

- **GIVEN** a `method=signature` DecisionStage whose `signedDocument` is the meeting minutes and whose signatories are listed in `Minutes.signedBy`
- **WHEN** the chair and secretary complete eIDAS signing
- **THEN** `EIDASSignatureService` resolves the stage to `outcome=adopted` with `decidedAt` stamped, reusing the minutes signing flow rather than a new signature entity

### Requirement: Minute-taking editor initialization from an AI-generated draft

The minute-taking editor SHALL support initialization from an AI-generated draft produced by the meeting-transcription capability, in addition to the existing metadata pre-population. AI-generated content SHALL be visibly marked in the editor (a draft-provenance banner plus per-section markers), and the secretary SHALL be able to accept, edit, or discard each generated section independently. Accepting, editing, or submitting AI-initialized minutes SHALL follow the existing review and approval workflow without any shortcut: AI provenance SHALL never alter the lifecycle, and approval SHALL always be an explicit human action. The provenance metadata SHALL be retained on the minutes record through approval for audit purposes.

#### Scenario: Editor pre-filled from a generated draft

- **GIVEN** a meeting with an AI-generated draft from its transcript
- **WHEN** the secretary opens the minutes editor and chooses to start from the generated draft
- **THEN** the template is pre-populated with the per-agenda-item generated summaries alongside the existing metadata pre-population, a provenance banner is shown, and each generated section carries a visible AI marker

#### Scenario: Discard a generated section

- **GIVEN** the editor initialized from a generated draft
- **WHEN** the secretary discards the generated section for one agenda item and writes their own text
- **THEN** the discarded content is removed, the replacement section carries no AI marker, and the other generated sections are unaffected

#### Scenario: Approval workflow unchanged for AI-initialized minutes

- **GIVEN** minutes that were initialized from an AI-generated draft and marked ready for review
- **WHEN** the chair approves them
- **THEN** the approval follows the existing workflow (review, correction suggestions, explicit approval with timestamp and approver identity, locking), and the retained provenance metadata records that the draft originated from AI generation

#### Scenario: Provenance retained for audit

@e2e exclude metadata-retention contract — covered by PHPUnit on the minutes record
- **WHEN** AI-initialized minutes reach `approved`
- **THEN** the minutes record still carries the generation provenance (provider id, generated-at, sections accepted as generated vs. rewritten)

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
