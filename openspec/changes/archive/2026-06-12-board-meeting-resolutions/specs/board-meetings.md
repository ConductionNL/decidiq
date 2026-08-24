# Board Meetings Specifications

## REQ-001: Meeting notice with statutory deadline

**GIVEN** a board meeting is scheduled in the BoardMeeting entity
**WHEN** the company secretary sends the formal notice via the admin interface
**THEN** the system verifies the notice date is at least the statutory minimum (default 7 days per Dutch law, configurable per board's statuten) before meeting-date
**AND** distributes notice with agenda and access-link to board portal to all board members in their preferred language
**AND** records timestamp and delivery confirmation in the audit trail
**AND** updates BoardMeeting.status to `notice-sent`

**Acceptance Criteria:**
- Notice deadline is configurable per Board (field: `notice-deadline-days`)
- Notice is distributed via Nextcloud Notification Service to each board member's inbox
- Each board member receives notice in their preferred language (languagePreference on Person entity)
- Audit trail logs: timestamp, actor (secretary UUID), action ("notice-sent"), meeting UUID, recipient list
- System prevents sending notice if time remaining is less than configured deadline (returns 400 Bad Request)
- Notice delivery is idempotent (re-sending the same notice does not create duplicate notifications)

---

## REQ-002: Resolution with named voting and audit trail

**GIVEN** a resolution is being voted on with `vote-type=named`
**WHEN** each board member casts a vote through the board portal
**THEN** the system records vote, timestamp, method, and member identity immutably in the audit trail
**AND** displays running tally to chairman only until vote is closed
**AND** computes adoption status against vote-threshold automatically
**AND** prevents vote-changes after chairman closes the vote

**Acceptance Criteria:**
- Only chairman (role: chairman on BoardMember) can view running tally during voting
- Vote data includes: board-member UUID, vote choice (in-favor, against, abstain, absent, recused-due-to-conflict), timestamp, vote-method (raised-hand, electronic, written-ballot, proxy)
- Vote is immutable after casting (PUT/DELETE requests return 403 Forbidden after vote closes)
- Adoption is computed as: count(in-favor) >= threshold_percentage * total_eligible_members
- Threshold is determined by vote-threshold field on Resolution (simple-majority, qualified-majority-two-thirds, qualified-majority-three-quarters, unanimous)
- Audit trail logs each vote with SHA-256 hash chaining
- Vote count is updated in real-time to chairman's view via WebSocket or polling endpoint
- After chairman closes vote, Resolution.status transitions to `adopted` or `rejected`

---

## REQ-003: Anonymous voting with cryptographic unlinkability

**GIVEN** a resolution requires anonymous voting (typically appointments or sensitive personnel matters)
**WHEN** members cast votes
**THEN** the system records the vote outcome without recoverable link to the voting member
**AND** records only that each member voted (for quorum) without revealing the choice
**AND** the chairman sees only aggregate counts
**AND** the audit trail proves no individual vote-to-member mapping exists

**Acceptance Criteria:**
- Vote.anonymized flag is set to `true` for anonymous voting rounds
- Board-member-koppeling field is encrypted via HMAC-SHA256 using a per-resolution random key
- HMAC key is stored in encrypted form (via Nextcloud Vault API or HSM) and never persisted in plaintext
- Aggregate counts (votesFor, votesAgainst, votesAbstain, votesAbsent) are stored on VotingRound
- Chairman view displays only aggregate counts, never individual votes
- Audit trail includes the vote (anonymized), but not the decrypted member identity
- A member can verify they voted (by checking quorum list) but cannot prove how they voted
- After voting closes, no mechanism exists to reverse the HMAC encryption (mathematically one-way per HMAC definition)

---

## REQ-004: eIDAS Qualified Electronic Signature on minutes

**GIVEN** minutes have been prepared and reviewed by the chairman
**WHEN** signatories apply their signatures via integrated eIDAS QES provider (openconnector-e-sign)
**THEN** the system requests QES-level signatures and verifies certificate validity against EU Trusted List
**AND** stores the signed PDF with embedded signatures
**AND** generates SHA-256 hash and links to docudesk archive
**AND** minutes status transitions to `signed` only when all required signatures are complete

**Acceptance Criteria:**
- Minutes.status starts as `draft`; transitions to `final` when secretary marks for review
- Chairman reviews and approves; status remains `final` until signature process begins
- "Sign minutes" action opens openconnector-e-sign interface (via iframe or modal)
- openconnector-e-sign collects QES signatures from each required signatory (chairman, secretary, and any board members designated per board rules)
- Each signature is verified against EU eIDAS Trusted List (via docudesk-eidas service)
- If certificate is invalid or expired, signature is rejected (HTTP 422)
- Signed PDF is generated (via docudesk-eidas) and stored with reference in Minutes.pdf-archive-reference
- SHA-256 hash of signed PDF is computed and stored in Minutes.hash-sha256
- Minutes.signed-by array is populated with signer UUIDs, signature-timestamps, and certificate-thumbprints
- Minutes.status transitions to `signed` only when all required signatures are present
- Each signature addition is logged to the audit trail with timestamp and signatory UUID
- Unsigned minutes remain readable but with a warning banner: "Notulen nog niet ondertekend"

---

## REQ-005: Mandatory conflict-of-interest declaration per agenda item

**GIVEN** a board member opens an agenda item in the portal
**WHEN** no ConflictOfInterest declaration exists for this combination
**THEN** the system blocks access to the agenda item materials until a declaration is filed
**AND** offers options `none`, `material`, `non-material` with required description for the latter two
**AND** if `material` is declared, notifies the chairman and company secretary
**AND** offers the member the choice to recuse from discussion, recuse from vote only, or disclose-and-participate
**AND** records the chosen action in the audit trail

**Acceptance Criteria:**
- Portal enforces: GET /api/board-materials/{id} returns 403 Forbidden if no ConflictOfInterest record exists for (board-member, agenda-item)
- Declaration form offers radio buttons: "Geen belangenconflict" (none), "Niet-materieel" (non-material), "Materieel" (material)
- For material and non-material, description field is required (≥10 characters)
- If material conflict is declared, Nextcloud notification is sent to chairman and secretary with conflict summary
- After filing declaration, member selects action: "Onthouding van discussie" (recuse from discussion), "Onthouding van stemming" (recuse from vote), "Openbaarmaking en deelname" (disclose and participate)
- ConflictOfInterest record is created with: board-member-koppeling, agenda-item-koppeling, declaration-type, description (if applicable), severity, action-taken, declaration-timestamp
- Portal enforces action: if action-taken = "recused-from-discussion", agenda-item and its materials are marked read-only for that member; if "recused-from-vote", vote input is disabled for that resolution but materials remain readable
- If disclosure is chosen, no restrictions are applied to the member's access or voting rights
- Audit trail logs the declaration and action-taken choice

---
