# Governance Reporting and Special Procedures Specifications

## REQ-010: Annual governance report

**GIVEN** the financial year closes and a Code-mandated governance report must be produced
**WHEN** the company secretary requests the `annual-governance-report`
**THEN** the system generates statistics on number of meetings, attendance per member, resolutions adopted, conflicts declared, independence status of members
**AND** outputs in format compatible with the Code's reporting template
**AND** flags any non-compliance with Code provisions (e.g., insufficient meeting frequency, low independence ratio)

**Acceptance Criteria:**
- Report generation is triggered via admin portal: "Generate Annual Governance Report" button, with date-range selector (default: Jan 1 – Dec 31 of prior year)
- Report includes sections:
  1. **Board Composition**: List of board members, role, appointment date, term end, independence status, nevenfuncties summary
  2. **Meeting Frequency**: Total meetings held, frequency per quarter, breakdown by meeting-type (regular, extraordinary, committee)
  3. **Attendance Statistics**: Per-member attendance rate (in-person + remote + proxies as "attended"), total votes cast, number of absences
  4. **Resolutions Adopted**: Count by type (approval, appointment, dismissal, financial, strategic, policy, delegation-of-authority, acknowledgement), adoption rate (adopted / total proposed)
  5. **Voting Patterns**: Named vs. anonymous voting breakdown, unanimous votes, qualified-majority votes
  6. **Conflict of Interest**: Number of conflicts declared, breakdown by severity and action-taken, any members with recurring conflicts
  7. **Compliance Flags**: 
     - Independence ratio: flag if <50% independent (Code requirement for listed entities)
     - Meeting frequency: flag if <4 regular meetings per year (Code benchmark)
     - Attendance: flag any member with <75% attendance rate
     - Conflicts: flag any member with >3 material conflicts declared in a year
     - Minutes signing: flag any minutes not signed within 5 business days (Code benchmark)
- Output formats:
  - PDF (formatted per Dutch Corporate Governance Code template)
  - Excel (spreadsheet with data tables)
  - JSON (for machine consumption; feeds to opencatalogi public-publishing workflow)
- Report includes a reconciliation section: "Data completeness check" — confirms all meetings in the calendar were recorded as BoardMeeting entities, all resolutions logged, etc.
- Secretary can manually add narrative commentary (e.g., "Special committee formed 15 May 2026 due to...") before exporting
- Report is timestamped and versioned; prior reports are accessible in an archive
- Report generation logs: timestamp, actor (secretary UUID), report parameters (date-range, format, recipient list)

---

## REQ-011: Written resolution outside meeting

**GIVEN** the board statuten allow resolutions to be adopted in writing without a meeting (rondvraag-besluit)
**WHEN** the company secretary initiates a written resolution and circulates it to all members
**THEN** the system collects signed agreement from each member via eIDAS QES
**AND** verifies unanimity is reached within the configured response window
**AND** records the resolution as adopted with effective-date matching the last signature
**AND** generates the equivalent of minutes documenting the written-resolution procedure for the audit trail

**Acceptance Criteria:**
- Admin portal includes "Create written resolution" flow (alternative to "Schedule meeting")
- Secretary provides:
  - Resolution title and full-text
  - Response deadline (default: 5 business days from initiation)
  - Required signatories (default: all active board members; or custom list)
- Written-resolution workflow:
  1. Resolution is created with `type: written-resolution` and `status: proposed`
  2. Secretary sends email to all required members with:
     - Resolution text
     - eIDAS QES signature request link (via openconnector-e-sign)
     - Response deadline
  3. Each member signs the resolution text via openconnector-e-sign (same QES verification as per REQ-004)
  4. System collects signatures and records Vote-equivalents (one per member): `vote: in-favor` (signing) or `vote: abstain` (not signing)
  5. After response deadline or when all required signatures are collected:
     - System verifies unanimity: all required members signed (no votes against, no absents)
     - If unanimity is met, Resolution.status = `adopted`, effective-date = last signature timestamp
     - If unanimity is NOT met, Resolution.status = `rejected`, with notation of who signed and who did not
  6. Minutes are auto-generated documenting:
     - Date resolution was initiated
     - Response deadline
     - Signature list and timestamps
     - Adoption date or rejection reason
  7. Minutes are marked `version: final` (not signed separately; the individual signatures on the resolution serve as approval)
- Resolution cannot be modified after initiation (PUT returns 403)
- Voting records for written resolutions show: member, signature timestamp, and note "(signed via rondvraag-besluit)" to distinguish from in-meeting votes
- Written resolutions are included in annual governance report with separate count

---

## REQ-012: Proxy and remote attendance handling

**GIVEN** a board member cannot attend a meeting and grants a proxy to another member
**WHEN** the company secretary registers the proxy with scope (full proxy or per-agenda-item) and expiration
**THEN** the proxy holder gains the right to vote on behalf of the absent member
**AND** votes cast via proxy are recorded with both the actual voter and the proxy-grantor identity
**AND** the proxy is automatically revoked at meeting close or grant-expiration, whichever comes first
**AND** if the absent member joins remotely mid-meeting, the proxy is suspended for any remaining items

**Acceptance Criteria:**
- Board member (grantor) requests proxy via portal:
  - Select proxy-holder from list of active board members
  - Optionally restrict to specific agenda items (e.g., "items 1–3 only"; full proxy if blank)
  - Optional expiration date (default: meeting end-date)
- Company secretary approves proxy registration (to prevent unauthorized delegation)
- Proxy is recorded as:
  - Grantor: board-member-uuid-1
  - Holder: board-member-uuid-2
  - Scope: "full-proxy" or list of agenda-item-uuids
  - Effective: immediately after approval
  - Expires: meeting-end-date or custom-date, whichever is earlier
- Vote recording:
  - When proxy-holder casts a vote on behalf of grantor:
    - Vote.board-member-koppeling = proxy-holder UUID (actual voter)
    - Vote.proxy-holder = proxy-holder UUID
    - Audit trail logs: "Vote cast by [proxy-holder] on behalf of [grantor] via valid proxy"
  - Vote is valid and counts toward quorum and adoption-threshold
  - Vote display in chairman view shows: "[Proxy-holder] (via proxy from [Grantor])"
- Proxy is automatically revoked:
  - At meeting-close (Status: closed)
  - At custom expiration-date if earlier
  - If grantor joins the meeting remotely mid-session, proxy is suspended for all remaining items
    - Secretary marks proxy as "suspended"; any votes attempted by proxy-holder for suspended items return 403
- Proxy is visible in minutes:
  - "Board member [Grantor] was absent; proxy granted to [Holder] for items 1–4"
  - If proxy was suspended: "Proxy was suspended at [timestamp] when [Grantor] joined remotely"
- Voting records include proxy information for audit purposes:
  - GET /api/votes filters can show: "votes cast via proxy" or "votes cast in person"
  - Annual governance report includes proxy-voting statistics

---
