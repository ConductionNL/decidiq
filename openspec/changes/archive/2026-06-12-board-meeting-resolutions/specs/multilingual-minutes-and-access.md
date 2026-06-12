# Multilingual Minutes and Access Control Specifications

## REQ-006: Multilingual minutes with reconciled content

**GIVEN** a board operates in both Dutch and English
**WHEN** minutes are prepared
**THEN** both language versions are created in parallel and linked
**AND** signature blocks apply to both versions (legally both are equally valid)
**AND** a discrepancy-check alerts the secretary if structural elements diverge (different resolution count, missing sections)

**Acceptance Criteria:**
- When a BoardMeeting is created with `language: "both"`, two Minutes records are automatically created: one with `language: "nl"` and one with `language: "en"`
- Both Minutes records are linked via OpenRegister relation (`minutes-translation` relation type)
- Secretary drafts content in the primary language (typically Dutch); portal provides side-by-side editing or toggle view for both languages
- Before approval, a reconciliation check runs:
  - Extract structured elements (resolutions, decisions, action items) from both versions
  - Compare counts: if resolution count differs, alert secretary with details
  - Compare section structure: if a major section (e.g., "Voorbeschouwing") is missing in one version, alert
  - Check for dangling cross-references (e.g., "Agenda item 5" referenced but not present)
- Secretary can override reconciliation warnings (with audit logging) or re-draft the mismatched language version
- When minutes are signed, both language versions are signed together:
  - A single signature-timestamp block is created covering both versions
  - Signature is applied to both PDFs (one Dutch, one English)
  - Both signed PDFs are stored in docudesk archive with shared hash
- Search and filter across both language versions (e.g., searching for "dividend" returns results from both Dutch and English minutes)
- When exporting meeting record, both language versions are included (as separate PDFs or bilingual combined document per board preference)

---

## REQ-007: Board portal with secure offline access

**GIVEN** a board member is preparing for a meeting while traveling
**WHEN** they download materials via the board portal mobile app or web download
**THEN** materials are encrypted-at-rest on the device with the member's authenticated key
**AND** materials are watermarked with the member's name on every page
**AND** materials expire (become unreadable) X days after meeting close (configurable, default 30 days)
**AND** any export or print is logged in the audit trail

**Acceptance Criteria:**
- Board portal includes "Download package" button on materials list view
- Downloaded materials are encrypted via AES-256 with a device-specific key derived from the member's login credentials and device UUID (prevents sharing across devices)
- Watermark is visible on all downloaded pages: "Confidential: [Member Name] | Page X of Y"
- Watermark is embedded in PDF metadata (for search/copy prevention) and as background image (for print prevention)
- Downloaded package includes expiration metadata: `expires: <meeting-close-date> + 30 days` (configurable via Board.material-retention-days)
- On device, downloaded materials become unreadable after expiration (mobile app decryption fails; web app shows 403 Forbidden if accessing from cache)
- Each download action is logged to audit trail with: timestamp, board-member UUID, material IDs, device fingerprint
- Each print action (via browser print or device print dialog) is logged to audit trail
- Each export/copy attempt (copy-paste, right-click-save, screenshot) generates a warning log entry (best-effort detection; not foolproof)
- Portal warns member when downloading: "Downloaded materials will expire on [date]. They are watermarked with your name and are not transferable."

---

## REQ-008: Quorum verification before vote

**GIVEN** the chairman initiates a vote on a resolution
**WHEN** the system computes attendance (in-person + remote + valid proxies)
**THEN** the system verifies the configured quorum-rule is met before allowing the vote to open
**AND** if not met, the chairman is notified and the vote cannot proceed
**AND** if a vote requires qualified-majority, the threshold is computed against total seats not against attendees

**Acceptance Criteria:**
- Board entity stores `quorum-rule` field (free-text, e.g., "majority", "two-thirds", "all-except-one")
- QuorumVerificationService computes quorum as:
  1. Attend in-person: count active in-person participants from meeting attendance list
  2. Attend remote: count participants with valid remote attendance verified (via Nextcloud login or opentalk video confirmation)
  3. Proxies: count proxies from valid proxy-grants (board-member-koppeling is the proxy-holder; proxy-grantor is absent)
  4. Total eligible: count all board members with `role != observer` and `term-end-date >= meeting-date`
  5. Quorum met: (in-person + remote + proxies) >= threshold_percentage * total_eligible
- Chairman view displays: "Quorum status: 5 of 6 required members present (in-person: 4, remote: 1, proxy: 0)"
- If quorum is not met, "Open vote" button is disabled; tooltip explains why
- If vote is forced to open (via admin override, logged to audit trail), a warning is added to Resolution and Minutes: "Stemming gehouden zonder quorum"
- For vote-threshold computation:
  - `simple-majority`: count(in-favor) > 50% * total_eligible
  - `qualified-majority-two-thirds`: count(in-favor) >= 2/3 * total_eligible
  - `qualified-majority-three-quarters`: count(in-favor) >= 3/4 * total_eligible
  - `unanimous`: count(in-favor) == total_eligible (no absents, no recusals, no votes against)
- Quorum check and vote-threshold check are independent: quorum can be met, but a qualified-majority resolution can still fail

---

## REQ-009: Regulator and auditor access with read-only scope

**GIVEN** an external auditor or regulator requests access to board meeting records
**WHEN** the company secretary grants time-bound access via the portal
**THEN** the recipient receives a read-only access link valid for a configurable period
**AND** the recipient cannot download materials but can view minutes, resolutions, voting records, and conflict declarations
**AND** every view is logged with timestamp and recipient identity for the audit trail

**Acceptance Criteria:**
- Admin portal (company secretary) includes "Grant auditor/regulator access" button
- Secretary selects:
  - Recipient: dropdown of known auditors/regulators (preloaded from a settings list) or free-text email address
  - Scope: "audit-committee-only", "all-resolutions", "all-records"
  - Duration: date-range picker (default: 30 days from grant date)
- System generates a time-bound token (JWT with exp claim, 256-bit random slug for URL readability)
- Auditor/regulator receives email with access link: `https://decidesk.example.com/audit-access/{token}/meetings`
- Access link is read-only:
  - GET /api/minutes/{id} with valid token returns full content
  - GET /api/resolutions with valid token returns filtered list (per scope)
  - GET /api/votes with valid token returns votes (per scope) — identity redacted for anonymous votes
  - GET /api/conflict-of-interest returns conflict declarations (per scope)
  - POST/PUT/DELETE operations return 403 Forbidden
  - Download buttons are hidden or disabled
- Every read operation via auditor/regulator token is logged: timestamp, token (or recipient-identifier), resource UUID, operation (GET /api/path)
- Token expires automatically at end-date; accessing with expired token returns 401 Unauthorized
- Secretary can revoke token immediately (status: revoked in audit log)
- Auditor/regulator view is filtered by scope:
  - "audit-committee-only": only materials with `access-level: audit-committee` are visible
  - "all-resolutions": only resolutions and votes are visible; minutes and materials are hidden
  - "all-records": all minutes, resolutions, votes, conflict declarations visible; materials with `access-level: executive-only` are redacted

---
