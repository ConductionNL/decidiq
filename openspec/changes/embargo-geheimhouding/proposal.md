---
kind: code
---

# Proposal: embargo-geheimhouding

## Summary

Add a geheimhouding (statutory confidentiality) and embargo lifecycle for governance documents to decidesk: a formal `Geheimhouding` record attachable to a document, agenda item, or decision carrying a structured legal ground from a configurable, seeded ground list (Gemeentewet geheimhoudingsartikelen — both pre- and post-2023 article numbering — and Woo art. 5.1 grounds), a bekrachtiging workflow that places the geheimhouding on the next meeting agenda of the confirming body and records the bekrachtigingsbesluit, an opheffing workflow whose lifting besluit routes the object into the normal publication machinery, a member-facing embargo (embargo-until moment with timed release to the wider membership via a scheduled job), a geheimhoudingenregister overview per body with an awaiting-bekrachtiging KPI, a view audit trail for stukken under geheimhouding, and declarative notifications for bekrachtiging deadlines and embargo releases.

## Motivation

Novelty-verified PARTIAL (2026-07-17): decidesk already classifies confidentiality in four places — `AgendaItem.confidentiality` (meeting-pack-board-book REQ-005, enum public/internal/confidential), `Decision.isPublished` (p3-citizen-participation, internal/public/confidential) with a free-text `legalBasis`, the public-publication deny-list + confidential stripping + future-`publicatiedatum` timed release on the public payload, and the commissievergaderingen besloten-onderdeel with separate access and viewer audit (REQ-CVG-010). What is MISSING is exactly the juridical layer griffies are accountable for: document-level embargo with timed release to MEMBERS, structured legal grounds instead of free text, the Gemeentewet bekrachtiging workflow, and the opheffing workflow. Without these, a griffie cannot answer "which geheimhoudingen are active, on what ground, and which still await bekrachtiging" — the exact question a rekenkamer or bestuursrechter asks. Competitor pressure is direct: Notubiz ships "Besloten stuk-embargo" plus DigiD-gated besloten stukken. The Wet bevorderen integriteit en functioneren decentraal bestuur (2023) moved municipal geheimhouding to Gemeentewet art. 87-89, so municipalities are actively re-checking their procedures right now — old records still cite the pre-2023 articles, which is why the ground list ships both labelings and stays configurable.

## Affected Projects

- [ ] Project: `decidesk` — new OR schemas (`Geheimhouding`, `GeheimhoudingGrond`) with declarative lifecycle/aggregations/notifications/relations in the ADR-037 register fragment `lib/Settings/register.d/65-embargo-geheimhouding.json`; additive embargo properties on the existing `DigitalDocument` schema in `lib/Settings/decidesk_register.json`; GeheimhoudingService (impose/bekrachtig/opheffing orchestration), EmbargoReleaseJob, publication guard extension, view-audit logging; manifest fragment `src/manifest.d/embargo-geheimhouding.json`; seed data, docs, tests.
- [ ] Project: `openregister` — consumed only: object storage, RBAC, lifecycle/aggregation/notification dialects, audit trail. No OR code changes (ADR-022).

## Scope

### In Scope

1. **Geheimhouding record**: attachable to a document, agenda item, or decision (`scope`: document/item/decision + target UUID), with structured legal ground (reference to a `GeheimhoudingGrond`), imposed-by (body/chair/college + governance body), imposed-at, and a guarded lifecycle (`opgelegd → bekrachtigd → opgeheven`, with `opgeheven` also reachable directly when no bekrachtiging is required).
2. **Configurable ground list shipped as seeds**: Gemeentewet geheimhoudingsartikelen carrying BOTH the post-2023 art. 87-89 labels and the pre-2023 article labels, plus the Woo art. 5.1 absolute and relative grounds. Grounds are data, not code — admins can add, edit, and deactivate grounds.
3. **Bekrachtiging workflow**: where the ground requires it, the geheimhouding MUST be placed on the next meeting agenda of the confirming body and the bekrachtigingsbesluit recorded as a link to a `Decision`. Non-bekrachtigd within the statutory window → flagged fail-visible (KPI + notification), never auto-lifted (legally cautious: the system reports, humans decide).
4. **Opheffing workflow**: lifting requires a besluit by the imposing/confirming body, recorded with date and optional conditions; on opheffing the object flows into the NORMAL publication machinery — never auto-public, the griffie confirms publication.
5. **Member-facing embargo**: a `DigitalDocument` can carry an `embargoUntil` moment — entitled members see it immediately; wider member access unlocks at that moment via a scheduled job flipping an access field (honest about what RBAC can time-switch); the public side reuses the existing future-`publicatiedatum` primitive unchanged.
6. **Geheimhoudingenregister overview**: active geheimhoudingen per body with ground and bekrachtiging status, plus an awaiting-bekrachtiging KPI, from declarative aggregations.
7. **View audit trail** for stukken under geheimhouding, extending the commissievergaderingen besloten audit precedent (REQ-CVG-010).
8. **Declarative notifications** (ADR-031): bekrachtiging due, embargo released, opheffing recorded.

### Out of Scope

- DigiD-gated PUBLIC access to besloten stukken (portal-side authentication) — decidesk's public surface stays the OR published-predicate + OpenCatalogi route.
- Courtroom / WOB-litigation flows (bezwaar, beroep over geheimhouding).
- Redaction tooling — anonymisation machinery already exists (motion-execution-and-anonymisation).
- Retro-classifying archived records — archival access restrictions belong to records-management-archiving (REQ-RMA-009 `securityClassification` → MDTO `beperkingGebruik`).
- Modifying public-publication's eligibility gates — this change only ADDS a structural refusal for objects under active geheimhouding, consistent with the existing deny-list approach.

## Approach

Declarative-first per ADR-031: two new schemas in ADR-037 fragment `65-embargo-geheimhouding.json` with `x-openregister-lifecycle` (Geheimhouding state machine), `x-openregister-aggregations` (register KPIs), `x-openregister-notifications` (bekrachtiging due, embargo released), and `x-openregister-relations`. This change builds ON the existing confidentiality classifiers — imposing a geheimhouding sets the target's existing classifier (`AgendaItem.confidentiality: confidential`, `Decision.isPublished: confidential`) rather than introducing a parallel vocabulary; the structured ground supersedes the free-text `Decision.legalBasis` for new geheimhouding records while existing free-text values remain untouched. Imperative PHP is limited to justified exceptions: impose/bekrachtig/opheffing orchestration (multi-object transactions with authority guards), the scheduled embargo-release job (time-based access flip that RBAC cannot express per object), the publication-guard check, and view-audit logging. UI ships as an ADR-037 manifest fragment. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/65-embargo-geheimhouding.json` — new fragment: `Geheimhouding` + `GeheimhoudingGrond` schemas, dialects, seeds (fragment numbers 40-64 belong to sibling changes; 65 is assigned to this change).
- `lib/Settings/decidesk_register.json` — additive `embargoUntil`, `embargoActive`, `embargoAudience` properties on `DigitalDocument` (property additions to existing schemas belong in the canonical file; fragments merge whole schemas).
- `lib/Service/` — new GeheimhoudingService; extension of the publication payload/eligibility guard (structural refusal, deny-list-consistent).
- `lib/BackgroundJob/` — new EmbargoReleaseJob (pattern: existing scheduled jobs).
- `src/manifest.d/embargo-geheimhouding.json` — geheimhoudingenregister pages, KPI widgets, impose/bekrachtig/opheffing dialogs.
- `openspec/specs/` — new `embargo-geheimhouding` capability spec; terminology aligned with meeting-pack-board-book (confidentiality enum), public-publication (timed release, deny-list), commissievergaderingen (besloten access + audit), records-management-archiving (classification vocabulary).

## Cross-Project Dependencies

- **OpenRegister** (hard, existing): object storage, RBAC, declarative dialects, audit trail, background-job-driven field flips through the object API. No OR changes.

## Risks

### Risk 1: Auto-lifting a geheimhouding that legally still stands (or vice versa)
**Severity:** High — **Mitigation:** the system NEVER changes a geheimhouding's legal state automatically. Overdue bekrachtiging is flagged (KPI + notification), not auto-lifted; opheffing always requires a recorded besluit; publication after opheffing always requires griffie confirmation through the normal publication machinery.

### Risk 2: Embargo release pretends more precision than RBAC delivers
**Severity:** Medium — **Mitigation:** the design states honestly that group-scoped RBAC rules cannot time-switch per object; member-side release is a scheduled job flipping `embargoActive` with a documented granularity (job interval), and the public side reuses the proven future-`publicatiedatum` predicate. The release notification fires only after the flip actually happened.

### Risk 3: Parallel confidentiality vocabulary drifts from the four existing classifiers
**Severity:** Medium — **Mitigation:** Geheimhouding stores no own confidentiality enum for the target; it references the target and drives the target's EXISTING classifier field. The spec names the mapping per target type; records-management terms (`beperkingGebruik` vocabulary) are reused for classification language.

### Risk 4: 2023 Gemeentewet renumbering confuses users citing old articles
**Severity:** Low — **Mitigation:** seed grounds carry both the current art. 87-89 labels and the pre-2023 labels ("voorheen art. …"); the ground list is fully configurable so municipalities adjust wording without code changes.

## Rollback Strategy

Purely additive: a new register fragment, additive optional properties on `DigitalDocument`, new service/job classes, and a new manifest fragment. Revert the PR and re-import the register: new schemas disappear, existing objects are untouched. Geheimhouding records already created remain in the register (they are legal records); already-released embargoes stay released.

## Open Questions

- Should the bekrachtiging deadline be computed from the confirming body's actual next scheduled meeting or from a configurable statutory window (e.g. "eerstvolgende vergadering")? Provisional: the next scheduled meeting of the confirming body, with a manual override date.
- Should the embargo-release job run every 5 or every 15 minutes? Provisional: 15 minutes (NC background-job friendly), documented as the release granularity.
