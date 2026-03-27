---
status: idea
---

# Motion and Amendment Specification

## Purpose

Motions and amendments are the formal mechanisms for proposing decisions and modifying proposals before a vote. A motion is a formal proposal submitted by a member for consideration by the governing body. An amendment is a proposed modification to a pending motion. The system supports the full motion lifecycle (draft, submitted, seconded, debated, voted, adopted/rejected/withdrawn), amendment drafting with diff visualization, amendment voting order (most far-reaching first per parliamentary procedure), conflict detection for overlapping amendments, and Dutch-specific motion types (motie, motie van orde, motie van wantrouwen, initiatiefvoorstel).

**Standards**: Akoma Ntoso (`bill`, `amendment`, `motion`), Schema.org (`Action`, `ReplaceAction`), OpenRaadsinformatie (`Motie`, `Amendement`)
**Feature tier**: V1
**Legal reference**: Gemeentewet 147a (right to submit motions), Reglement van Orde (rules of procedure), BW 2:37 (counter-nomination), BW 2:42 (statute amendment process)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Motion and Amendment entity definitions including property tables, Akoma Ntoso alignment, and OpenRaadsinformatie mapping.

## Evidence Base

This specification is informed by **113 user stories**, **32 requirements** from Dutch government tenders, and **31 external sources** from the intelligence database. Key evidence clusters:

- **Requirement cluster #43**: "Besluitvorming (decision process)" -- 271 requirements across 133 tenders include motion/amendment handling (Source: intelligence DB cluster #43)
- **Tender requirement #5572**: "De Oplossing ondersteunt de registratie, behandeling en archivering van raadsinstrumenten zoals moties, amendementen, initiatiefvoorstellen, toezeggingen" (Source: intelligence DB req #5572)
- **Tender requirement #5573**: "Moties en amendementen zijn koppelbaar aan specifieke agendapunten en vergaderingen" (Source: intelligence DB req #5573)
- **Tender requirement #131443**: "Het systeem kan besluitenlijsten, lijsten van openstaande moties en amendement enz. genereren" (Source: intelligence DB req #131443)
- **Competitor analysis**: OpenSlides (motion management + amendment workflow), Decidim (proposal system), Notubiz (council instruments), Parlaeus (BBV process) (Source: intelligence DB competitors)
- **ParlTech Framework**: Transformation framework for digital parliament covering motion digitization (Source: intelligence DB ext #82)

## Requirements

---

### Requirement: Motion Submission

The system MUST support submitting motions with a title, body text, proposer, co-signers, and rationale. Motions MUST follow the governing body's rules for submission (e.g., minimum co-signers, submission deadline). Motions MUST be stored as OpenRegister objects in the `decidesk` register using the `motion` schema.

**Feature tier**: V1
**Evidence**: 18 tender requirements reference motion/amendment management in bestuurlijke besluitvorming (Source: intelligence DB reqs #5572, #5573, #131443)

#### Scenario: Submit a motion with co-signers

- GIVEN a member of a governing body with an active meeting
- WHEN they submit a motion with title "Sustainability Policy", body text with the proposal, 3 co-signers, and a rationale
- THEN the system MUST create an OpenRegister object with the `motion` schema
- AND the motion status MUST be set to `submitted`
- AND the chair MUST be notified of the new motion
- AND the motion MUST appear on the agenda for consideration

#### Scenario: Reject motion below minimum co-signer threshold

- GIVEN a governing body requiring 2 co-signers for motions
- WHEN a member submits a motion with only 1 co-signer
- THEN the system MUST reject the submission with a message indicating the minimum co-signer requirement
- AND the member MUST be able to add more co-signers and resubmit

#### Scenario: Submit a motion during a live meeting

- GIVEN a meeting in progress
- WHEN a member submits a motion via the meeting interface
- THEN the chair MUST receive a real-time notification
- AND the chair MUST be able to add the motion to the current agenda or defer to next meeting

#### Scenario: Draft motion from standardized template

- GIVEN a council member wanting to create a formal motion
- WHEN they select the motion template
- THEN the system MUST provide a structured form with required fields: title, dictum (requested action), rationale (overwegingen), and optional attachments
- AND the template MUST follow the Reglement van Orde format for the specific governing body (Source: intelligence DB story #144)

---

### Requirement: Motion Co-Signature Collection

The system MUST support digital collection of co-signatures for motions. Co-signers MUST be able to review the motion text and confirm their support digitally. The system MUST track co-signature status in real-time.

**Feature tier**: V1
**Evidence**: Digital co-signature collection is a key workflow improvement over paper-based processes (Source: intelligence DB story #145)

#### Scenario: Collect co-signatures digitally

- GIVEN a council member has drafted a motion requiring 3 co-signers
- WHEN they send co-signature requests to selected colleagues
- THEN each invitee MUST receive a notification with the motion text
- AND each invitee MUST be able to accept or decline the co-signature request
- AND the proposer MUST see real-time status of pending/accepted/declined requests
- AND upon reaching the required number, the motion MUST become submittable

#### Scenario: Withdraw co-signature before submission

- GIVEN a member has agreed to co-sign a motion that has not yet been submitted
- WHEN the co-signer reviews the final text and finds it changed
- THEN they MUST be able to withdraw their co-signature
- AND the proposer MUST be notified of the withdrawal

---

### Requirement: Dutch Motion Types

The system MUST support the following Dutch-specific motion types, each with its own procedural rules: (1) Motie -- a statement of opinion or request to the executive, (2) Motie van orde -- a procedural motion about the meeting process, (3) Motie van wantrouwen -- a motion of no confidence, (4) Amendement -- a proposed modification to a draft decision, (5) Initiatiefvoorstel -- a legislative proposal initiated by a council member.

**Feature tier**: V1
**Legal reference**: Gemeentewet 147a, Reglement van Orde
**Evidence**: Parlaeus supports the full BBV process including proposal routing through college and council (Source: intelligence DB competitor #601, ext #63). Tender requirements specify "registratie, behandeling en archivering van raadsinstrumenten" (Source: intelligence DB req #5572)

#### Scenario: Submit a motie van orde (procedural motion)

- GIVEN a meeting in progress with debate on an agenda item
- WHEN a council member raises a motie van orde (e.g., to limit speaking time)
- THEN the system MUST flag it as a procedural motion requiring immediate handling
- AND the chair MUST immediately put it to vote (no debate required)
- AND the result MUST be applied immediately to the meeting proceedings

#### Scenario: Submit a motie van wantrouwen (motion of no confidence)

- GIVEN a council meeting where a faction wants to express no confidence in a wethouder
- WHEN the motion of no confidence is submitted
- THEN the system MUST flag it as a special motion type with heightened visibility
- AND the system MUST enforce that the vote is nominal (hoofdelijke stemming)
- AND if adopted, the system MUST trigger the appropriate governance actions

#### Scenario: Create an initiatiefvoorstel (council-initiated proposal)

- GIVEN a council member wanting to propose new policy
- WHEN they create an initiatiefvoorstel
- THEN the system MUST route it through the standard proposal workflow: drafting -> legal review -> financial review -> committee discussion -> plenary vote
- AND the proposal MUST follow the same template as executive proposals (raadsvoorstel)

---

### Requirement: Amendment Drafting and Submission

The system MUST support creating amendments to pending motions or proposals. Amendments MUST clearly show what text is being added, removed, or modified (diff view). Multiple amendments to the same motion MUST be supported. The system MUST detect conflicting amendments that modify the same text.

**Feature tier**: V1
**Evidence**: Rapporteurs in European Parliament manage hundreds of amendments per file (Source: intelligence DB story #205). OpenSlides provides motion management with amendment workflow (Source: intelligence DB competitor #707)

#### Scenario: Submit an amendment to a pending motion

- GIVEN a pending motion "Sustainability Policy" with body text
- WHEN a member submits an amendment that modifies paragraph 2
- THEN the system MUST store the amendment with a reference to the original motion
- AND a diff view MUST show the original text and proposed changes (additions in green, removals in red)
- AND the amendment MUST have its own status lifecycle (submitted, under consideration, voted, adopted/rejected)

#### Scenario: Submit multiple amendments to the same motion

- GIVEN a pending motion with one existing amendment
- WHEN another member submits a second amendment to a different paragraph
- THEN both amendments MUST be tracked independently
- AND the system MUST detect if amendments conflict (modify the same text)

#### Scenario: Draft amendment with inline text selection

- GIVEN a council member viewing a proposal text
- WHEN they select text in the proposal and choose "Create Amendment"
- THEN the system MUST pre-populate the amendment with the selected text as the target
- AND the member MUST be able to edit the replacement text inline
- AND the system MUST generate a clean diff showing exactly what changes (Source: intelligence DB story #148)

#### Scenario: Preview resulting text after amendment

- GIVEN a proposal with an amendment that modifies two paragraphs
- WHEN a member clicks "Preview Result"
- THEN the system MUST display the full proposal text as it would read if the amendment is adopted
- AND unchanged text MUST be shown normally, changed text MUST be highlighted (Source: intelligence DB story #149)

---

### Requirement: Amendment Voting Order

The system MUST enforce the parliamentary rule that amendments are voted on before the main motion. When multiple amendments exist, the most far-reaching amendment MUST be voted on first. The chair MUST be able to set the voting order. The system MUST suggest an order based on amendment scope.

**Feature tier**: V1
**Legal reference**: Reglement van Orde (standard parliamentary procedure: last-in-first-out or most radical first)
**Evidence**: Amendment ordering is a core parliamentary procedure requirement across all council tender requirements (Source: intelligence DB stories #150, #311)

#### Scenario: Vote on amendments before the main motion

- GIVEN a motion with 2 amendments
- WHEN the chair initiates voting on the motion
- THEN the system MUST present amendments for voting first, in the order set by the chair
- AND after all amendments are resolved, the (possibly amended) main motion MUST be put to vote
- AND the final motion text MUST incorporate all adopted amendments

#### Scenario: Chair sets amendment voting order

- GIVEN a motion with 3 amendments
- WHEN the chair reviews the amendments
- THEN the chair MUST be able to reorder the amendments for voting
- AND the system MUST suggest an order based on scope (most far-reaching first)
- AND the system MUST support LIFO (last-in-first-out) as the default ordering strategy

#### Scenario: Detect conflicting amendments

- GIVEN a motion with two amendments that both modify paragraph 3
- WHEN the chair reviews the amendments for voting order
- THEN the system MUST alert: "Amendments A and B both modify paragraph 3 -- voting order determines precedence"
- AND the chair MUST be advised that if amendment A is adopted, amendment B may become moot
- AND the system MUST support marking an amendment as "fallen" (vervallen) if it conflicts with an adopted amendment (Source: intelligence DB story #150)

---

### Requirement: Motion Lifecycle and Status Tracking

The system MUST support motion withdrawal by the proposer before voting. Motions MUST follow a status lifecycle: `draft`, `submitted`, `under_consideration`, `voting`, `adopted`, `rejected`, `withdrawn`, `executed`. Adopted motions MUST be tracked through execution by the responsible party.

**Feature tier**: V1
**Evidence**: Motion lifecycle tracking from submission through execution is the most requested feature for council members; 271 requirements reference decision process (Source: intelligence DB story #146, cluster #43)

#### Scenario: Withdraw a motion before voting

- GIVEN a motion in `submitted` or `under_consideration` status
- WHEN the proposer requests to withdraw the motion
- THEN the status MUST change to `withdrawn`
- AND the withdrawal MUST be recorded in the audit trail
- AND the motion MUST remain visible in the meeting record but marked as withdrawn

#### Scenario: Track motion status throughout lifecycle

- GIVEN a council member who has submitted multiple motions over the past year
- WHEN they view their motion dashboard
- THEN the system MUST display all their motions with current status (draft, submitted, debated, voted, adopted, rejected, executed)
- AND for adopted motions, the system MUST show: assigned executive, deadline, and execution progress (Source: intelligence DB story #146)

#### Scenario: Track adopted motion execution

- GIVEN an adopted motion with an assigned executive and deadline
- WHEN the griffier views the motion execution dashboard
- THEN the system MUST show all adopted motions with: motion text, adoption date, responsible wethouder, deadline, and execution status
- AND the wethouder MUST be able to update execution status with progress notes
- AND overdue motions MUST be flagged for follow-up (Source: intelligence DB stories #178, #179, #180)

---

### Requirement: Proposal Routing and Approval Workflow

The system MUST support routing proposals (voorstellen) through an internal review and approval chain before submission to the governing body. The chain MUST be configurable per organization and proposal type, and MUST support parallel and sequential review steps.

**Feature tier**: V1
**Evidence**: BBV process with 18 process steps from zaak to college to council is the most complex workflow in Dutch municipalities (Source: intelligence DB req #1479, #4183). Multiple tenders specify proposal routing through MT, college, and council (Source: intelligence DB reqs #2830, #3223, #5071)

#### Scenario: Route proposal for internal review

- GIVEN a beleidsadviseur has drafted a council proposal (raadsvoorstel)
- WHEN they submit it for internal review
- THEN the system MUST route it through the configured chain: legal review -> financial review -> management review -> wethouder approval
- AND each reviewer MUST be able to approve, reject, or request changes with comments
- AND the system MUST log who performed each step, when, and how (Source: intelligence DB req #27, #3597)

#### Scenario: Approve proposal for council submission

- GIVEN a proposal that has passed all internal review steps
- WHEN the wethouder approves it for submission to the council
- THEN the proposal MUST be forwarded to the griffie for agenda placement
- AND the proposal MUST include all review comments and approval trail
- AND the system MUST support the "parafencarrousel" (initials carousel) for multi-approver flow (Source: intelligence DB story #156, req #5071)

#### Scenario: Multiple approvers on a single proposal

- GIVEN a proposal requiring approval from both the head of legal and the financial controller
- WHEN both reviewers can review simultaneously (parallel routing)
- THEN the system MUST track both approval statuses independently
- AND the proposal MUST only proceed when all required approvals are obtained (Source: intelligence DB req #30, #3600)

---

### Requirement: Motion Linking and Cross-Referencing

The system MUST support linking motions and amendments to specific agenda items, meetings, and related documents. The system MUST maintain a searchable register of all motions and amendments across meetings.

**Feature tier**: V1
**Evidence**: Tender requirements specify that motions and amendments must be linkable to agenda items and meetings (Source: intelligence DB req #5573). Council members need to search across all documents, decisions, motions, and minutes (Source: intelligence DB story #186)

#### Scenario: Link motion to agenda item

- GIVEN a council member drafting a motion about environmental policy
- WHEN they link the motion to agenda item "Environmental Action Plan 2026"
- THEN the motion MUST appear under that agenda item in the meeting view
- AND when the agenda item is discussed, all linked motions MUST be visible to participants (Source: intelligence DB story #147)

#### Scenario: Search across all motions and amendments

- GIVEN a citizen or journalist wanting to find motions about housing policy
- WHEN they search using the term "woningbouw"
- THEN the system MUST return all motions and amendments containing that term across all meetings
- AND results MUST include: motion title, proposer, status, meeting date, and voting result
- AND the search MUST support filtering by faction, status, and date range

#### Scenario: Export motion register for reporting

- GIVEN a griffier preparing a periodic report on motion execution
- WHEN they request an export of all motions with status and execution data
- THEN the system MUST generate a report with: motion text, proposer, faction, adoption date, responsible executive, deadline, and current status
- AND the export MUST support filtering on status (e.g., all overdue motions) (Source: intelligence DB req #4724)

---

### Requirement: Written Resolution Procedure (Schriftelijk Besluit)

The system MUST support the written resolution procedure for decisions outside meetings. The system MUST enforce BW 2:238 requirements for BVs (unanimous consent to the method) and track all responses.

**Feature tier**: V1
**Legal reference**: BW 2:40 (board decision outside meeting), BW 2:238 (BV written procedure)
**Evidence**: Written resolutions are essential for urgent decisions between meetings (Source: intelligence DB story #68)

#### Scenario: Circulate written resolution to board

- GIVEN an urgent matter requiring board approval between meetings
- WHEN the chair creates a written resolution with text, rationale, and response deadline
- THEN all board members MUST receive a notification with the proposal
- AND each member MUST respond with for/against/abstain before the deadline
- AND for BVs, the system MUST first collect unanimous consent to the written procedure itself
- AND the result MUST be recorded with the same formality as an in-meeting decision

---

### Requirement: Counter-Nomination and Board Election Process

The system MUST support the statutory right of members to submit counter-nominations for board positions per BW 2:37. Counter-nominations MUST follow the same procedural requirements as regular nominations.

**Feature tier**: V1
**Legal reference**: BW 2:37 (right to counter-nomination)

#### Scenario: Submit counter-nomination for board position

- GIVEN a board has proposed a binding nomination for treasurer
- WHEN a member submits a counter-nomination with their own candidate
- THEN the system MUST verify the counter-nomination meets the statutory requirements (submitted within deadline, sufficient support)
- AND both the binding nomination and counter-nomination MUST be presented to voters
- AND the election MUST follow secret ballot rules (Source: intelligence DB story #74)

---

### Requirement: BOB Model Phase Tracking

The system MUST support tagging agenda items with their BOB phase (Beeldvorming/Oordeelsvorming/Besluitvorming) and tracking how topics progress through phases across multiple meetings.

**Feature tier**: V1
**Evidence**: The BOB model is a standard Dutch decision-making framework used in municipalities. Hollands Kroon uses separate BOB-phase meetings (Source: intelligence DB ext #337)

#### Scenario: Track topic through BOB phases

- GIVEN a policy topic "Zonnepanelen op gemeentelijk vastgoed" entering the council process
- WHEN the griffier tags the first meeting as "Beeldvorming" (image-forming)
- THEN the system MUST track the topic's progression: Beeldvorming -> Oordeelsvorming -> Besluitvorming
- AND each phase MUST be linked to the relevant committee/council meeting
- AND the dashboard MUST show all active topics with their current BOB phase (Source: intelligence DB story #341)

## User Stories

### Priority: Must Have

1. **Council member drafting motion from template**: As a raadslid, I want to create a motion using a standard template so that my motion meets all procedural requirements without manual formatting. (Source: intelligence DB #144)
2. **Council member tracking motion status**: As a raadslid, I want to see the current status of all my motions (draft, submitted, debated, voted, adopted, rejected, executed) so that I can follow up on my initiatives. (Source: intelligence DB #146)
3. **Council member linking motion to agenda item**: As a raadslid, I want to link my motion to a specific agenda item so that it is automatically included in the debate on that topic. (Source: intelligence DB #147)
4. **Council member drafting amendment with inline changes**: As a raadslid, I want to select text in a proposal and draft an amendment showing the exact changes (deletions and additions) so that the proposed modification is unambiguous. (Source: intelligence DB #148)
5. **Griffier creating and publishing meeting agenda**: As a griffier, I want to create a meeting agenda by selecting and ordering proposals from the backlog so that council members and the public can see what will be discussed. (Source: intelligence DB #136)
6. **Beleidsadviseur creating standardized proposal**: As a beleidsadviseur, I want to create a council proposal using a standardized template with all required sections so that the griffie can process it efficiently. (Source: intelligence DB #154)
7. **Beleidsadviseur routing proposal for review**: As a beleidsadviseur, I want to route my proposal through the required internal review steps (legal, financial, management) so that it meets all quality requirements before submission to the council. (Source: intelligence DB #155)
8. **Wethouder approving proposal for council**: As a wethouder, I want to approve a prepared proposal for submission to the council so that it enters the formal decision-making process. (Source: intelligence DB #156)
9. **Griffier submitting draft minutes for approval**: As a griffier, I want to submit draft minutes for approval at the next meeting with tracked corrections so that the approval process is transparent. (Source: intelligence DB #177)
10. **Griffier tracking adopted motion execution**: As a griffier, I want to track all adopted motions with assigned deadlines and responsible executive members so that I can report on progress to the council. (Source: intelligence DB #178)
11. **Chair managing amendment voting order**: As a chair, I want to manage the correct voting order for amendments so that the most far-reaching amendment is voted on first per parliamentary procedure. (Source: intelligence DB #311)
12. **Secretary conducting qualified majority vote for statute amendment**: As a board secretary, I want to conduct a 2/3 qualified majority vote so that statute amendments meet the legal threshold per BW 2:42. (Source: intelligence DB #314)
13. **Clerk creating execution tasks for adopted motions**: As a clerk, I want the system to automatically create follow-up tasks when a motion or decision is adopted, assigned to the responsible executive, so that execution is tracked from day one. (Source: intelligence DB #1838)
14. **Auditor tracking decision lifecycle events**: As an auditor, I want every action on a decision (creation, state change, vote cast, document added, amendment submitted) to be logged in the Nextcloud Activity stream so that there is a tamper-evident audit trail. (Source: intelligence DB #1865)

### Priority: Should Have

15. **Council member collecting co-signatures digitally**: As a raadslid, I want to digitally request and collect co-signatures for my motion from other council members so that I can quickly gather the required support. (Source: intelligence DB #145)
16. **Council member previewing amendment result**: As a raadslid, I want to preview what the proposal text looks like after my amendment is applied so that I can verify my changes have the intended effect. (Source: intelligence DB #149)
17. **Griffier detecting conflicting amendments**: As a griffier, I want to be alerted when multiple amendments target the same text passage so that I can advise the chair on voting order. (Source: intelligence DB #150)
18. **Commissiegriffier collecting executive proposals**: As a commissiegriffier, I want to receive proposals from the college in a standardized format so that I can efficiently process them into the agenda. (Source: intelligence DB #139)
19. **Raadslid viewing motion dashboard**: As a raadslid, I want to see a dashboard showing all adopted motions with their current status so that I can hold the executive accountable for follow-up. (Source: intelligence DB #179)
20. **Wethouder updating motion execution status**: As a wethouder, I want to update the execution status of motions assigned to me so that the council has current information about progress. (Source: intelligence DB #180)
21. **Council secretary tracking BOB phases**: As a council secretary, I want to tag each meeting or agenda item with its BOB phase and track how topics progress through phases across multiple meetings. (Source: intelligence DB #341)
22. **Stakeholder receiving calendar entries for deadlines**: As a stakeholder, I want voting deadlines, amendment submission deadlines, and public comment periods to automatically appear in my calendar so that I never miss a deadline. (Source: intelligence DB #1829)
23. **Council member tracking amendment versions with diff**: As a council member, I want to see all versions of a proposal/amendment with clear diffs between versions so that I can understand what changed at each stage of the deliberation process. (Source: intelligence DB #1845)

### Priority: Could Have

24. **Rapporteur managing large amendment sets**: As a rapporteur, I want tools to manage and organize hundreds of amendments on a single file so that I can efficiently negotiate compromise amendments across political groups. (Source: intelligence DB #205)
25. **Lobbyist tracking policy proposals**: As a belangenbehartiger, I want to track specific policy proposals as they move through the decision-making process across multiple democratic bodies so that I can provide timely input at each stage. (Source: intelligence DB #211)
26. **Member submitting counter-nomination**: As a member, I want to submit a counter-nomination for a board position against the board's binding nomination so that I can exercise my democratic right per BW 2:37. (Source: intelligence DB #74)
27. **Member submitting motion for ALV agenda**: As a member, I want to submit a motion or proposal for the ALV agenda with supporting arguments so that my topic is formally discussed and voted on. (Source: intelligence DB #54)
28. **MT member submitting agenda item with documents**: As an MT member, I want to submit agenda items with supporting documents through a structured form so that the secretary can compile a complete and well-organized agenda. (Source: intelligence DB #87)
29. **Management assistant compiling MT agenda**: As a management assistant, I want to compile submitted agenda items into a structured agenda and distribute the complete package to all MT members so that everyone is prepared for the meeting. (Source: intelligence DB #88)
30. **User embedding decision references via Smart Picker**: As a user composing an email or document in Nextcloud, I want to use the Smart Picker to search and embed a reference to a specific decision, motion, or meeting so that recipients can click through to the full record. (Source: intelligence DB #1874)

## Competitor Analysis

| Competitor | Motion/Amendment Features | Strengths | Gaps |
|---|---|---|---|
| **OpenSlides** | Create, amend, discuss, and vote on motions; amendment workflow | Purpose-built for assemblies; strong motion-vote pipeline; drag-and-drop amendment ordering | No proposal routing; no BBV process; no execution tracking |
| **Notubiz** | Council instruments: motions, amendments, votes; NotuBiz minutes | Dominant Dutch council market; integrated with meeting recordings | Closed platform; poor search UX; limited amendment diff view |
| **Parlaeus** | Full BBV process; proposal routing; council member profiles | Strong integration with municipal workflow; Apeldoorn case study | Proprietary; limited to Dutch councils; no association/corporate support |
| **Decidim** | Proposals, versioned texts, participatory legislation | Citizen-facing proposal system; versioned collaborative texts | No formal parliamentary procedure; no amendment voting order; heavy platform |
| **Loomio** | Proposals with agree/disagree/abstain/block; threaded discussion | Good for collaborative deliberation; supports 30+ languages | No formal motion types; no amendment diff; no parliamentary procedure |
| **GO Raadsinformatie** | Document management; publish council documents and decisions | Market presence in Dutch councils; document-focused | No real-time motion submission; limited amendment workflow |

(Sources: intelligence DB competitors #707, #601, #620, #699, #685, #585)

## Acceptance Criteria

- Motions are stored as OpenRegister objects with proposer, co-signers, rationale, and type classification
- Dutch motion types are supported: motie, motie van orde, motie van wantrouwen, amendement, initiatiefvoorstel
- Motion templates follow Reglement van Orde format with required fields (title, dictum, overwegingen)
- Co-signature collection is digital with real-time status tracking
- Amendments show a diff view of proposed text changes (additions/removals)
- Amendment conflict detection alerts when multiple amendments target the same text
- Amendment voting precedes main motion voting (parliamentary rule)
- Chair can set amendment voting order; system suggests most-far-reaching-first
- Proposal routing supports configurable review chains (legal, financial, management, wethouder)
- Multi-approver flows support both parallel and sequential routing
- Motion lifecycle follows defined status transitions (draft through executed)
- Adopted motion execution is tracked with assigned responsible party, deadline, and progress
- Motion withdrawal is supported and recorded in audit trail
- Motions and amendments are linkable to agenda items and meetings
- Searchable register of all motions with filtering by faction, status, and date
- BOB model phase tracking across multiple meetings
- OpenRaadsinformatie `Motie`/`Amendement` mapping is available
- Every lifecycle event is logged in Nextcloud Activity stream
- Written resolution procedure supports BW 2:238 consent-to-method for BVs
- Counter-nominations are supported per BW 2:37
