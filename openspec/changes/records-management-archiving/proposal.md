---
kind: code
---

# Proposal: records-management-archiving

## Summary

Add a records-management and Archiefwet archiving lifecycle to decidesk: archival dossiers assembled per meeting/decision, MDTO metadata on all archivable objects (extending the pattern the resolution-minutes spec already mandates for minutes), retention schedules per Selectielijst gemeenten 2020 mapped onto OpenRegister's object-level `_retention` abstraction, MDTO-compliant transfer (overbrenging) export packages delivered to a DMS/zaaksysteem/e-depot via OpenConnector, an authorized destruction workflow ending in a verifiable vernietigingsverklaring, a declarative archive-completeness/compliance dashboard, and security classification labels on archival records.

## Motivation

This is the #1 unresolved market gap for decidesk with zero current coverage. RIS vendor contracts contractually exclude archiving responsibility; archivists regard iBabs as an information-exchange tool, not an archive (its one-time export webservice to a DMS/zaaksysteem is a paid extra, ~EUR 1,950, single unverified source). Municipalities do not know whether the systems they purchased meet statutory archiving duties despite GIBIT. The intelligence DB lists the unresolved must-features: api-based-records-management-integration (demand 1317), destruction-verification-and-compliance-reporting (1170), council-document-archive (913), auto-archive-council-decisions (755), records-management-compliance-dashboard (632), archive-talk-conversations (497), verify-archive-completeness (490), national-archives-compliance (423), security-classification-labels-on-records (274). The Archiefwet 2021 tightens the transfer deadline to 10 years and expands scope. Decidesk already produces the formal record (approved minutes, enacted decisions with hash-chained audit, proof packages) but has no dossier formation, no retention schedule, no transfer, and no destruction accountability — the exact gap every competitor leaves open.

## Affected Projects

- [ ] Project: `decidesk` — new OR schemas (ArchivalDossier, RetentionRule, TransferPackage, DestructionList), MDTO/classification properties on existing archivable schemas, declarative lifecycle/aggregations/notifications in `lib/Settings/decidesk_register.json`, TransferPackageService + DestructionService + ArchiveConnectorService (imperative exceptions), compliance-dashboard manifest fragment, seed data, docs, tests.
- [ ] Project: `openconnector` — consumed only (a configured Source per archive target, same lazy-lookup pattern as the existing `eidas-qes` e-sign Source). No openconnector code changes expected.
- [ ] Project: `openregister` — consumed only: object-level `_retention` and `_tmlo` columns, retention-aware `deleteObject` (soft delete), audit trail. No OR changes; decidesk never reimplements storage (ADR-022).

## Scope

### In Scope

1. **ArchivalDossier assembly** per meeting/decision: bundle minutes, decisions, motions (decisionType-typed decisions), votes, and attachments into one coherent archival unit with its own lifecycle (`forming → closed → transferred | destroyed`).
2. **MDTO metadata mapping** for all archivable objects — dossier-level MDTO record plus per-item MDTO derivation at export time, extending the MDTO commitment the resolution-minutes spec already carries.
3. **Retention schedules** per Selectielijst gemeenten 2020: RetentionRule objects (category, retention period, trigger event) applied to dossiers; the resolved schedule is written to OR's `_retention` on the dossier's member objects.
4. **Transfer (overbrenging) export packages**: MDTO-compliant sidecar metadata + documents, generated as a TransferPackage and delivered via API export to DMS/zaaksysteem/e-depot through OpenConnector. Decidesk only produces the package and calls the connector.
5. **Destruction workflow**: proposal list (vernietigingslijst) → authorization → execution via OR retention-aware deletion → destruction verification report (vernietigingsverklaring) retained permanently.
6. **Archive-completeness / compliance dashboard**: declarative `x-openregister-aggregations` + dashboard widget where possible; shows dossiers overdue for transfer, unassigned retention categories, completeness gaps.
7. **Security classification labels** on archival records (openbaar / intern / vertrouwelijk / geheim), respected by transfer and publication surfaces.

### Out of Scope

- Being an e-depot ourselves — decidesk hands packages to the archive system of record.
- Archiving Talk conversations (intelligence-DB demand 497) — deferred; the dossier model leaves room for a future `talk-export` member kind.
- Physical records / paper archives.
- Changes to OpenRegister or OpenConnector code.

## Approach

Declarative-first per ADR-031: the four new schemas live in `lib/Settings/decidesk_register.json` with `x-openregister-lifecycle` (dossier and destruction-list state machines), `x-openregister-aggregations` (compliance counters), `x-openregister-notifications` (transfer-deadline and destruction-authorization notices), and `x-openregister-relations`. Imperative PHP is limited to three justified exceptions: package generation (document/sidecar production), the OpenConnector delivery call (external integration, lazy source lookup like `EIDASSignatureService`), and destruction execution (scheduled bulk OR deletions + verklaring generation). The dashboard ships as an ADR-037 `src/manifest.d/` fragment. Details in design.md.

## New Dependencies

None. OpenConnector remains an optional runtime dependency (already declared for eIDAS QES); a new archive-export Source slug is configuration, not code.

## Impact

- `lib/Settings/decidesk_register.json` — 4 new schemas; `mdto` + `securityClassification` properties added additively to Minutes, Decision, Meeting, DigitalDocument; new declarative lifecycle/aggregation/notification/relation blocks.
- `lib/Service/` — new TransferPackageService, DestructionService, ArchiveConnectorService (+ log-fallback), extension of seed/settings wiring.
- `lib/BackgroundJob/` — retention/transfer-deadline sweep job (pattern: TranscriptRetentionJob).
- `src/manifest.d/records-management.json` — ArchivalDossiers index/detail pages, compliance dashboard widgets, menu entries.
- `openspec/specs/` — new `records-management-archiving` capability spec; terminology aligned with resolution-minutes (MDTO), public-publication (derived payloads, RBAC), decision-management (lifecycle `archived`).

## Cross-Project Dependencies

- **OpenRegister** (hard, existing): `_retention`/`_tmlo` object columns, retention-aware `deleteObject`, audit trail, declarative dialects.
- **OpenConnector** (soft, existing): delivery of TransferPackages to DMS/zaaksysteem/e-depot; graceful degradation when absent (package still produced and downloadable, delivery marked pending).
- **Docudesk** (soft, existing): PDF rendering of the vernietigingsverklaring; markdown fallback per the established minutes pattern.

## Risks

### Risk 1: Irreversible destruction executed on the wrong objects
**Severity:** High — **Mitigation:** two-step human authorization (proposer ≠ authorizer), enumerated object list frozen at proposal time, execution through OR's retention-aware soft delete (never hard purge from decidesk), permanent vernietigingsverklaring listing exactly what was destroyed.

### Risk 2: MDTO mapping declared compliant but rejected by the receiving e-depot
**Severity:** Medium — **Mitigation:** MDTO sidecar validated against the MDTO XSD/JSON schema at package-build time; package status `failed-validation` blocks delivery; per-target mapping quirks live in OpenConnector configuration, not decidesk code.

### Risk 3: Retention resolution disagrees with OR's `_retention` semantics
**Severity:** Medium — **Mitigation:** decidesk only writes `_retention` through OR's public API abstraction (ADR-022); the compliance dashboard surfaces mismatches instead of auto-correcting; seed data includes both permanent-retention (V) and destruction (B) Selectielijst categories.

### Risk 4: Dossier assembly misses records (completeness gap)
**Severity:** Medium — **Mitigation:** completeness check is declarative aggregation over the meeting's known artefacts (minutes approved? decisions enacted? votes present?) surfaced on the dashboard; transfer of an incomplete dossier requires an explicit staff override with reason.

## Rollback Strategy

All schema additions are additive (new schemas, new optional properties) — reverting the register import removes the new schemas without touching existing objects. Services and background jobs are new classes wired in `Application.php`; reverting the code PR disables the feature. Destruction executions already performed cannot be rolled back by design (that is the legal point of a vernietigingsverklaring); transfer packages already delivered remain at the receiving archive.

## Open Questions

- Which MDTO serialization does the first pilot target require (MDTO-XML vs MDTO-JSON)? Provisional: generate MDTO-XML sidecars (the National Archives reference format) with the mapping table structured so JSON can be added later.
- Should retention categories be editable per municipality or shipped read-only from Selectielijst 2020? Provisional: shipped as seed objects, editable by admins (municipalities apply local hotspots/exceptions).
