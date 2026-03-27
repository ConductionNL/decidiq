---
status: idea
---

# Voting System Specification

## Purpose

The voting system is Decidesk's most critical feature. It supports multiple voting methods (open vote, secret ballot, roll call, weighted voting, ranked choice, dot voting, quadratic voting, consent-based, approval voting, score polling), real-time ballot casting and result calculation, quorum-aware majority thresholds, proxy vote handling, and configurable voting rules per governing body. The system ensures legally compliant voting for associations (ALV), corporate boards (BV/NV), government councils, citizen participation, and operational teams.

**Standards**: Schema.org (`VoteAction`, `ChooseAction`), Akoma Ntoso (`voting`, `count`), OpenRaadsinformatie (`Stemming`, `Stem`), CoE CM/Rec(2017)5 (e-voting standards)
**Feature tier**: MVP
**Legal reference**: BW 2:38 (ALV voting), BW 2:230 (BV shareholder voting), BW 2:42 (statute amendment 2/3), BW 2:18 (dissolution 2/3), Gemeentewet 20 (quorum), 29 (second meeting without quorum), 30 (absolute majority), 31 (secret ballot), 32 (roll call), WBTR (documentation requirements), WDAV (digital meetings)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Vote and VotingRound entity definitions including property tables, Schema.org mappings, and OpenRaadsinformatie alignment.

## Evidence Base

This specification is informed by **345 user stories**, **178 requirements** from Dutch government tenders, and **50 external sources** from the intelligence database. Key evidence clusters:

- **Requirement cluster #43**: "Besluitvorming (decision process)" -- 271 requirements across 133 tenders (Source: intelligence DB cluster #43)
- **Council of Europe CM/Rec(2017)5**: 49 standards in three-tiered structure for e-voting (principles, recommendations, standards) (Source: intelligence DB ext #258)
- **Gemeentewet Art. 20/29/30/31/32**: Dutch municipal voting law codified as non-negotiable system requirements (Source: intelligence DB ext #273, #292)
- **BW 2:230/238**: Configurable majority and written procedure for BVs (Source: intelligence DB ext #293)
- **Competitor analysis**: Loomio (7 voting types), OpenSlides (4 modes), Decidim (encrypted e-voting), POLYAS (BSI-certified), Belenios (cryptographic verification), ElectionBuddy (multi-ballot) (Source: intelligence DB competitors)
- **Market sizing**: European participation market 300M EUR, e-voting 500M EUR expected within 5 years (Source: intelligence DB insight #23, ext #87)

## Requirements

---

### Requirement: Open Vote (For/Against/Abstain)

The system MUST support open (public) voting where each participant casts a for, against, or abstain vote. Results MUST be displayed in real-time. The vote of each participant MUST be recorded and visible in the minutes.

**Feature tier**: MVP
**Evidence**: 47 user stories reference open voting; Gemeentewet Art. 30 mandates this as the default method for council decisions (Source: intelligence DB ext #292)

#### Scenario: Conduct an open vote on an agenda item

- GIVEN a meeting with quorum met and an active agenda item of type "decision"
- WHEN the chair initiates an open vote
- THEN each eligible member MUST see a voting panel with "For", "Against", and "Abstain" buttons
- AND the system MUST display the running tally in real-time
- AND once all members have voted (or the chair closes voting), the result MUST be calculated
- AND the result (adopted/rejected) MUST be announced based on the configured majority rule

#### Scenario: View individual votes after an open vote

- GIVEN an open vote has been completed
- WHEN a user views the voting results
- THEN the system MUST display how each member voted (for/against/abstain)
- AND the results MUST be recorded in the decision audit trail
- AND the results MUST be mappable to OpenRaadsinformatie `Stemming`/`Stem` format

#### Scenario: Reject a vote when quorum is lost mid-meeting

- GIVEN a meeting where quorum was initially met but members have since left
- WHEN the chair attempts to start a new vote
- THEN the system MUST recalculate quorum from current attendance
- AND if quorum is no longer met, voting MUST be blocked with a quorum warning
- AND the system MUST offer the option to adjourn per Gemeentewet Art. 29 (second meeting without quorum)

---

### Requirement: Secret Ballot

The system MUST support secret (anonymous) voting where individual votes are not linked to voters in the results. Secret ballots MUST be used for board elections and other votes where the chair or statutes require anonymity.

**Feature tier**: MVP
**Legal reference**: BW 2:38 (election by secret ballot), Gemeentewet 31 (secret ballot requirements)
**Evidence**: Competitors Belenios (ElGamal homomorphic encryption, ZK proofs), POLYAS (BSI Common Criteria certified) set the security bar (Source: intelligence DB ext #269, #270)

#### Scenario: Conduct a secret ballot for board election

- GIVEN a meeting with an agenda item "Board Election -- Treasurer"
- WHEN the chair initiates a secret ballot
- THEN each eligible member MUST see a voting panel with candidate options
- AND individual votes MUST NOT be linked to voters in the stored results
- AND only aggregate totals (votes per candidate) MUST be recorded
- AND the system MUST verify that the total vote count matches the number of eligible voters

#### Scenario: Verify vote count integrity for secret ballot

- GIVEN a secret ballot has been completed with 12 eligible voters
- WHEN the results are tallied
- THEN the total number of votes MUST equal exactly 12
- AND if a discrepancy is detected, the system MUST flag it for the chair
- AND the system SHOULD provide E2E verifiability: cast-as-intended, recorded-as-cast (Source: intelligence DB ext #261)

#### Scenario: Secret ballot for council appointments per Gemeentewet Art. 31

- GIVEN a council meeting with a personnel appointment on the agenda
- WHEN the chair initiates voting on the appointment
- THEN the system MUST enforce secret ballot mode (non-overridable for appointments)
- AND blank votes MUST be counted separately from abstentions
- AND the system MUST support multiple rounds if no candidate achieves majority

---

### Requirement: Roll Call Vote (Hoofdelijke Stemming)

The system MUST support roll call voting where each member is called individually to declare their vote. The starting position MUST be randomized to prevent order bias. Roll call voting is mandatory when requested by the chair or any council member per Gemeentewet Art. 32.

**Feature tier**: MVP
**Legal reference**: Gemeentewet Art. 32
**Evidence**: Parlaeus, Notubiz, and iBabs all support roll call with automated speaker identification (Source: intelligence DB competitors #601, #623, #605)

#### Scenario: Conduct a roll call vote with randomized order

- GIVEN a council meeting where a member requests a roll call vote
- WHEN the chair initiates a roll call vote (hoofdelijke stemming)
- THEN the system MUST generate a randomized member order
- AND each member MUST be called individually to declare "for", "against", or "abstain"
- AND the system MUST record each vote with timestamp in declaration order
- AND results MUST show the full voting sequence

#### Scenario: Handle tie in roll call vote per Gemeentewet Art. 32

- GIVEN a roll call vote on a non-personnel matter resulting in a tie
- WHEN all members have voted and the result is equal for/against
- THEN if the meeting is not full (not all seated members present), the vote MUST be postponed to the next meeting
- AND if the meeting IS full (all seated members present), the proposal MUST be declared rejected

---

### Requirement: Qualified Majority and Voting Rules

The system MUST support configurable majority rules: simple majority (50%+1), absolute majority (>50% of all members), qualified majority (e.g., 2/3, 3/4), unanimous, and weighted voting. Abstentions MUST be configurable as counting toward total or excluded from calculation. The system MUST support DMN-inspired decision tables for configurable voting rules per organization.

**Feature tier**: MVP
**Legal reference**: BW 2:42 (statute amendment requires 2/3), BW 2:18 (dissolution requires 2/3), BW 2:230 (default absolute majority)
**Evidence**: DMN decision tables recommended for modeling voting rules per organization (Source: intelligence DB insight #13, ext #283)

#### Scenario: Verify qualified majority for statute amendment

- GIVEN a vote on statute amendment requiring 2/3 majority of votes cast
- WHEN 20 members vote: 14 for, 5 against, 1 abstain
- THEN the system MUST calculate: 14/(14+5) = 73.7% (abstentions excluded from calculation)
- AND the result MUST be "adopted" (73.7% >= 66.7%)
- AND the system MUST record the required threshold alongside the result

#### Scenario: Verify quorum requirement for statute amendment vote

- GIVEN a statute amendment vote requiring 2/3 of members present
- WHEN only 8 of 15 members are present (53%)
- THEN the system MUST block the vote with a message: "Quorum not met. Statute amendment requires 2/3 of members present (10 required, 8 present)."

#### Scenario: Handle a tie vote

- GIVEN a simple majority vote where 10 for and 10 against
- WHEN the votes are tallied
- THEN the system MUST declare the result as "tied"
- AND the system MUST apply the configured tie-breaking rule (e.g., chair's casting vote, motion fails, revote, or lot for persons per BW 2:230)

#### Scenario: Configure custom majority rules via decision table

- GIVEN a governing body with custom statutes requiring 3/4 majority for asset disposal
- WHEN the administrator configures the voting rules
- THEN the system MUST allow creating a decision table mapping: decision type -> required majority, required quorum, abstention handling, tie-breaking rule
- AND the configured rules MUST be applied automatically when a matching vote is initiated

---

### Requirement: Proxy Voting (Volmacht)

The system MUST support digital proxy voting (volmacht) where a member authorizes another member to vote on their behalf. Proxy votes MUST be verifiable and count toward both quorum and voting. The system MUST enforce configurable limits on proxies per member.

**Feature tier**: MVP
**Legal reference**: BW 2:227 (shareholder proxy), BW 2:38 (ALV proxy per statutes)
**Evidence**: Broadridge processes global proxy voting with push notifications, facial recognition, and synchronized multi-channel voting (Source: intelligence DB ext #265). Lumi Global combines live + proxy + pre-meeting votes (Source: intelligence DB ext #264)

#### Scenario: Grant and exercise a digital proxy

- GIVEN member A cannot attend the ALV and grants a proxy to member B
- WHEN member B votes on a decision item
- THEN the system MUST prompt member B to cast their own vote AND the proxy vote separately
- AND both votes MUST be recorded (member B's own vote and member A's proxy vote)
- AND the results MUST show the total including proxy votes
- AND the proxy grant MUST be stored with timestamp and digital confirmation

#### Scenario: Limit proxy votes per member

- GIVEN the statutes allow a maximum of 2 proxies per member
- WHEN member B already holds 2 proxies and member C attempts to grant a proxy to member B
- THEN the system MUST reject the proxy with a message indicating the maximum has been reached

#### Scenario: Submit proxy vote before AGM

- GIVEN a shareholder AGM with resolution items published in the convocation
- WHEN a shareholder submits proxy votes before the meeting date
- THEN each resolution item MUST allow a for/against/abstain proxy vote
- AND proxy votes MUST be combinable with live votes (overridable if shareholder attends)
- AND the system MUST support split voting for institutional investors with multiple fund mandates (Source: intelligence DB story #319)

---

### Requirement: Remote Voting in Digital/Hybrid Meetings

The system MUST support real-time voting for remote participants in digital and hybrid meetings. Remote votes MUST have equal weight to in-person votes. The system MUST ensure vote integrity through session verification.

**Feature tier**: MVP
**Legal reference**: WDAV (Wet Digitale Algemene Vergadering, passed Tweede Kamer Dec 2025) (Source: intelligence DB ext #21)
**Evidence**: 71% of Dutch municipal clerks want digital meetings to continue post-COVID (Source: intelligence DB ext #140)

#### Scenario: Cast vote remotely during hybrid meeting

- GIVEN a hybrid meeting where member is attending remotely
- WHEN the chair initiates a vote
- THEN the remote member MUST see the same voting panel as in-person attendees
- AND their vote MUST be counted with equal weight
- AND their attendance mode (remote) MUST be recorded alongside their vote
- AND identity verification MUST confirm the remote voter is the authenticated member

#### Scenario: Vote by email reply for non-platform users

- GIVEN a member who does not use the Decidesk platform regularly
- WHEN a voting notification is sent via email
- THEN the member SHOULD be able to reply with "For", "Against", or "Abstain"
- AND the system MUST verify the reply came from the registered email address
- AND the vote MUST be recorded with "email" as the channel (Source: intelligence DB stories #1814-1816)

---

### Requirement: Weighted Voting

The system MUST support weighted voting where votes carry different weights based on share ownership, contribution level, or other configurable criteria. The system MUST calculate results using weighted tallies.

**Feature tier**: MVP
**Legal reference**: BW 2:228 (share-proportional voting for NV/BV)
**Evidence**: Lumi Global and ConveneAGM both support real-time weighted voting for shareholder meetings (Source: intelligence DB ext #264, #161). Decidim supports cost-based and budget-weighted voting (Source: intelligence DB ext #267)

#### Scenario: Conduct weighted shareholder vote at AGM

- GIVEN an AGM with shareholders holding different numbers of shares (A: 1000, B: 500, C: 250)
- WHEN a resolution is put to vote and all three vote "for"
- THEN the system MUST calculate: 1750 weighted votes for (100%)
- AND individual vote weights MUST be displayed alongside the result

#### Scenario: Handle split votes for institutional investors

- GIVEN an institutional investor holding shares across multiple fund mandates
- WHEN they wish to vote different directions per mandate
- THEN the system MUST allow splitting the total weight across for/against/abstain
- AND the split MUST be validated to equal the total holding (Source: intelligence DB story #319)

---

### Requirement: Ranked Choice Voting (Preferential)

The system MUST support ranked choice voting where voters rank options in order of preference. The system MUST implement Instant Runoff Voting (IRV) for single-winner elections and support configurable elimination algorithms.

**Feature tier**: V1
**Evidence**: Stanford Encyclopedia analysis of voting methods shows IRV and Condorcet agree >95% of the time (Source: intelligence DB ext #251, #252). Loomio supports drag-and-drop ranked choice (Source: intelligence DB ext #266). ElectionBuddy supports preferential/STV and Borda count (Source: intelligence DB ext #263)

#### Scenario: Conduct ranked choice vote for venue selection

- GIVEN an MT meeting deciding between 4 venue options
- WHEN each member ranks the options 1st through 4th
- THEN the system MUST apply IRV: eliminate lowest first-choice, redistribute preferences
- AND continue rounds until one option exceeds 50%
- AND display round-by-round elimination results

#### Scenario: Use ranked choice for board election with multiple seats (STV)

- GIVEN a board election with 3 open positions and 7 candidates
- WHEN members rank all candidates
- THEN the system MUST apply Single Transferable Vote (STV) with configurable quota (Droop or Hare)
- AND surplus votes from elected candidates MUST be redistributed fractionally
- AND elected candidates MUST be announced in order of election

---

### Requirement: Dot Voting (Budget/Point Allocation)

The system MUST support dot voting where participants distribute a fixed number of points across multiple options. This supports prioritization, backlog management, and participatory budgeting.

**Feature tier**: V1
**Evidence**: Loomio supports dot voting with budget allocation (Source: intelligence DB ext #266). Decidim uses budget-limited voting for participatory budgeting (Source: intelligence DB ext #267). Research shows participants prefer expressive voting formats for budget decisions (Source: intelligence DB ext #277)

#### Scenario: Prioritize backlog items with dot voting

- GIVEN 10 backlog items and each team member has 5 dots to distribute
- WHEN team members allocate their dots (max 3 per item)
- THEN the system MUST tally total dots per item
- AND rank items by total allocation
- AND display a visual heat map of point distribution (Source: intelligence DB story #321)

#### Scenario: Participatory budget allocation with cost constraints

- GIVEN a participatory budget of 100,000 EUR with 8 project proposals of varying costs
- WHEN citizens allocate their budget tokens to projects
- THEN the system MUST enforce that selected projects do not exceed the total budget
- AND apply the "method of equal shares" for proportional allocation (Source: intelligence DB ext #277)
- AND display which projects are funded and unfunded (Source: intelligence DB story #326)

---

### Requirement: Quadratic Voting

The system MUST support quadratic voting where participants buy votes at quadratic cost (1 vote = 1 credit, 2 votes = 4 credits, 3 votes = 9 credits). This allows expressing intensity of preference while preventing tyranny of the majority.

**Feature tier**: V2
**Evidence**: Seminal research proposes quadratic voting for corporate governance, preventing majority tyranny while capturing preference intensity (Source: intelligence DB ext #254). Used in participatory democracy for multi-issue prioritization (Source: intelligence DB story #329)

#### Scenario: Run quadratic voting for multi-issue prioritization

- GIVEN 5 policy proposals and each citizen has 25 credits
- WHEN a citizen allocates 9 credits (3 votes) to proposal A, 4 credits (2 votes) to proposal B, and 1 credit each to C, D, E
- THEN the system MUST validate total credits used (9+4+1+1+1 = 16 <= 25)
- AND aggregate all votes per proposal with quadratic weighting
- AND rank proposals by total votes received

---

### Requirement: Consent-Based Decision-Making (Sociocracy)

The system MUST support consent-based decision-making following sociocratic principles. In consent, a decision is adopted when no participant has a "paramount objection" -- it does not require agreement, only the absence of reasoned objections.

**Feature tier**: V1
**Evidence**: Sociocracy For All defines the process: proposal -> question round -> reaction round -> objection round -> integration (Source: intelligence DB ext #256). Consent is faster than consensus for organizations, avoids gridlock (Source: intelligence DB ext #257)

#### Scenario: Conduct consent-based decision round

- GIVEN a proposal presented to a team using consent-based process
- WHEN the facilitator initiates a consent round
- THEN the system MUST guide through phases: (1) Proposal presentation, (2) Clarifying questions, (3) Quick reactions, (4) Objection round, (5) Integration
- AND each phase MUST have a configurable timer
- AND if no objections are raised, the proposal MUST be marked "adopted by consent"
- AND if objections are raised, the system MUST capture the objection text and trigger integration discussion (Source: intelligence DB stories #324, #346)

#### Scenario: Record consent decision without formal vote counting

- GIVEN a supervisory board using consent-based decision-making
- WHEN the chair concludes a consent round with no objections
- THEN the system MUST record the decision as "adopted by consent" (not by vote count)
- AND the record MUST include who participated and that no objections were raised (Source: intelligence DB story #320)

---

### Requirement: Approval Voting and Score Polling

The system MUST support approval voting (vote for as many options as you approve of) and score polling (rate each option on a numeric scale). These methods capture nuanced preferences for multi-option decisions.

**Feature tier**: V1
**Evidence**: ElectionBuddy supports approval, scoring, and rating scale ballots (Source: intelligence DB ext #263). Loomio score polls use 0-9 per option (Source: intelligence DB ext #266)

#### Scenario: Evaluate vendor proposals with score polling

- GIVEN 4 vendor proposals to evaluate across 5 criteria
- WHEN each MT member scores each proposal 0-10 per criterion
- THEN the system MUST calculate weighted average scores per proposal
- AND rank proposals by aggregate score
- AND display score distribution per criterion (Source: intelligence DB story #323)

#### Scenario: Approval voting for committee membership

- GIVEN 8 candidates for 4 committee seats
- WHEN each voter approves as many candidates as they wish
- THEN the top 4 candidates by approval count MUST be elected
- AND the system MUST handle ties via configurable tiebreaker

---

### Requirement: Asynchronous (Written) Decision-Making

The system MUST support decision-making outside meetings via written procedure (schriftelijke besluitvorming). For BVs, written procedure requires unanimous consent to the METHOD (not the decision itself) per BW 2:238.

**Feature tier**: MVP
**Legal reference**: BW 2:40 (board decision outside meeting), BW 2:238 (BV written procedure)
**Evidence**: BoardPro captures decisions from "flying minutes" (Source: intelligence DB ext #114). Loomio enables asynchronous proposals with deadline-based voting (Source: intelligence DB ext #118)

#### Scenario: Circulate written resolution for board decision outside meeting

- GIVEN a chair wants to make an urgent decision between meetings
- WHEN they circulate a proposal to all board members with a response deadline
- THEN each member MUST receive a notification with the proposal text
- AND each member MUST cast a for/against/abstain vote before the deadline
- AND the proposal MUST be adopted only if the configured majority is met
- AND for BVs, the system MUST first collect consent to the written procedure before collecting votes on the substance (Source: intelligence DB story #68)

#### Scenario: Extend voting deadline for asynchronous decision

- GIVEN an asynchronous vote with 2 of 5 board members not yet responded and deadline approaching
- WHEN the chair extends the deadline by 48 hours
- THEN the system MUST notify outstanding voters of the extension
- AND the original votes MUST remain valid

---

### Requirement: Consent Agenda (Hamerstukken)

The system MUST support processing consent agenda items (hamerstukken) as a batch without individual debate or voting. Any member MUST be able to pull an item from the consent agenda for individual discussion.

**Feature tier**: MVP
**Legal reference**: Standard Dutch council procedure
**Evidence**: Hamerstuk/bespreekstuk classification is a core Dutch council pattern (Source: intelligence DB stories #158, #164)

#### Scenario: Process consent agenda items in batch

- GIVEN a council meeting with 8 hamerstukken on the consent agenda
- WHEN the chair asks "Are there any items to be removed from the consent agenda?"
- AND no member objects
- THEN all 8 items MUST be marked as "adopted without debate" in a single action
- AND each item MUST receive an individual decision record

#### Scenario: Pull item from consent agenda for debate

- GIVEN a consent agenda with 8 items
- WHEN a council member requests item 5 be discussed separately
- THEN item 5 MUST be moved to the regular agenda for debate and individual vote
- AND the remaining 7 items MUST proceed as hamerstukken

---

### Requirement: Decision Point Activation from Agenda [MVP]

The system MUST automatically activate the voting interface when the chair advances to an agenda item that has a `decisionPoint` (linked Motion, Amendment, or Vote). The chair MUST NOT need to manually open the voting panel for decision-point items. Amendments linked to a decision point MUST be voted on before the main motion, following standard parliamentary procedure.

**Cross-reference**: See agenda-management spec -- AgendaItem `decisionPoint` field links to Motion/Amendment/Vote. The live meeting page (meeting-management spec) displays the voting panel when a decision-point item is active.

#### Scenario: Voting interface activates when chair reaches decision-point agenda item

- GIVEN a meeting in progress and agenda item 7 "Vaststellen begroting 2027" has a linked decisionPoint (a Motion to approve the budget)
- WHEN the chair advances the meeting to agenda item 7
- THEN the voting interface MUST activate automatically for all eligible participants
- AND the motion text and any supporting documents MUST be displayed alongside the voting panel
- AND the chair MUST be able to open debate before starting the formal vote

#### Scenario: Amendments are voted before the main motion

- GIVEN agenda item 7 has a main motion and 2 linked amendments (Amendment A and Amendment B)
- WHEN the chair reaches item 7 and starts the voting sequence
- THEN the system MUST present amendments for voting first, in submission order (Amendment A, then Amendment B)
- AND after all amendments are resolved (adopted or rejected), the main motion (as possibly amended) MUST be put to vote
- AND the final motion text MUST reflect any adopted amendments before the main vote

#### Scenario: Vote result updates agenda item status in real-time

- GIVEN a vote has been conducted on agenda item 7's decision point
- WHEN the vote result is calculated (adopted or rejected)
- THEN the agenda item's status MUST update to "decided" in real-time on all participants' live meeting pages
- AND the decision outcome (adopted/rejected with vote counts) MUST be recorded on the agenda item
- AND the chair MUST see a summary before advancing to the next agenda item

#### Scenario: Non-decision agenda item does not trigger voting

- GIVEN a meeting in progress and agenda item 3 "Mededelingen" has type "informational" with no decisionPoint
- WHEN the chair advances to agenda item 3
- THEN the voting interface MUST NOT activate
- AND the chair MUST retain the ability to manually initiate an ad-hoc vote if needed

---

### Requirement: Quorum Tracking and Enforcement

The system MUST continuously track attendance and calculate quorum in real-time. Quorum rules MUST be configurable per governing body. The system MUST block voting when quorum is not met and provide alerts when quorum is at risk.

**Feature tier**: MVP
**Legal reference**: Gemeentewet Art. 20 (council quorum >50%), Art. 56 (B&W quorum >=50%), BW 2:38 (ALV quorum per statutes)
**Evidence**: iBabs and Notubiz both provide quorum tracking with RSVP management (Source: intelligence DB ext #97). 271 requirements across 133 tenders reference decision process support (Source: intelligence DB cluster #43)

#### Scenario: Real-time quorum monitoring during meeting

- GIVEN a council meeting requiring >50% of 35 seated members (>17 required)
- WHEN 19 members are present and 1 member leaves
- THEN the system MUST update the quorum indicator to "18/18 required -- AT RISK"
- AND send an alert to the chair
- AND if another member leaves, voting MUST be blocked

#### Scenario: Track attendance patterns for quorum analytics

- GIVEN historical attendance data across multiple meetings
- WHEN the secretary views attendance analytics
- THEN the system MUST show per-member attendance rate, average quorum percentage, and meetings where quorum was at risk
- AND identify patterns (e.g., "Member X has attended 4 of last 10 meetings") (Source: intelligence DB story #342)

---

### Requirement: Voting Audit Trail and Transparency

The system MUST maintain a tamper-evident audit trail of all voting actions. All vote results MUST be publishable for public transparency. The system MUST support the three E2E verifiability properties where applicable.

**Feature tier**: MVP
**Evidence**: CoE CM/Rec(2017)5 defines 49 standards including auditability and verifiability (Source: intelligence DB ext #258). OSCE guidelines warn about voter coercion risks in remote settings (Source: intelligence DB ext #259). Every state change must be logged in Nextcloud Activity stream (Source: intelligence DB insight #25)

#### Scenario: Publish council vote results for citizen transparency

- GIVEN a completed council vote
- WHEN the clerk publishes the results
- THEN the system MUST generate a public-facing view showing: agenda item, vote type, per-member positions, totals, and outcome
- AND the data MUST be available via API in OpenRaadsinformatie format
- AND citizens MUST be able to search voting history by topic or member (Source: intelligence DB stories #168, #187)

#### Scenario: Generate complete audit package for notarial deed

- GIVEN a statute amendment adopted by qualified majority
- WHEN the notary requests proof of proper adoption
- THEN the system MUST produce: convocation proof (with delivery receipts), quorum verification log, individual voting records, and the resolution text
- AND the package MUST include cryptographic integrity verification (Source: intelligence DB story #78)

---

### Requirement: Voting UX and Accessibility

The system MUST provide an accessible, mobile-friendly voting interface that meets WCAG AA standards. The voting UX MUST minimize errors and provide clear confirmation of cast votes.

**Feature tier**: MVP
**Evidence**: UX research shows mobile-first design boosts participation up to 3x; key principles include clean fonts >=12pt, high contrast ratio, vote confirmation builds trust (Source: intelligence DB ext #278). ElectionBuddy provides one-click voting via personal secure invitations (Source: intelligence DB ext #16)

#### Scenario: Cast vote on mobile device

- GIVEN a member accessing the voting panel on a mobile phone
- WHEN they tap their vote choice
- THEN the system MUST display a confirmation dialog: "You voted: FOR. Confirm?"
- AND after confirmation, display a receipt: "Vote recorded at [timestamp]"
- AND the interface MUST meet WCAG AA contrast ratios and support screen readers

#### Scenario: Voting panel accessibility for keyboard-only users

- GIVEN a member using keyboard-only navigation
- WHEN the voting panel is active
- THEN all vote options MUST be reachable via Tab key
- AND the current selection MUST have a visible focus indicator
- AND Enter/Space MUST activate the selected option

## User Stories

### Priority: Must Have

1. **Chair conducting open vote**: As chair, I want to conduct an open vote (for/against/abstain) on an agenda item and see results in real-time so that I can announce the outcome immediately. (Source: intelligence DB #57)
2. **Chair initiating roll call vote**: As a voorzitter, I want to conduct a roll call vote (hoofdelijke stemming) with randomized starting position so that the voting order does not always favor the same factions. (Source: intelligence DB #166)
3. **Clerk recording individual voting records**: As a commissiegriffier, I want to automatically record how each member voted on each item so that voting records are complete and accurate. (Source: intelligence DB #167)
4. **Council member casting electronic vote**: As a council member, I want to cast my vote electronically during a plenary session so that voting is faster and results are immediately visible. (Source: intelligence DB #308)
5. **Clerk publishing vote results**: As a clerk, I want to publish vote results including each member's position so that citizens can verify how their representatives voted. (Source: intelligence DB #309)
6. **Board chair verifying ALV quorum**: As a board chair, I want to verify quorum before opening voting so that all decisions taken are legally valid per BW 2:38 and our statutes. (Source: intelligence DB #312)
7. **Member delegating vote via proxy**: As a member, I want to delegate my vote to another member via proxy so that my voice is counted even when I cannot attend the ALV. (Source: intelligence DB #313)
8. **Secretary conducting qualified majority vote**: As a board secretary, I want to conduct a 2/3 qualified majority vote so that statute amendments meet the legal threshold per BW 2:42. (Source: intelligence DB #314)
9. **Administrator enabling digital ALV voting**: As an association administrator, I want to enable digital voting during hybrid ALV meetings so that remote members can participate equally. (Source: intelligence DB #316)
10. **Secretary conducting weighted shareholder vote**: As a board secretary, I want to conduct weighted voting at the AGM so that each shareholder's vote reflects their share ownership. (Source: intelligence DB #317)
11. **Shareholder submitting proxy vote before AGM**: As a shareholder, I want to submit my proxy vote before the AGM so that my shares are voted even if I cannot attend. (Source: intelligence DB #318)
12. **MT member approving decision asynchronously**: As an MT member, I want to vote on decisions asynchronously so that we don't need to wait for the next meeting for routine approvals. (Source: intelligence DB #322)
13. **Chair classifying hamerstuk/bespreekstuk**: As a voorzitter, I want to classify agenda items as hamerstuk (consent) or bespreekstuk (discussion) so that meeting time is used efficiently. (Source: intelligence DB #158)
14. **Chair processing hamerstukken in batch**: As a voorzitter, I want to process all hamerstukken in one batch without debate so that meeting time is reserved for items requiring discussion. (Source: intelligence DB #164)
15. **Secretary conducting formal votes with configurable rules**: As a council clerk or board secretary, I want to conduct formal votes with configurable majority rules (simple, absolute, qualified 2/3), real-time vote counting, and an auditable result trail. (Source: intelligence DB #347)
16. **Secretary tracking quorum in real-time**: As a meeting secretary, I want real-time quorum tracking with automatic alerts when quorum is at risk and historical attendance analytics per member. (Source: intelligence DB #342)
17. **Citizen voting in participatory budget**: As a citizen, I want to allocate my budget tokens to community projects so that public money is spent on what residents actually want. (Source: intelligence DB #326)
18. **Citizen voting in local referendum**: As a citizen, I want to vote yes/no on a referendum question so that my voice directly influences municipal policy. (Source: intelligence DB #327)

### Priority: Should Have

19. **Secretary verifying voting rights**: As secretary, I want to verify each attendee's voting rights (paid-up membership, correct category) so that only eligible members participate in voting. (Source: intelligence DB #56)
20. **Member casting remote vote**: As a member attending remotely, I want to cast my vote securely during the ALV so that my participation is equal to physical attendees. (Source: intelligence DB #58)
21. **Chair conducting secret ballot**: As chair, I want to conduct a secret ballot for board elections so that members can vote freely without social pressure. (Source: intelligence DB #60)
22. **Member granting digital proxy**: As a member who cannot attend the ALV, I want to grant a proxy (volmacht) to another member digitally so that my vote is represented without paper forms. (Source: intelligence DB #63)
23. **Secretary recording board decisions with votes**: As secretary, I want to record each board decision with the vote distribution per board member so that we comply with WBTR documentation requirements. (Source: intelligence DB #66)
24. **Faction leader analyzing voting patterns**: As a faction leader, I want to analyze voting patterns across my party members so that I can assess party discipline and identify cross-party alliances. (Source: intelligence DB #310)
25. **Team lead using dot voting for backlog**: As a team lead, I want to use dot voting to prioritize backlog items so that the team collectively decides what to work on next. (Source: intelligence DB #321)
26. **Project manager using score polling**: As a project manager, I want to use score polling to evaluate multiple vendor proposals so that the team's nuanced preferences are captured. (Source: intelligence DB #323)
27. **Member voting by email reply**: As a member who cannot attend the meeting, I want to cast my vote by replying to the voting notification email so that my vote is counted without needing the platform. (Source: intelligence DB #1814)
28. **Stakeholder receiving calendar entries for voting deadlines**: As a stakeholder, I want voting deadlines to automatically appear in my calendar so that I never miss a deadline. (Source: intelligence DB #1829)
29. **Journalist searching voting history**: As a journalist, I want to search voting records by topic, member name, or faction so that I can research political positions for my reporting. (Source: intelligence DB #187)
30. **Citizen comparing faction positions**: As a burger, I want to compare how different factions voted on specific issues so that I can make an informed choice at the next election. (Source: intelligence DB #196)

### Priority: Could Have

31. **Investor casting split votes**: As an institutional investor, I want to cast split votes so that I can vote different shares in different directions reflecting diverse fund mandates. (Source: intelligence DB #319)
32. **Supervisory board chair recording consent decision**: As a supervisory board chair, I want to record consent-based decisions so that the board can approve matters efficiently without formal vote counting. (Source: intelligence DB #320)
33. **Department head using consent-based process**: As a department head, I want to use consent-based decision-making (sociocracy) so that decisions move forward quickly while ensuring no critical objections are missed. (Source: intelligence DB #324)
34. **MT member using ranked choice**: As an MT member, I want to rank options in order of preference so that the group finds the option with broadest support rather than just plurality. (Source: intelligence DB #325)
35. **Facilitator using quadratic voting**: As a facilitator, I want to use quadratic voting so that citizens can express intensity of preference across multiple issues without tyranny of the majority. (Source: intelligence DB #329)
36. **Fractievoorzitter recording faction positions**: As a fractievoorzitter, I want to record the agreed faction position (for/against/undecided) on each agenda item so that all members know how to vote. (Source: intelligence DB #153)
37. **Partijbestuurslid analyzing faction voting patterns**: As a partijbestuurslid, I want to analyze my faction's voting patterns across topics and time periods so that I can verify alignment with the party program. (Source: intelligence DB #195)
38. **Assembly participant voting on recommendations**: As an assembly participant, I want to vote on draft recommendations so that the final output reflects the group consensus. (Source: intelligence DB #225)
39. **Cooperative member voting on profit distribution**: As a cooperative member, I want to vote on the proposed profit distribution plan so that members collectively decide how cooperative earnings are allocated. (Source: intelligence DB #81)
40. **Chair circulating written resolution**: As chair, I want to circulate a proposal for written decision to all board members and collect their votes electronically so that urgent decisions can be made between meetings per BW 2:40. (Source: intelligence DB #68)

## Competitor Analysis

| Competitor | Voting Features | Strengths | Gaps |
|---|---|---|---|
| **Loomio** | 7 types: proposals, polls, dot voting, score (0-9), ranked choice, time poll, check | Best variety of collaborative voting methods; drag-and-drop ranked choice | No weighted voting, no formal quorum tracking, no legal compliance features |
| **OpenSlides** | 4 modes: analog, named electronic, token-based, VoteCollector hardware | Assembly-focused; live visualization on projector screens; motion-vote workflow | No ranked choice, no consent-based, no participatory budgeting |
| **Decidim** | Encrypted e-voting, threshold/weighted/cost-based, participatory budgeting | Open source (AGPL); 80+ governments; strong citizen participation | Heavy platform (Ruby on Rails); not suitable for board/corporate governance |
| **POLYAS** | BSI Common Criteria certified; browser-side encryption; anonymous tokens | Only certified online voting software; highest security guarantees | Pure voting tool; no meeting management, no minutes, no motion handling |
| **Belenios** | ElGamal homomorphic encryption; distributed key management; ZK proofs | Strongest cryptographic guarantees; open source (AGPL) | Academic tool; no organizational workflow; verification requires expertise |
| **ElectionBuddy** | FPTP, cumulative, preferential/STV, Borda, scored, approval, rating | Multi-channel (phone, web, mail, in-person); free under 20 voters | No meeting context; standalone voting tool only |
| **iBabs** | Digital voting with audit trail; quorum tracking; RSVP | ISO-certified; strong Dutch government presence; integrated RIS | Limited voting types (open only); no secret ballot; no ranked choice |
| **Notubiz** | Council instruments: motions, amendments, votes; NotuVote | Dominant Dutch council market; video integration | Poor UX ("a true maze"); limited voting types; closed platform |
| **Diligent/OnBoard** | E-voting, surveys, polls, AI minutes, resolutions | Enterprise board governance market leader; 700K+ directors | No legislative features; no citizen participation; expensive |

(Sources: intelligence DB ext #266, #268, #267, #270, #269, #263, #97, #361, competitor features #685, #709, #699, #605, #601, #661, #642)

## Acceptance Criteria

- Open vote records individual votes per participant (for/against/abstain) with real-time display
- Secret ballot stores only aggregate totals with vote count integrity verification
- Roll call vote supports randomized order per Gemeentewet Art. 32 with tie-breaking rules
- Configurable majority rules: simple, absolute, qualified (2/3, 3/4), unanimous, weighted
- Proxy votes are verifiable, count toward quorum, respect per-member limits, and support split voting
- Remote votes have equal weight with session verification and identity confirmation
- Weighted voting calculates results proportionally to configured weights
- Ranked choice voting implements IRV (single-winner) and STV (multi-winner)
- Dot voting supports fixed-point and budget-constrained allocation
- Quadratic voting enforces credit-based cost function
- Consent-based decision records "adopted by consent" with participant list and objection status
- Approval voting and score polling support multi-option evaluation
- Asynchronous written procedure supports BW 2:238 consent-to-method requirement
- Consent agenda (hamerstukken) can be processed in batch with individual pull-out
- Quorum is tracked in real-time with alerts and historical analytics
- Tie-breaking rules are configurable per body (chair casting vote, lot, rejection, revote)
- Complete audit trail mapped to Nextcloud Activity stream
- All voting results mapped to OpenRaadsinformatie `Stemming`/`Stem` and Akoma Ntoso `voting`/`count`
- Voting UX meets WCAG AA; mobile-first design with vote confirmation
- E2E verifiability (cast-as-intended, recorded-as-cast, tallied-as-recorded) for secret ballots
- Decision tables for configurable voting rules per organization
- Voting interface activates automatically when the chair advances to an agenda item with a decisionPoint
- Amendments linked to a decision point are voted before the main motion in submission order
- Vote results update the agenda item status to "decided" in real-time on all participants' views
- Non-decision agenda items do not trigger the voting interface
