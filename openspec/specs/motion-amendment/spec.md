---
status: partial
status-note: 2026-06-12 audit — 2/4 requirements built via motion-management, motion-status-management, amendment-workflow, amendment-diff (conflict detection) capabilities. Missing — visual amendment diff view, chair-controlled amendment voting order, co-signer threshold validation, submission deadline enforcement.
---

# Motion and Amendment Specification

## Purpose

Motions and amendments are the formal mechanisms for proposing decisions and modifying proposals before a vote. A motion is a formal proposal submitted by a member for consideration by the governing body. An amendment is a proposed modification to a pending motion. The system supports motion submission, amendment drafting, amendment voting order (amendments voted before the main motion), and the parliamentary procedure for handling competing amendments.

**Standards**: Akoma Ntoso (`bill`, `amendment`, `motion`), Schema.org (`Action`, `ReplaceAction`), OpenRaadsinformatie (`Motie`, `Amendement`)
**Feature tier**: V1
**Legal reference**: Gemeentewet 147a (right to submit motions), Reglement van Orde (rules of procedure)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Motion and Amendment entity definitions including property tables, Akoma Ntoso alignment, and OpenRaadsinformatie mapping.

## Requirements

---

### Requirement: Motion Submission

The system MUST support submitting motions with a title, body text, proposer, co-signers, and rationale. Motions MUST follow the governing body's rules for submission (e.g., minimum co-signers, submission deadline). Motions MUST be stored as OpenRegister objects in the `decidesk` register using the `motion` schema.

**Feature tier**: V1

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

---

### Requirement: Amendment Drafting and Submission

The system MUST support creating amendments to pending motions. Amendments MUST clearly show what text is being added, removed, or modified (diff view). Multiple amendments to the same motion MUST be supported.

**Feature tier**: V1

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

---

### Requirement: Amendment Voting Order

The system MUST enforce the parliamentary rule that amendments are voted on before the main motion. When multiple amendments exist, the most far-reaching amendment MUST be voted on first. The chair MUST be able to set the voting order.

**Feature tier**: V1

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

---

### Requirement: Motion Withdrawal and Status

The system MUST support motion withdrawal by the proposer before voting. Motions MUST follow a status lifecycle: `draft`, `submitted`, `under_consideration`, `voting`, `adopted`, `rejected`, `withdrawn`.

**Feature tier**: V1

#### Scenario: Withdraw a motion before voting

- GIVEN a motion in `submitted` or `under_consideration` status
- WHEN the proposer requests to withdraw the motion
- THEN the status MUST change to `withdrawn`
- AND the withdrawal MUST be recorded in the audit trail
- AND the motion MUST remain visible in the meeting record but marked as withdrawn

## User Stories

1. **Member submitting a motion**: As a member, I want to submit a motion or proposal for the ALV agenda with supporting arguments so that my topic is formally discussed and voted on. (Source: intelligence DB #54)

2. **Secretary recording board decisions with votes**: As secretary, I want to record each board decision with the vote distribution per board member so that we comply with WBTR documentation requirements. (Source: intelligence DB #66)

3. **Secretary preparing structured approval request**: As a board secretary, I want to prepare structured approval requests with all required information, so that the supervisory board can make informed decisions efficiently. (Source: intelligence DB #26)

## Acceptance Criteria

- Motions are stored as OpenRegister objects with proposer, co-signers, and rationale
- Amendments show a diff view of proposed text changes
- Amendment voting precedes main motion voting (parliamentary rule)
- Chair can set amendment voting order
- Motion lifecycle follows defined status transitions
- Motion withdrawal is supported and recorded
- OpenRaadsinformatie `Motie`/`Amendement` mapping is available
