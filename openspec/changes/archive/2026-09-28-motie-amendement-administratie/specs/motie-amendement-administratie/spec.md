---
status: draft
---

# Spec: Decidiq Motie en Amendement Administratie

## Purpose

Decidiq implements a comprehensive motion (motie) and amendment (amendement) administration system for Dutch municipal councils. This spec captures the data model, lifecycle workflows, voting mechanics with fractie-snapshot immutability, execution status tracking, automated escalation at 90+ days of silence, searchable historical archive across terms, public publication to the griffie portal with WCAG AA compliance, and detection of "motion bingo" (vague takeovers without concrete action).

## ADDED Requirements

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: motie-administratie                                       -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

### Requirement: REQ-MOT-001 — Motie schema and lifecycle

The system SHALL provide a Motion (Motie) schema on OpenRegister with the following fields:
- `title` (string, required): Motion title
- `proposer_id` (string FK→Person, required): Primary proposer
- `proposer_party_id` (string FK→Fractie, required): Proposer's party at submission
- `co_signers` (array of Person IDs, optional): Additional signatories
- `preamble` (text, optional): "Gelet op..." preamble (rich text)
- `dispositif` (text, required): "Draagt college op..." operative clause (rich text)
- `meeting_id` (string FK→Meeting, required): Scheduled meeting
- `agenda_item_id` (string FK→AgendaItem, optional): Agenda placement
- `motie_status` (enum, required): ingediend | behandeling | aangenomen | verworpen | aangehouden | ingetrokken | overgenomen-door-college
- `voting_type` (enum, required): hoofdelijk | bij-zitten-en-opstaan | unaniem
- `execution_status` (enum, required): niet-van-toepassing | in-behandeling | uitgevoerd | afgewezen | gedeeltelijk-uitgevoerd
- `execution_deadline` (date, optional): Target completion date for college
- `portfolio_holder_id` (string FK→Person, optional): Responsible alderman after adoption
- `submitted_at` (datetime, required, immutable): Submission timestamp
- `published_at` (datetime, optional): Publication to public griffie portal

Motie statuses SHALL transition as: ingediend → (behandeling →)? (aangenomen | verworpen | aangehouden | ingetrokken | overgenomen-door-college).
Aangehouden motions SHALL be automatically re-agendaed to a specified next meeting.
Ingetrokken motions SHALL NOT proceed to voting.
Overgenomen-door-college motions SHALL skip voting and mark execution_status as in-behandeling with a mandatory ExecutionUpdate.

#### Scenario: Motie indienen door raadslid
- **GIVEN** a councilor is logged in as member of active party
- **AND** an active council meeting exists with status voorbereiding or actief
- **WHEN** the councilor submits a new motion with title, dispositif, meeting id, and optional co-signers
- **THEN** the motion receives status ingediend, a unique id M-{year}-{sequence}, and is visible to griffier
- **AND** co-signers receive notification and must confirm before public listing

#### Scenario: Motie aangehouden en herscheduled
- **GIVEN** a motion has status aangehouden with beoogde_volgende_vergadering set
- **WHEN** the griffier marks the motion as aangehouden
- **THEN** the motion is automatically added to the next meeting's agenda as a discussion item
- **AND** retains its original number M-{year}-{sequence}
- **AND** appears in "openstaande moties" overview for the party

---

### Requirement: REQ-MOT-002 — Amendement schema linked to proposal

The system SHALL provide an Amendment (Amendement) schema on OpenRegister with:
- `title` (string, required): Amendment title
- `proposer_id` (string FK→Person, required): Primary proposer
- `proposer_party_id` (string FK→Fractie, required): Proposer's party at submission
- `co_signers` (array of Person IDs, optional): Additional signatories
- `proposal_id` (string FK→Proposal, required): MUST reference an agendaed proposal
- `original_text` (text, required): Literal text from the proposal being amended
- `modified_text` (text, required): Proposed replacement text
- `rationale` (text, optional): Justification (rich text)
- `amendement_status` (enum, required): ingediend | aangenomen | verworpen | aangehouden | ingetrokken | overgenomen-door-college
- `voting_type` (enum, required): hoofdelijk | bij-zitten-en-opstaan | unaniem
- `submitted_at` (datetime, required, immutable): Submission timestamp

An amendment WITHOUT a proposal_id SHALL NOT be persisted. The system SHALL generate and display a diff-view (side-by-side original vs. modified).

#### Scenario: Amendement koppelen aan raadsvoorstel
- **GIVEN** a council proposal is agendaed for a meeting
- **WHEN** a councilor submits an amendment with original_text and modified_text
- **THEN** the system generates a side-by-side diff view
- **AND** rejects the amendment if original_text does not match any substring in the proposal exactly
- **AND** assigns a unique id A-{year}-{sequence}

#### Scenario: Diff validation rejects mismatched text
- **GIVEN** a proposal contains the text "Betonverharding wordt aangelegd."
- **WHEN** a user submits an amendment with original_text "Beton wird aangelegd." (typo)
- **THEN** the amendment is rejected with error "Original text not found in proposal."

---

### Requirement: REQ-MOT-003 — Vote result (Stemresultaat) with fractie snapshot

The system SHALL provide a Vote Result (Stemresultaat) schema with:
- `motie_or_amendement_id` (string FK, required): Reference to Motion or Amendment
- `raadslid_id` (string FK→Person, required): Voting councilor
- `fractie_id` (string, immutable): Party ID as of vote date
- `fractie_name_snapshot` (string, immutable): Party name as of vote date (for display if party is renamed)
- `vote` (enum, required): voor | tegen | onthouden | afwezig | niet-deelgenomen
- `voting_explanation` (text, optional): Optional statement (PII, opt-in for publication)
- `voted_at` (datetime, required, immutable): Vote timestamp

The fractie_id and fractie_name_snapshot fields SHALL be locked at creation time and MUST NOT be updated, even if the councilor later switches parties.

#### Scenario: Fractie snapshot captures party at vote time
- **GIVEN** councilor Alice was a VVD member on 2024-06-14
- **AND** Alice switches to GroenLinks on 2024-07-01
- **WHEN** a historical search queries "How did Alice's party vote on 2024-06-14?"
- **THEN** the vote result shows fractie_id=vvd, fractie_name_snapshot="VVD"
- **AND** does NOT show GroenLinks

#### Scenario: Head-to-head voting registration
- **GIVEN** a motion or amendment is called for head-to-head voting
- **WHEN** the griffier opens the voting matrix
- **THEN** the system displays all present councilors with buttons for voor/tegen/onthouden
- **AND** councilors marked absent on the attendance list are pre-set to afwezig
- **AND** voting is locked until griffier confirms

---

### Requirement: REQ-MOT-004 — Execution update timeline and escalation

The system SHALL provide an Execution Update (UitvoeringsUpdate) schema with:
- `motie_id` (string FK→Motion, required): Target motion
- `status_change` (enum): nie-van-toepassing | in-behandeling | uitgevoerd | afgewezen | gedeeltelijk-uitgevoerd
- `explanation` (text, required): Status narrative (rich text)
- `attachments` (array of file UUIDs, optional): e.g., collegebrief, voortgangsrapport
- `updated_by_id` (string FK→Person, required): Alderman or staff member
- `updated_at` (datetime, required): Update timestamp

The latest ExecutionUpdate status_change SHALL be synced to the Motion.execution_status field. Execution updates older than 90 days SHALL trigger an automated reminder email to the portfolio_holder_id.

#### Scenario: Motie-uitvoering bijhouden
- **GIVEN** a motion has status aangenomen and execution_status in-behandeling
- **WHEN** the portfolio holder adds an ExecutionUpdate with status_change=uitgevoerd
- **THEN** the motion's execution_status field is updated to uitgevoerd
- **AND** a timeline of all ExecutionUpdates remains visible

#### Scenario: 90-day escalation reminder
- **GIVEN** an ExecutionUpdate exists with updated_at = 2024-06-14
- **AND** today is 2024-09-30 (>90 days)
- **AND** no newer ExecutionUpdate exists
- **WHEN** ReminderJob runs daily
- **THEN** an email is sent to the portfolio_holder with the motion title and "No update in 90+ days"
- **AND** the motion appears on a "Motions Needing Updates" dashboard

---

### Requirement: REQ-MOT-005 — "Motie-bingo" detection warning

When a Motion transitions to overgenomen-door-college WITHOUT a corresponding ExecutionUpdate, the system SHALL:
1. Display a warning to the griffier: "Motion taken over by college, but no concrete action plan attached."
2. Place the motion on an "Vague Takeovers" list (UI flag).
3. Require an explicit ExecutionUpdate before the motion is marked complete.

#### Scenario: Vague takeover warning
- **GIVEN** a motion was on the agenda and the college announces "We take this over"
- **WHEN** the griffier marks motie_status = overgenomen-door-college without a concrete ExecutionUpdate
- **THEN** a yellow warning appears: "Vague takeover without action plan"
- **AND** the motion appears on the "Vague Takeovers" list for the council to see
- **AND** once a concrete ExecutionUpdate (with plan text) is added, the warning clears

---

### Requirement: REQ-MOT-006 — Searchable historical archive across terms

The system SHALL expose a full-text search API endpoint `/api/motions/search` with:
- Query string (title, preamble, dispositif, explanations)
- Filters: `year` (range), `party`, `portfolio_holder`, `motie_status`, `execution_status`, `vote` (how a specific councilor voted)
- Results: paginated 20/page, sorted by relevance then date descending
- Archive span: all motions regardless of council term

Results SHALL include motions from archived council periods (before current term).

#### Scenario: Cross-term search on keyword
- **GIVEN** searches are stored from 2020–2024 and archived 2024-05-31 (new term)
- **WHEN** a user searches for "fietspad verbreding"
- **THEN** results include motions from both 2020–2024 and the current term, sorted by relevance

#### Scenario: Councilor voting history filter
- **GIVEN** a councilor Alice with voting history in terms 2020–2024 and current
- **WHEN** filtering motions where "Alice's party voted voor"
- **THEN** all motions are listed where Alice (via her fractie at that time) voted voor
- **AND** results show her party affiliation at each vote date (via fractie_name_snapshot)

---

### Requirement: REQ-MOT-007 — Public griffie portal publication with WCAG AA

When a Motion or Amendment achieves status aangenomen or verworpen, and the griffier activates publication, the system SHALL:
1. Generate a public page at `/griffie/moties/{M-year-seq}` with:
   - Title, proposers, co-signers
   - Full preamble and dispositif
   - Vote tally (for/against/abstain counts by party if hoofdelijk) OR single result (if unanimity)
   - Stemresultaat table (optional public list of councilor votes, configurable per governance body)
   - Current execution_status and latest ExecutionUpdate summary
   - Amendment links (if any amendementen were adopted as changes to the proposal)
2. Ensure all pages meet **WCAG 2.1 AA** accessibility standards (color contrast, heading hierarchy, alt text, keyboard nav)
3. Include **OWMS metadata** (schema.org structured data) so search engines index the motion with:
   - Title, date, status, publication date
   - Subject classification (TOOI vocabulary if available)
4. Publish an **Atom/RSS feed** per portfolio-holder so media can subscribe

#### Scenario: Public motie-pagina met stemresultaten
- **GIVEN** a motion M-2024-001 achieved status aangenomen
- **WHEN** the griffier clicks "Publish to Public Portal"
- **THEN** a page appears at `/griffie/moties/M-2024-001` with:
  - Motion title, proposers, full text
  - Vote count: "12 voor, 3 tegen, 2 onthouden"
  - Party breakdown (if configured): VVD: 8 voor, 1 tegen, etc.
  - Current execution_status: e.g., "In behandeling — Laatste update 30 september 2024"
- **AND** the page is WCAG AA compliant (tested with axe)

#### Scenario: OWMS metadata for search engine indexing
- **WHEN** a search engine crawls `/griffie/moties/M-2024-001`
- **THEN** the HTML includes schema.org metadata:
  ```html
  <script type="application/ld+json">
    {"@context": "https://schema.org", "@type": "GovernmentDocument",
     "name": "M-2024-001: Aanvraag herinrichting Marktplein",
     "datePublished": "2024-06-15", ...}
  </script>
  ```
- **AND** OWMS classification tags are present if populated

---

### Requirement: REQ-MOT-008 — End-of-term motion report (PDF)

The system SHALL expose an endpoint `/api/motions/endofterm-report` (griffier-only) that generates a PDF report containing:
1. All motions of the completed term (grouped by year)
2. Status distribution (aangenomen, verworpen, ingetrokken, etc.) with counts
3. Motions with open execution_status (in-behandeling, gedeeltelijk-uitgevoerd) marked for handover to next term
4. Party voting breakdown (how each party voted on major motions)
5. Top portfolio-holders by number of motions assigned
6. Optional: list of councilors switching parties (from Raadslid-fractie-historie)

The report generation SHALL be asynchronous (background job) with a completion notification and download link sent to the griffier.

#### Scenario: Griffier exports end-of-term report
- **GIVEN** the term ends 2024-05-31 (new elections)
- **WHEN** the griffier clicks "Generate End-of-Term Report"
- **THEN** a background job queues; griffier sees "Report generating..."
- **AND** after completion (~30 seconds for ~100 motions), an email arrives with download link
- **AND** the PDF contains all motions, status summary, and open items marked for carryover

---

### Requirement: REQ-MOT-009 — Bulk import of carryover motions

At the start of a new council term, the griffier SHALL be able to bulk-import motions with open execution_status from the previous term. The import UI SHALL:
1. Display all motions from the previous term with `execution_status != uitgevoerd | afgewezen | niet-van-toepassing`
2. Allow re-assignment of `portfolio_holder_id` based on the new college composition
3. Optionally assign a "carryover" tag or flag in the UI
4. Auto-notify each party's chair of carryover motions they inherited

#### Scenario: Bulk-import openstaande moties to new term
- **GIVEN** term 2020–2024 ended with 12 motions in execution_status=in-behandeling
- **WHEN** griffier runs "Import Carryover Motions"
- **THEN** all 12 are cloned to the new term with the same title/text
- **AND** portfolio_holder_id is reassigned (e.g., "Alderman X" → "Alderman Y" based on new college)
- **AND** each party chair receives notification: "You have inherited X open motions from the previous term"

---

### Requirement: REQ-MOT-010 — Voting explanation (stemverklaring) and opt-in publication

Councilors MAY provide a short voting explanation (stemverklaring) when their vote diverges from their party's recommendation (if a party vote recommendation is known from the fractie register). Explanations SHALL:
1. Be optional and free-text (max 200 characters)
2. Require explicit **opt-in for publication** (default: private)
3. Be marked as published ONLY if the councilor grants permission (AVG compliance)
4. Be displayed on the public motion page if published

#### Scenario: Raadslid geeft afwijkende stemverklaring
- **GIVEN** a party recommended "voor", but councilor Bob voted "tegen"
- **WHEN** the voting matrix is submitted
- **THEN** Bob is prompted: "Your vote differs from your party's recommendation. Add a statement?"
- **AND** Bob enters: "Ik stem tegen omdat de begroting onvoldoende is voor duurzaamheid."
- **AND** a checkbox appears: "Publish this statement to the public portal?" (unchecked by default)
- **AND** if checked, the statement appears on the public motion page attributed to Bob

#### Scenario: Voting explanation defaults to private
- **GIVEN** Bob submits his vote with an explanation but does NOT check "Publish"
- **WHEN** the public motion page is viewed
- **THEN** the motion shows the vote count (12 voor, 3 tegen, 2 onthouden)
- **AND** Bob's name is NOT listed as a "tegen" voter; the explanation is not visible

---

### Requirement: REQ-MOT-011 — Proposal amendment integration on adoption

When an Amendement achieves status aangenomen:
1. The system SHALL create a proposal-amendment record linking the Amendement and the Proposal
2. The system SHALL generate a new version of the Proposal.text with the modified_text substituted for original_text
3. The Proposal SHALL show all applied amendments in a change log

#### Scenario: Aangenomen amendement wijzigt raadsvoorstel tekst
- **GIVEN** a proposal P1 with text "Betonverharding wordt aangelegd."
- **AND** amendment A-2024-002 modifies this to "Groene verharding en boomenlaan worden aangelegd."
- **WHEN** A-2024-002 achieves status aangenomen
- **THEN** a new version of P1 is created with the substituted text
- **AND** the change log shows: "A-2024-002 applied: 'Betonverharding...' → 'Groene verharding...'"

---

### Requirement: REQ-MOT-012 — Regulatory and metadata compliance

The system SHALL:
1. **Gemeentewet artikel 147a (amendement), artikel 169 (collegeverantwoording)**: Motion and execution_status are immutable vote records and college accountability evidence.
2. **OWMS 4** (Overheid.nl Web Metadata Standard): All public motions include OWMS-formatted metadata.
3. **DUTO** (Durable Access): Motion/amendment texts are archived in a format suitable for long-term access (PDF + raw text).
4. **TOOI** (Thesaurus Officiële Informatie): Motion subjects are optionally tagged with TOOI vocabulary (if available from openregister).
5. **Woo** (Open Government Act): Adopted motions are proactively published as open data (category 6 raadsstukken).
6. **AVG** (GDPR): Voting explanations with personal names require explicit opt-in; default anonymization is supported.

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: amendement-administratie                                   -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

### Requirement: REQ-AMD-001 — Amendment submission validation

An Amendement can only be created if:
1. `proposal_id` references an existing, agendaed Proposal
2. `original_text` is found as a literal substring in the Proposal text
3. `modified_text` is non-empty and differs from `original_text`

If any condition fails, the system SHALL return a structured error:
```json
{
  "isError": true,
  "error": "amendment_invalid",
  "message": "Original text not found in proposal. Copy-paste the exact text from the proposal."
}
```

#### Scenario: Amendment missing original text
- **GIVEN** a proposal contains "Beton verharding"
- **WHEN** user submits amendment with original_text "Betonverharding" (no space)
- **THEN** the submission is rejected with message "Original text not found..."

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: stemming-administratie                                      -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

### Requirement: REQ-STEM-001 — Voting matrix UI for head-to-head voting

When a Motion or Amendment is called for head-to-head (hoofdelijk) voting, the system SHALL present:
1. A grid of all present councilors (names in rows, columns: voor / tegen / onthouden)
2. A touch/click-to-vote button layout
3. Real-time tally display (total counts updated as each vote is entered)
4. Ability for a councilor to change their vote before the voting round is locked
5. A "Lock and confirm" button (griffier-only) that finalizes the vote round

#### Scenario: Hoofdelijke stemming registreren
- **GIVEN** 23 councilors are present at the meeting
- **WHEN** the chairperson calls a motion for head-to-head voting
- **THEN** the griffier opens the voting matrix showing 23 names + buttons
- **AND** a councilor (or griffier on their behalf) clicks "voor"
- **AND** the tally updates: "voor: 1, tegen: 0, onthouden: 0"
- **AND** once all 23 have voted, griffier clicks "Lock vote"
- **AND** the system records all 23 Stemresultaat records with voted_at timestamp

---

### Requirement: REQ-STEM-002 — Absence handling on voting matrix

Councilors marked absent on the attendance list at meeting start SHALL be pre-populated as `afwezig` on the voting matrix. The griffier SHALL NOT be able to change them to autre votes without first marking them present on the attendance list.

#### Scenario: Afwezig raadslid can be marked present mid-meeting
- **GIVEN** councilor Alice was marked absent
- **WHEN** Alice arrives and the griffier updates the attendance list to mark Alice present
- **THEN** the voting matrix is updated to allow Alice's vote

---

### Requirement: REQ-STEM-003 — Party recommendation and voting divergence detection

If a party has issued a voting recommendation (e.g., "VVD: voor" or "GroenLinks: tegen") and a councilor votes differently:
1. The system SHALL flag the divergence in the voting UI
2. The councilor SHALL be prompted to provide a voting explanation (stemverklaring)
3. The voting explanation SHALL default to private (not published) unless the councilor opts in

#### Scenario: Afwijkend stemgedrag prompt
- **GIVEN** the VVD party recommended "voor" on a motion
- **AND** councilor Bob (VVD) votes "tegen"
- **WHEN** the voting round is submitted
- **THEN** Bob sees: "You voted differently from your party's recommendation. Add a statement?"
- **AND** a text field appears for optional statement (max 200 chars)

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Capability: uitvoerings-tracking                                        -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

### Requirement: REQ-EXEC-001 — Portfolio holder dashboard

The system SHALL expose a portfolio-holder dashboard at `/dashboard/motions-assigned-to-me` showing:
1. All motions where `portfolio_holder_id` matches the logged-in alderman
2. Grouped by `execution_status` (in-behandeling, gedeeltelijk-uitgevoerd, executed, rejected)
3. Sorted by `execution_deadline` (ascending; overdue items at top)
4. A quick-add form to create an ExecutionUpdate without leaving the page
5. Red warning badge for motions >90 days without update

#### Scenario: Portfolio holder sees assigned motions dashboard
- **GIVEN** alderman Carol is portfolio holder for 8 motions
- **WHEN** she navigates to `/dashboard/motions-assigned-to-me`
- **THEN** she sees:
  - **In Behandeling (3)**: M-2024-001 (due 2024-12-31), M-2024-005 (due 2024-11-30), M-2024-012 (overdue, red badge "90+ days")
  - **Gedeeltelijk Uitgevoerd (2)**: M-2024-003, M-2024-008
  - **Uitgevoerd (2)**: M-2024-002, M-2024-007
  - **Afgewezen (1)**: M-2024-010
- **AND** a "Add Update" button on each motion

---

### Requirement: REQ-EXEC-002 — Automated 90-day reminder job

A daily background job `ReminderJob` SHALL:
1. Query all motions with `execution_status = in-behandeling` or `gedeeltelijk-uitgevoerd`
2. Check the latest ExecutionUpdate.updated_at for each
3. If > 90 days ago (or no ExecutionUpdate exists), email the `portfolio_holder_id` with:
   - Motion title
   - Time since last update
   - Link to add new ExecutionUpdate
4. Log the reminder in the motion's audit trail

#### Scenario: ReminderJob triggers email after 90 days
- **GIVEN** motion M-2024-001 has last ExecutionUpdate on 2024-06-14
- **AND** today is 2024-09-30 (108 days later)
- **WHEN** ReminderJob runs at 08:00 UTC
- **THEN** alderman Carol (portfolio_holder) receives email:
  ```
  Motion M-2024-001: "Aanvraag herinrichting Marktplein"
  Status: In Behandeling
  Last Update: 14 juni 2024 (108 days ago)
  Action: Add an update — [LINK]
  ```

---

### Requirement: REQ-EXEC-003 — Overdue motion highlighting

The system SHALL:
1. Highlight motions where `execution_deadline` < today in red (overdue)
2. Show a count badge "X motions overdue" on the dashboard
3. Sort overdue motions to the top of the `in-behandeling` group

#### Scenario: Overdue motion visibility
- **GIVEN** motion M-2024-001 has `execution_deadline = 2024-12-31`
- **AND** today is 2025-01-15
- **WHEN** the portfolio holder views the dashboard
- **THEN** M-2024-001 is highlighted in red and sorted to the top
- **AND** a badge shows "Overdue: 1 motion"

---

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- Cross-capability: Data integrity and audit trail                        -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->

### Requirement: REQ-DATA-001 — Immutable timestamps and audit trail

The following fields SHALL be immutable after creation:
- Motion.submitted_at
- Amendment.submitted_at
- VoteResult.voted_at
- VoteResult.fractie_id, fractie_name_snapshot

All mutations to motions/amendments (status changes, portfolio_holder reassignment, execution_status updates) SHALL be logged in the OpenRegister audit trail with:
- Timestamp
- User ID and name
- Action type (e.g., "status_changed_from_ingediend_to_aangenomen")
- Old value and new value

#### Scenario: Audit trail captures status change
- **GIVEN** motion M-2024-001 is being processed
- **WHEN** griffier clicks "Mark as Adopted"
- **THEN** the audit trail records:
  ```json
  {
    "timestamp": "2024-06-14T11:35:00Z",
    "userId": "griffier-daan",
    "action": "motie_status_changed",
    "oldValue": "ingediend",
    "newValue": "aangenomen"
  }
  ```

---

### Requirement: REQ-DATA-002 — CSV/JSON export of motions

The system SHALL expose export endpoints:
- `GET /api/motions/export?format=csv|json` — all motions with all fields
- `GET /api/motions/{id}/executions/export` — execution timeline for a single motion

Exports SHALL include fractie_name_snapshot (so historical analysis is not lost if fractie names change).

---

