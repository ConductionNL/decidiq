---
status: draft
app: decidesk
spec: board-meeting-resolutions
target_users: supervisory boards, executive boards, company secretaries, board chairs, board members, external auditors
depends_on:
  - decidesk-base
  - docudesk-eidas
  - openconnector-e-sign
references:
  - Burgerlijk Wetboek Boek 2 (vennootschapsrecht NL)
  - eIDAS Regulation (EU 910/2014)
  - https://www.commissiecorporategovernance.nl/ (Dutch Corporate Governance Code)
  - https://www.oecd.org/corporate/principles-corporate-governance/
  - SOX Section 404 (waar van toepassing op NL-dochters van US-noteerders)
---

# Board Meeting Resolutions

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Besluiten > detail (board-mode label) + Vergaderingen filter "Bestuur" / split

**Rationale:** Resolution is a labeled decision; meeting type filter  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Enterprise board meetings — Raden van Commissarissen (RvC, supervisory boards), Raden van Bestuur (RvB, executive boards), audit committees en remuneration committees — hebben fundamenteel andere eisen dan publieke gemeentevergaderingen. Boards van beursgenoteerde ondernemingen, financiële instellingen, woningcorporaties, ziekenhuizen en grote stichtingen werken met formele resolutions die rechtsgevolg hebben, vereisen eIDAS-conforme elektronische handtekeningen op notulen, hanteren strikte vertrouwelijkheid (board materials onder NDA), en moeten een complete audit trail bieden aan externe accountant en toezichthouder (AFM, DNB, NZa, Aw). Veel Nederlandse boards zijn nog gevangen in Diligent of Nasdaq Boardvantage — proprietary tools met hoge licentiekosten (EUR 2000-EUR 8000 per gebruiker per jaar), zwakke Nederlandse compliance-positie, en data-opslag buiten de EU. Het gevolg is dat Nederlandse middelgrote boards veelal terugvallen op een mix van SharePoint-folders en versleutelde e-mailbijlagen, wat noch de audit-trail noch de vertrouwelijkheid op orde brengt en bij toezichthouder-onderzoek leidt tot reconstructie-werk dat dagen tot weken kost.

Deze spec definieert een open-source board portal binnen decidesk dat full-fidelity board meetings ondersteunt: resolutions met named of anonymous voting, eIDAS Qualified Electronic Signatures op vastgestelde notulen, meertalige notulen voor internationale boards (Engels + Nederlands minimaal, uitbreidbaar naar Frans/Duits voor cross-border holdings), strikt afgedwongen conflict-of-interest declarations per agenda-item conform best practice corporate governance, en een board portal voor leden met offline-capable mobiele toegang. De spec respecteert AVG, eIDAS, BW2 vennootschapsrecht en de Nederlandse Corporate Governance Code, en is geschikt voor zowel one-tier (executive + non-executive in één board) als two-tier governance modellen.

Three design priorities distinguish this spec from generic meeting software. First, immutability of the audit trail: every vote, every conflict declaration, every materials access is recorded with cryptographic integrity (SHA-256 chained log) so that subsequent regulator investigations or shareholder litigation can establish what was known, when, and by whom. Second, the principle of least privilege applied to board materials: a non-executive director typically should not see executive-session preparations, an audit committee member should not see remuneration committee deliberations on their own compensation, and an external auditor should see audit-committee minutes but not strategic executive sessions. The access-level field on BoardMaterial encodes these compartments and the portal enforces them at view time, not just at distribution time. Third, jurisdictional pragmatism: while the Dutch Corporate Governance Code is the primary reference for listed entities, the spec is generic enough to support housing corporations under the Woningwet, healthcare boards under the WTZi/WMG, and foundations under the WBTR, without requiring per-sector forks of the data model.

## Data Model

**Board** is een schema met velden: naam, type (`raad-van-commissarissen`, `raad-van-bestuur`, `audit-committee`, `remuneration-committee`, `nomination-committee`, `risk-committee`, `one-tier-board`), legal-entity (rechtspersoon-koppeling met KvK), governance-model (`two-tier`, `one-tier`), establishment-date, statuten-reference, chairman (board-member-koppeling), vice-chairman, secretary (company-secretary-koppeling), default-language (`nl`, `en`), additional-languages, quorum-rule (configureerbaar).

**BoardMember** is een schema met velden: persoon-koppeling, board-koppeling, rol (`chairman`, `vice-chairman`, `member`, `executive-member`, `non-executive-member`, `independent-member`, `employee-representative`), appointment-date, appointment-resolution-reference, term-end-date, reappointment-eligible, nationality, nevenfuncties (lijst van bestuursfuncties elders — verplicht conform Code), independence-status (conform Code criterium), profile-document.

**BoardMeeting** is een schema met velden: board-koppeling, meeting-type (`regular`, `extraordinary`, `strategy-day`, `closed-session`, `executive-session`), meeting-date, meeting-start, meeting-end, location, format (`in-person`, `remote`, `hybrid`), language (`nl`, `en`, `both`), status (`scheduled`, `notice-sent`, `materials-distributed`, `in-session`, `adjourned`, `closed`, `minutes-signed`), notice-sent-date, materials-deadline (X dagen voor meeting), quorum-required, quorum-achieved (boolean), recording-allowed.

**Resolution** is een schema met velden: meeting-koppeling, resolution-number (formaat `R-{year}-{number}`), title, type (`approval`, `appointment`, `dismissal`, `financial`, `strategic`, `policy`, `delegation-of-authority`, `acknowledgement`), proposing-member, full-text (rich text, meertalig), background (rich text), legal-basis (statuten-artikel of wettelijke grondslag), vote-type (`named`, `anonymous`, `unanimous-consent`, `acclamation`), vote-threshold (`simple-majority`, `qualified-majority-two-thirds`, `qualified-majority-three-quarters`, `unanimous`), status (`proposed`, `under-discussion`, `adopted`, `rejected`, `withdrawn`, `tabled`), adoption-date, effective-date.

**Vote** is een schema met velden: resolution-koppeling, board-member-koppeling, vote (`in-favor`, `against`, `abstain`, `absent`, `recused-due-to-conflict`), vote-timestamp, vote-method (`raised-hand`, `electronic`, `written-ballot`, `proxy`), proxy-holder (board-member-koppeling indien proxy), anonymized (boolean — bij anonymous voting wordt member-koppeling versleuteld).

**Minutes** is een schema met velden: meeting-koppeling, language, version (`draft`, `final`, `signed`), content (rich text), prepared-by (company-secretary), reviewed-by (chairman), signed-by (lijst van handtekeningen), signing-completion-date, eidas-signature-level (`SES`, `AdES`, `QES`), pdf-archive-reference, hash-sha256.

**ConflictOfInterest** is een schema met velden: board-member-koppeling, agenda-item-koppeling, declaration-type (`financial-interest`, `personal-relationship`, `competing-business`, `prior-involvement`, `none`), description, severity (`material`, `non-material`), action-taken (`recused-from-discussion`, `recused-from-vote`, `disclosed-and-participated`, `no-action-needed`), declaration-timestamp.

**BoardMaterial** is een schema met velden: meeting-koppeling, agenda-item-koppeling, title, document-reference, access-level (`board-only`, `executive-only`, `audit-committee`, `external-auditor`, `regulator`), distribution-timestamp, watermarked (per board member).

## Requirements

### REQ-001: Meeting notice with statutory deadline

**GIVEN** a board meeting is scheduled
**WHEN** the company secretary sends the formal notice
**THEN** the system verifies the notice date is at least the statutory minimum (default 7 days, configurable per board statuten) before meeting-date
**AND** distributes notice with agenda and access-link to board portal to all board members in their preferred language
**AND** records timestamp and delivery confirmation for audit trail

### REQ-002: Resolution with named voting and audit trail

**GIVEN** a resolution is being voted on with `vote-type=named`
**WHEN** each board member casts a vote through the board portal
**THEN** the system records vote, timestamp, method, and member identity immutably
**AND** displays running tally to chairman only until vote is closed
**AND** computes adoption status against vote-threshold automatically
**AND** prevents vote-changes after chairman closes the vote

### REQ-003: Anonymous voting with cryptographic unlinkability

**GIVEN** a resolution requires anonymous voting (typically appointments or sensitive personnel matters)
**WHEN** members cast votes
**THEN** the system records the vote outcome without recoverable link to the voting member
**AND** records only that each member voted (for quorum) without revealing the choice
**AND** the chairman sees only aggregate counts
**AND** the audit trail proves no individual vote-to-member mapping exists

### REQ-004: eIDAS Qualified Electronic Signature on minutes

**GIVEN** minutes have been prepared and reviewed by the chairman
**WHEN** signatories apply their signatures via integrated eIDAS QES provider (openconnector e-sign)
**THEN** the system requests QES-level signatures and verifies certificate validity against EU Trusted List
**AND** stores the signed PDF with embedded signatures
**AND** generates SHA-256 hash and links to docudesk archive
**AND** minutes status transitions to `signed` only when all required signatures are complete

### REQ-005: Mandatory conflict-of-interest declaration per agenda item

**GIVEN** a board member opens an agenda item in the portal
**WHEN** no ConflictOfInterest declaration exists for this combination
**THEN** the system blocks access to the agenda item materials until a declaration is filed
**AND** offers options `none`, `material`, `non-material` with required description for the latter two
**AND** if `material` is declared, notifies the chairman and company secretary
**AND** offers the member the choice to recuse from discussion, recuse from vote only, or disclose-and-participate
**AND** records the chosen action in the audit trail

### REQ-006: Multilingual minutes with reconciled content

**GIVEN** a board operates in both Dutch and English
**WHEN** minutes are prepared
**THEN** both language versions are created in parallel and linked
**AND** signature blocks apply to both versions (legally both are equally valid)
**AND** a discrepancy-check alerts the secretary if structural elements diverge (different resolution count, missing sections)

### REQ-007: Board portal with secure offline access

**GIVEN** a board member is preparing for a meeting while traveling
**WHEN** they download materials via the board portal mobile app or web download
**THEN** materials are encrypted-at-rest on the device with the member's authenticated key
**AND** materials are watermarked with the member's name on every page
**AND** materials expire (become unreadable) X days after meeting close (configurable, default 30 days)
**AND** any export or print is logged in the audit trail

### REQ-008: Quorum verification before vote

**GIVEN** the chairman initiates a vote on a resolution
**WHEN** the system computes attendance (in-person + remote + valid proxies)
**THEN** the system verifies the configured quorum-rule is met before allowing the vote to open
**AND** if not met, the chairman is notified and the vote cannot proceed
**AND** if a vote requires qualified-majority, the threshold is computed against total seats not against attendees

### REQ-009: Regulator and auditor access with read-only scope

**GIVEN** an external auditor or regulator requests access to board meeting records
**WHEN** the company secretary grants time-bound access via the portal
**THEN** the recipient receives a read-only access link valid for a configurable period
**AND** the recipient cannot download materials but can view minutes, resolutions, voting records, and conflict declarations
**AND** every view is logged with timestamp and recipient identity for the audit trail

### REQ-010: Annual governance report

**GIVEN** the financial year closes and a Code-mandated governance report must be produced
**WHEN** the company secretary requests the `annual-governance-report`
**THEN** the system generates statistics on number of meetings, attendance per member, resolutions adopted, conflicts declared, independence status of members
**AND** outputs in format compatible with the Code's reporting template
**AND** flags any non-compliance with Code provisions (e.g., insufficient meeting frequency, low independence ratio)

### REQ-011: Written resolution outside meeting

**GIVEN** the board statuten allow resolutions to be adopted in writing without a meeting (rondvraag-besluit)
**WHEN** the company secretary initiates a written resolution and circulates it to all members
**THEN** the system collects signed agreement from each member via eIDAS QES
**AND** verifies unanimity is reached within the configured response window
**AND** records the resolution as adopted with effective-date matching the last signature
**AND** generates the equivalent of minutes documenting the written-resolution procedure for the audit trail

### REQ-012: Proxy and remote attendance handling

**GIVEN** a board member cannot attend a meeting and grants a proxy to another member
**WHEN** the company secretary registers the proxy with scope (full proxy or per-agenda-item) and expiration
**THEN** the proxy holder gains the right to vote on behalf of the absent member
**AND** votes cast via proxy are recorded with both the actual voter and the proxy-grantor identity
**AND** the proxy is automatically revoked at meeting close or grant-expiration, whichever comes first
**AND** if the absent member joins remotely mid-meeting, the proxy is suspended for any remaining items

## Standards

- **Burgerlijk Wetboek Boek 2** (Dutch civil code book 2, legal entities)
- **eIDAS Regulation (EU 910/2014)** for electronic signatures, particularly QES level
- **Dutch Corporate Governance Code** (Commissie Corporate Governance, MCCG)
- **OECD Principles of Corporate Governance** for international alignment
- **Bpr** (Besluit prudentiële regels Wft) for financial institutions
- **WTZi/WMG** for healthcare boards (NZa supervised)
- **Woningwet** for housing corporation boards (Aw supervised)
- **AVG/GDPR** for personal data of board members, especially nevenfuncties
- **ISO 27001** for information security
- **Wet bestuur en toezicht rechtspersonen (WBTR)** for foundation and association governance
- **SOX Section 404** when applicable to Dutch subsidiaries of US-listed entities

## Cross-app

- **decidesk base**: provides core meeting and agenda primitives, member registry, basic minutes infrastructure
- **docudesk eIDAS**: provides QES signing service, certificate validation against EU Trusted List, archive integrity with SHA-256 anchoring
- **openconnector e-sign**: integrates with Signicat, ConnectiveTrust, Itsme, eHerkenning, DigiD for signature collection; abstracts QES providers so boards can swap providers without app changes
- **openregister**: persists all entities; field-level encryption for sensitive board materials; immutable audit log for vote and conflict records
- **openconnector**: synchronizes with Microsoft Teams, Webex, or Zoom for hybrid meetings, calendar systems (Exchange, Google Workspace) for member availability
- **opentalk**: provides secure end-to-end-encrypted video conferencing for remote board members, with attendance verification matched to portal authentication
- **opencatalogi**: publishes only those resolutions that are legally required to be public (e.g., for housing corporations: remuneration decisions; for listed entities: certain capital decisions)
- **mydash**: executive dashboards for board chairs with attendance, quorum risk, upcoming term-ends, conflict declaration heatmap
- **docudesk**: secure document storage with watermarking, expiration, and forensic access logging
- **opencatalogi external listings**: cross-publishes governance reports to AFM, DNB, or Aw portals where regulators ingest structured data
- **mydash regulator portal**: dedicated read-only views for AFM/DNB/NZa/Aw supervisors with scoped access

## Target Users

- **Supervisory board members (commissarissen)** review materials, attend meetings, vote on resolutions, declare conflicts
- **Executive board members (bestuurders)** propose resolutions, present to supervisory board, vote on operational matters
- **Board chairman** leads meetings, closes votes, signs minutes, manages agenda
- **Company secretary** prepares agenda and materials, drafts minutes, coordinates signature collection, manages portal access
- **External legal counsel** reviews resolutions for legal compliance, advises on governance matters
- **External auditor (accountant)** requests time-bound access to board records for audit purposes
- **Regulator** (AFM, DNB, NZa, Aw) requests access during supervision or investigation
- **Internal audit and compliance** monitor governance practice, report to audit committee
- **Investors and shareholders** (for listed entities) access public portions of governance reporting
- **Works council (ondernemingsraad)** may have observer role in certain board discussions
