## Why

Governance bodies — from municipal councils and water boards to corporate supervisory boards and associations — produce decisions that carry legal force, are subject to statutory deadlines, must be published as structured open data, and require complete audit trails to satisfy compliance obligations under the Awb, Woo, and the Dutch Corporate Governance Code. Market research across 851 user stories and over 2,000 tender documents reveals a clear tier of highest-demand capabilities that go beyond the core post-meeting workflow delivered in p2-minutes-and-decisions.

The single most in-demand feature is **marking a document as a case decision document** (demand 6,216 — more than three times the next feature). Following that: publishing permit decisions (1,581), generating a formal contract document from an award decision (1,482), including the statutory decision deadline in the acknowledgement (1,341), and triggering an urgent/spoed decision process (1,041). The Board Secretary / Company Secretary and Legal Counsel are the primary stakeholders: they need formal document generation, deadline tracking, and complete audit trails on every decision lifecycle event. Without these, clerks and compliance officers manage document marking, statutory deadlines, and urgent notifications in disconnected tools — creating deadline misses, audit gaps, and legal exposure.

This change delivers the highest-demand T1 extensions on top of the p2-minutes-and-decisions foundation: case decision document marking, permit and Woo disclosure PDF generation, contract document generation, statutory deadline calculation and acknowledgement inclusion, urgent decision fast-track with priority notifications, auto-generation of a decision list from voting results, and a complete audit trail for all decision lifecycle events.

## What Changes

- **New**: Case decision document marking — a DigitalDocument linked to a Decision can be marked with `documentType: case-decision`; the Decision detail page shows a "Besluitdocumenten" panel listing all attached case decision documents with type badges
- **New**: Permit decision publication action — for decisions with `legalBasis` referencing a permit regulation, a dedicated "Vergunningsbesluit publiceren" button generates a permit decision PDF using a Dutch legal template and sets `isPublished: true` with `publishedAt`; the generated PDF is stored as a file attachment on the Decision object
- **New**: Generate Woo disclosure decision document — a "Woo-openbaarmakingsbesluit genereren" action on a Decision detail page generates a compliant Woo disclosure PDF using the Dutch standard disclosure template and stores it as a DigitalDocument attached to the Decision
- **New**: Generate contract document from award decision — when a Decision is linked to a procurement outcome, a "Contract genereren" button generates a contract document PDF populated with the decision text, parties, and legal basis; the DigitalDocument is marked with `documentType: contract` and linked to the Decision
- **New**: Statutory decision deadline in acknowledgements — when a decision acknowledgement is drafted, `DecisionDocumentService` calculates the applicable statutory response deadline from the `legalBasis` field (Awb art. 4:13–4:15 mapping) and inserts the deadline date into the acknowledgement text; the deadline is also stored as an ActionItem with `title: "Wettelijke beslistermijn"` and `dueDate` set to the calculated date
- **New**: Urgent decision fast-track — a "Spoedbesluit" action on a Decision in `draft` or early lifecycle state adds tag `spoed` and triggers priority Nextcloud notifications to the chair, secretary, and legal counsel; the Decision list and detail pages display a prominent urgent indicator; the audit trail records who flagged the decision as urgent and when
- **New**: Auto-generate decision list from voting results — after one or more VotingRounds are closed for a meeting, a "Besluitenlijst genereren" action on the linked Minutes object compiles all adopted and rejected decisions (with vote totals) into a formatted Dutch decision list inserted into the Minutes `content` field
- **New**: Complete audit trail for all decision lifecycle events — every status change, publication action, document attachment, deadline update, and urgent flag on Decision, Minutes, and ActionItem objects produces an entry via `AuditTrailService`, surfaced in `CnObjectSidebar` → `CnAuditTrailTab` and exportable via `CnMassExportDialog`

## Capabilities

### New Capabilities

- `case-decision-document-marking`: Mark DigitalDocument objects as `case-decision`, `permit-decision`, `woo-disclosure`, or `contract` types and link them to a parent Decision via OpenRegister relation; display "Besluitdocumenten" panel on Decision detail page with type badges
- `decision-document-generation`: Generate permit decision PDFs, Woo disclosure documents, and contract documents from Decision objects using Dutch legal templates via `DecisionDocumentService`; generated files stored as DigitalDocument objects attached to the Decision via `FileService`
- `statutory-deadline-tracking`: Calculate statutory response deadlines from `legalBasis` (Awb art. 4:13–4:15 mapping); insert deadline into acknowledgement text; create linked ActionItem with `title: "Wettelijke beslistermijn"` and `dueDate`; visual countdown on Decision detail page
- `urgent-decision-fast-track`: Flag a Decision as urgent via `spoed` tag; trigger priority notifications to chair, secretary, and legal counsel; urgent indicator on Decision list and detail; `DecisionService::flagUrgent()` validates the transition and logs to audit trail
- `auto-decision-list-generation`: Compile adopted and rejected Decisions with vote totals from all VotingRounds linked to a Meeting into a formatted Dutch decision list; insert into linked Minutes `content` via `MinutesGenerationService::generateDecisionList()`
- `decision-audit-trail`: Complete per-event audit capture for Decision, Minutes, and ActionItem lifecycle changes via `AuditTrailService`; entries include before/after snapshot, actor, timestamp; exportable via `CnMassExportDialog`

### Modified Capabilities

- `decision-publication` *(from p2-minutes-and-decisions)*: Extended with permit-specific publication workflow (`permit-decision` document type) and Woo disclosure generation alongside the existing generic `isPublished` flag action
- `action-item-tracking` *(from p2-minutes-and-decisions)*: Extended to create and display statutory deadline ActionItems (`title: "Wettelijke beslistermijn"`) linked to a Decision alongside existing open action items
- `minutes-generation` *(from p2-minutes-and-decisions)*: Extended with `generateDecisionList()` method that reads VotingRound results and produces a formatted Dutch decision list for insertion into Minutes content

## Impact

- Adds `DecisionDocumentService.php` as the primary new PHP service (PDF template rendering for permit decisions, Woo disclosures, and contracts); adds `DecisionService.php` for urgent flagging and audit trail dispatch
- No schema changes — Decision, Minutes, ActionItem, and DigitalDocument are all defined in ADR-000 and registered from p1-schemas-and-data-model; the `spoed` tag uses the OpenRegister built-in `tags` array; document type uses the existing `documentType` field on DigitalDocument
- Frontend: adds `DecisionDocumentPanel.vue` embedded in `DecisionDetail.vue`; adds deadline countdown display; adds urgent badge to `CnStatusBadge` on Decision list and detail
- Extends `MinutesGenerationService::generateDecisionList()` added as a new method alongside the existing `generateDraft()` from p2-minutes-and-decisions
- Downstream: p3-ori-publication reads `isPublished` flag and linked DigitalDocument objects introduced here for ORI/PLOOI structured data submission
- Downstream: p3-governance-bodies can filter decisions by `spoed` tag to track urgent decision patterns per governance body
