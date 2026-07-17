---
kind: code
---

# Proposal: records-management-archiving

## Summary

Give decidesk an Archiefwet archiving lifecycle by **consuming OpenRegister's shipped records-management stack**, not by rebuilding it. OpenRegister already implements retention resolution, Selectielijst storage, MDTO serialization, SIP packaging, e-depot transfer, destruction with dual approval and legal holds, and the `verklaring_van_vernietiging` certificate. Decidesk adds only what OR genuinely lacks: the **archival dossier** (an aggregate unit per meeting/decision route — OR has none), a **compliance dashboard** (OR's is spec-only), a **security-classification** label aligned to OR's confidentiality ordinal, **Selectielijst 2020 seed data** shipped as OR `SelectionList` objects, and **rendering** of OR's destruction certificate to PDF.

## Motivation

This is the #1 unresolved market gap for decidesk with zero current coverage. RIS vendor contracts contractually exclude archiving responsibility; archivists regard iBabs as an information-exchange tool, not an archive (its one-time export webservice to a DMS/zaaksysteem is a paid extra, ~EUR 1,950, single unverified source). Municipalities do not know whether the systems they purchased meet statutory archiving duties despite GIBIT. The intelligence DB lists the unresolved must-features: api-based-records-management-integration (demand 1317), destruction-verification-and-compliance-reporting (1170), council-document-archive (913), auto-archive-council-decisions (755), records-management-compliance-dashboard (632), archive-talk-conversations (497), verify-archive-completeness (490), national-archives-compliance (423), security-classification-labels-on-records (274). The Archiefwet 2021 tightens the transfer deadline to 10 years and expands scope. Decidesk already produces the formal record (approved minutes, enacted decisions with hash-chained audit, proof packages) but has no dossier formation — the one missing link between decidesk's records and OR's archiving engine.

An earlier draft of this change assumed decidesk had to build dossier assembly, MDTO mapping, Selectielijst retention rules, transfer packages, a destruction workflow, the verklaring, and the dashboard, consuming only OR's `_retention`/`_tmlo` columns. **That premise was wrong** (verified against openregister `ebedbdd5a`), and it also rested on two impossible field names. This proposal corrects it: the vast majority of that surface already exists upstream, and duplicating it would violate ADR-022 and create a second, divergent Archiefwet implementation.

## Affected Projects

- [ ] Project: `decidesk` — one new OR schema (ArchivalDossier), one service (ArchivalDossierService), two endpoints (assemble/close), an additive `securityClassification` property, a compliance-dashboard manifest fragment, Selectielijst 2020 seeds as OR `SelectionList` objects, docs, tests.
- [ ] Project: `openregister` — consumed only: `RetentionService`, `Archival/*`, `Edepot/*`, `SelectionList`, `DestructionList`, archival + e-depot routes. No OR changes in this change; genuinely-missing upstream capabilities are recorded as **proposed OR follow-ups** (see spec) rather than built here.
- [ ] Project: `openconnector` — consumed transitively via OR's `Transport/OpenConnectorTransport`. No decidesk-side connector code, no openconnector changes.
- [ ] Project: `docudesk` — consumed only: PDF rendering of the verklaring, markdown fallback.

## Scope

### In Scope

1. **ArchivalDossier assembly** per meeting/decision: bundle minutes, decisions, motions, votes, and attachments into one archival unit with lifecycle `forming → closed → transferred | destroyed`. OR has no aggregate unit — this is decidesk's.
2. **Dossier-level MDTO through OR fields**: populate the dossier's OR `tmlo` / `retention` fields so OR's `MdtoXmlGenerator` emits its MDTO record. No decidesk MDTO mapping table, serializer, or item-level derivation.
3. **Selectielijst 2020 seeds as OR `SelectionList` objects** + the archivable schemas' `archive.classificatie`, so `RetentionService::applyArchivalMetadata()` resolves nominatie / bewaartermijn / archiefactiedatum via `ArchiefactiedatumCalculator`. Decidesk computes no dates.
4. **Transfer by consumption**: create an OR transfer list over the dossier's member UUIDs (`TransferListService`); OR's `EdepotTransferService` + `SipPackageBuilder` + `OpenConnectorTransport` package and deliver.
5. **Destruction by consumption**: OR's destruction lists, approval routes, dual-approval rule, legal holds, and `DestructionCheckJob` / `DestructionExecutionJob`. The dossier reflects the outcome.
6. **Verklaring rendering**: fetch OR's `verklaring_van_vernietiging` certificate and render it (Docudesk PDF, markdown fallback), persisted permanently.
7. **Archive-completeness / compliance dashboard**: declarative aggregations + manifest widgets, reading OR's `retention.archiefactiedatum` / `retention.archiefstatus`. OR's equivalent is spec-only.
8. **Security classification labels**, documented as a subset of OR's `VERTROUWELIJKHEIDAANDUIDING_LEVELS`.

### Out of Scope

- **Anything OpenRegister already ships**: retention resolution, Selectielijst entity, MDTO serialization, SIP/BagIt packaging, e-depot transport, destruction execution, legal holds, dual approval, the destruction certificate. Consumed, never reimplemented (ADR-022).
- Being an e-depot ourselves — OR hands packages to the archive system of record.
- Changes to OpenRegister, OpenConnector, or Docudesk code. Verified upstream gaps are filed as proposed OR follow-ups.
- Archiving Talk conversations (intelligence-DB demand 497) — deferred; the dossier model leaves room for a future `talk-export` member kind.
- Physical records / paper archives.

## Approach

Consumption-first, then declarative-first (ADR-031). The single new schema `ArchivalDossier` lives in `lib/Settings/decidesk_register.json` (via an ADR-037 fragment) with `x-openregister-lifecycle`, `x-openregister-aggregations` (compliance counters), `x-openregister-notifications` (transfer-deadline notices), and `x-openregister-relations`. Imperative PHP shrinks to **one** justified exception: `ArchivalDossierService` (cross-schema enumeration + override-gated close). The former TransferPackageService, ArchiveConnectorService, and DestructionService are all deleted from scope — OR owns those. The dashboard ships as an ADR-037 `src/manifest.d/` fragment. Details in design.md.

## New Dependencies

None. OpenRegister is already a hard dependency. OpenConnector is reached transitively through OR's transport layer, so decidesk gains no connector dependency at all.

## Impact

- `lib/Settings/decidesk_register.json` (+ ADR-037 fragment) — **1** new schema (ArchivalDossier); additive `securityClassification` property on Minutes/Decision/Meeting/DigitalDocument; `archive` config (`enabled`, `classificatie`) on archivable schemas; declarative lifecycle/aggregation/notification/relation blocks; Selectielijst 2020 seeds as OR `SelectionList` objects.
- `lib/Service/` — **1** new service: `ArchivalDossierService`. `PublicationEligibilityService` gains one structural classification check.
- `lib/Controller/` — `ArchivalDossierController` with **2** endpoints (assemble, close).
- `src/manifest.d/records-management.json` — ArchivalDossiers index/detail pages, compliance dashboard widgets, menu entries.
- `openspec/specs/` — new `records-management-archiving` capability spec; terminology aligned with resolution-minutes (MDTO), public-publication (derived payloads, RBAC), decision-management (lifecycle `archived`).
- No background job (OR's `DestructionCheckJob` already sweeps), no migrations, no app tables.

## Cross-Project Dependencies

- **OpenRegister** (hard, existing): `RetentionService::applyArchivalMetadata()`, `Archival/{DestructionService,LegalHoldService,ArchiefactiedatumCalculator}`, `Edepot/{TransferListService,EdepotTransferService,SipPackageBuilder,MdtoXmlGenerator,Transport/*}`, `Db/{SelectionList,DestructionList}`, archival + e-depot routes, the persisted `retention` / `tmlo` fields, audit trail.
- **OpenConnector** (transitive, existing): reached only through OR's `Transport/OpenConnectorTransport`; decidesk holds no connector code.
- **Docudesk** (soft, existing): PDF rendering of the verklaring; markdown fallback per the established minutes pattern.

## Risks

### Risk 1: Irreversible destruction executed on the wrong objects
**Severity:** High — **Mitigation:** decidesk does not destroy anything. Destruction is OR's: OR freezes the approved enumeration, enforces dual approval (`DestructionService` rejects a second approval by the same archivist), re-checks legal holds immediately before each delete, and emits the permanent certificate. Decidesk's exposure is limited to which member UUIDs a dossier enumerates — hence the frozen member list on close.

### Risk 2: Duplicating OpenRegister and drifting from it
**Severity:** High — **Mitigation:** the risk this rewrite exists to close. Every archiving concern that OR ships is out of scope by name (see Scope); the spec carries a "What OpenRegister already provides" table with file citations; verified upstream gaps become proposed OR follow-ups, never decidesk workarounds.

### Risk 3: Targeting the wrong OR field
**Severity:** Medium — **Mitigation:** `_retention` is a **transient, read-only** view from the `x-openregister-archival` TTL mechanism and cannot be written; the Archiefwet block is the persisted `retention` field and the MDTO field is `tmlo` (`_tmlo` does not exist). The spec states this explicitly, and also that `x-openregister-archival` is a log-rotation TTL mechanism that cannot express waardering, Selectielijst categories, or an approval-gated route — so nobody targets it later. Verified against a live OR instance, never fakes.

### Risk 4: Dossier assembly misses records (completeness gap)
**Severity:** Medium — **Mitigation:** completeness check is a declarative aggregation over the meeting's known artefacts (minutes approved? decisions enacted? votes present?) surfaced on the dashboard; closing an incomplete dossier requires an explicit staff override with a stored reason.

### Risk 5: MDTO output rejected by the receiving e-depot
**Severity:** Low (owned upstream) — **Mitigation:** MDTO serialization and SIP validation are OR's. Decidesk's only duty is populating `tmlo` / `retention`. Per-target quirks live in OR/OpenConnector configuration.

## Rollback Strategy

Additive: one new schema plus optional properties — reverting the register import removes the schema without touching existing objects. `ArchivalDossierService` is a new class wired in `Application.php`; reverting the code PR disables the feature. No OR state is created by decidesk beyond dossiers and the transfer/destruction lists that OR owns and can manage independently.

## Open Questions

- Can `end-of-council-term` retention be expressed via OR's `eigenschap` afleidingswijze (a materialised term-end date on the dossier), or does it warrant a first-class OR trigger? Provisional: use `eigenschap`; file the OR follow-up.
- Should Selectielijst `SelectionList` rows be editable per municipality or shipped read-only? Provisional: shipped as editable seeds (municipalities apply local hotspots/exceptions) — consistent with `SelectionList` carrying an `organisation` field.
