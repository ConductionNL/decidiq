## Why

Enterprise board meetings — Raden van Commissarissen (supervisory boards), Raden van Bestuur (executive boards), audit committees, and remuneration committees — have fundamentally different governance requirements than public municipal councils. Boards of listed companies, financial institutions, housing corporations, healthcare systems, and large foundations must manage formal resolutions with legal force, require eIDAS-compliant electronic signatures on minutes, enforce strict confidentiality of board materials, and provide complete audit trails to external auditors and regulators.

Current solutions (Diligent, Nasdaq Boardvantage) are proprietary and expensive (EUR 2,000–8,000 per user per year) with weak Dutch compliance positioning and data stored outside the EU. Most Dutch mid-market boards fall back on a mix of SharePoint folders and encrypted email attachments — which provides neither audit trail nor controlled access — leading to expensive reconstruction work during regulator investigations.

## What Changes

- **New capability**: `board-portal` — secure web and mobile portal for board members with offline-capable material download, watermarking, and expiration
- **New capability**: `board-meetings` — full-fidelity board meeting lifecycle: scheduling, notice with statutory deadlines, material distribution with granular access control (board-only, executive-only, audit-committee, external-auditor, regulator), quorum verification
- **New capability**: `board-resolutions-and-voting` — named and anonymous voting on resolutions with cryptographic unlinkability, vote-change prevention, running tally (chairman only), adoption-status computation against configurable thresholds
- **New capability**: `conflict-of-interest-declarations` — mandatory per-agenda-item declarations with material/non-material severity, action tracking (recuse from discussion, recuse from vote, disclose-and-participate), chairman notification
- **New capability**: `eidas-qualified-signatures` — integration with eIDAS QES providers via openconnector-e-sign, signature verification against EU Trusted List, signed PDF storage with SHA-256 integrity anchoring
- **New capability**: `multilingual-minutes` — parallel Dutch/English (configurable) minutes with reconciliation checks and linked signatures on both versions
- **New capability**: `board-audit-trail` — immutable cryptographic log of every vote, conflict declaration, material access with SHA-256 chaining and audit export
- **New capability**: `regulator-and-auditor-access` — time-bound read-only access for external auditors and regulators with scoped visibility (audit committee minutes vs. executive sessions) and audit logging per view
- **New capability**: `annual-governance-reporting` — automated generation of Code-mandated statistics (attendance, independence ratios, meeting frequency, conflict trends) in compliance-report format
- **New capability**: `written-resolutions` — rondvraag-besluit (written resolution outside meeting) workflow with eIDAS QES collection and unanimity verification
- **New capability**: `proxy-voting` — proxy delegation with per-agenda-item scope control and automatic revocation at meeting close
- **Modified capability**: `decidesk-meetings` — extended from p2-meeting-management to support board-specific lifecycle states (notice-sent, materials-distributed, minutes-signed)

## Capabilities

### New Capabilities

- `board-portal`: Secure portal with offline access, watermarking, member authentication, encrypted device storage, material expiration
- `board-meetings`: Meeting scheduling, statutory notice deadlines, granular material access control (access-level enum), quorum rules
- `board-resolutions-and-voting`: Named/anonymous voting, vote-change prevention, running tally (chairman-only), adoption-status computation
- `conflict-of-interest-declarations`: Per-agenda-item mandatory declarations, material/non-material severity, action-taken tracking
- `eidas-qualified-signatures`: QES signing via openconnector-e-sign, certificate validation, signed PDF storage, hash verification
- `multilingual-minutes`: Parallel-language minutes with reconciliation, linked signatures on both versions
- `board-audit-trail`: Cryptographic immutability log (SHA-256), vote/conflict/access logging, export for external audit
- `regulator-and-auditor-access`: Time-bound read-only links, scoped visibility per access-level, per-view audit logging
- `annual-governance-reporting`: Automated statistics generation (attendance, independence, meeting frequency, conflicts) per Code template
- `written-resolutions`: Rondvraag-besluit workflow with eIDAS QES, unanimity verification, minutes generation
- `proxy-voting`: Per-agenda-item scope, automatic revocation, dual-identity recording
- `board-governance-model-configuration`: One-tier vs. two-tier governance, per-board statuten reference, role hierarchy (chairman, vice-chairman, secretary, member, executive-member, non-executive-member, independent-member, employee-representative)

### Modified Capabilities

- `decidesk-meetings` (from p2-meeting-management-core): Extended with board-specific lifecycle states and material access control

## Impact

- **Backend**: 8 new OpenRegister schemas (Board, BoardMember, BoardMeeting, Resolution, Vote, Minutes, ConflictOfInterest, BoardMaterial); CalDAV integration for meeting scheduling; eIDAS QES service integration; notification service for chairman/auditor alerts
- **Frontend**: Board portal views (member dashboard, materials list, material detail with watermark, voting view, minutes view), admin views (board configuration, access control, audit log export)
- **Data**: 100+ new objects (seed data: 3 boards, 5 board members, 5 meetings, 10 resolutions, 25 votes, minutes with signatures, conflict declarations)
- **Dependencies**: OpenRegister app (required), Nextcloud Calendar/CalDAV (required), openconnector-e-sign app (required for QES), docudesk-eidas app (required for signature storage and verification)
- **Governance**: Dutch Corporate Governance Code (MCCG), eIDAS Regulation (EU 910/2014), Burgerlijk Wetboek Boek 2, Woningwet, WTZi/WMG, WBTR, SOX Section 404 (where applicable), AVG/GDPR, ISO 27001
