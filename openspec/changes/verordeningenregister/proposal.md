---
kind: code
---

# Proposal: verordeningenregister

## Summary

Add a register of local regulations (verordeningen, beleidsregels, nadere regels, reglementen, externe statuten) to decidesk: `Regeling` objects with type, citeertitel, wettelijke grondslag, vaststellend orgaan and CVDR identifier; immutable `RegelingVersie` objects where every version traces to the amending decision (wijzigingsbesluit) that enacted it, with inwerkingtreding/vervaldatum and a consolidated-text document; deterministic "which version is in force on date X" resolution; a public register page of regelingen in force with their consolidated texts; a DROP/STOP-TPOD export package delivered through OpenConnector (honest degradation when the connector is absent); and notifications when an inwerkingtreding approaches without a published consolidated text.

## Motivation

Novelty verification (2026-07-17) shows zero coverage: CVDR, DROP, and consolidation have no hits anywhere in decidesk's specs or code. The gap is explicitly acknowledged by two sibling changes: `notubiz-ibabs-griffie-koppeling` parks "bulk-export naar STOP/TPOD-XML voor officiële bekendmakingen" as "een aparte publicatie-spec" (its proposal.md line 94, context-brief line 153) — this change is that spec's register foundation. And `commissievergaderingen` REQ-CVG-013 requires coupling a Commissie record to a "verordening-versie via immutable-referentie" — a reference that currently points at nothing, because decidesk has no verordening-versie entity. Market demand is high: ordinance-management 740, legislative-management 740 (intelligence DB). Decidesk already produces the amending decision (Decision supertype, decision-management spec) and the evolution linkage (decision-evolution-and-cascade), but the enacted *result* — the regulation as a versioned, consolidated, publishable legal text — has no home. Municipalities are legally required (Gemeentewet art. 139-144, Bekendmakingswet/Wep) to publish regulations via DROP into the CVDR; decidesk should hold the authoritative register and hand the publication package to the connector layer.

## Affected Projects

- [ ] Project: `decidesk` — new OR schemas (`regeling`, `regeling-versie`, `regeling-export-package`) in register fragment `lib/Settings/register.d/53-verordeningenregister.json` with declarative lifecycle/relations/notifications (ADR-031 dialects), a RegelingConsolidationService (in-force resolution) and RegelingExportService (STOP/TPOD package generation, imperative document-generation exception), OpenConnector delivery via lazy slug lookup, public register page, list/detail pages, seed data, tests.
- [ ] Project: `openconnector` — consumed only: a configured Source (slug `drop-bekendmakingen`) for DROP delivery, same lazy-lookup pattern as the existing `eidas-qes` Source and the records-management-archiving archive Source. No openconnector code changes.
- [ ] Project: `openregister` — consumed only: ObjectService storage, declarative lifecycle enforcement, file attachments for consolidated texts, published-predicate RBAC for the public page (ADR-022). No OR changes.

## Scope

### In Scope

1. **Regeling schema**: type (`verordening` / `beleidsregel` / `nadere-regel` / `reglement` / `statuut-extern`), citeertitel, officiële titel, wettelijke grondslag (legal basis references), vaststellend orgaan (GovernanceBody reference), status lifecycle (`in-voorbereiding → vastgesteld → in-werking → vervallen`), CVDR identifier field.
2. **RegelingVersie objects**: version number, `vastgesteldDoor` Decision link (the wijzigingsbesluit — every version MUST trace to its amending decision), inwerkingtreding date, optional vervaldatum, consolidated text document attached via OpenRegister's file abstraction, immutable once in-werking. This is the immutable version-reference pattern commissievergaderingen REQ-CVG-013 consumes.
3. **Current-consolidated-version resolution**: deterministic resolution of which version of a regeling is in force on an arbitrary date.
4. **Publication**: public register page listing regelingen in force with their consolidated texts (public-publication conventions: server-side eligibility, OR published-predicate RBAC); DROP/STOP-TPOD export package generation delegated through OpenConnector following the records-management-archiving connector-delivery pattern — decidesk produces the package and calls the connector; it never speaks to officielebekendmakingen.nl itself. Honest degradation (downloadable package, truthful UI) when the connector or Source is absent.
5. **Notifications**: warn responsible staff when an inwerkingtreding approaches without a published consolidated text.
6. **List/detail pages** for regelingen and their version timelines.
7. **Seed data**: example regelingen with version chains linked to seed decisions.

### Out of Scope

- Being a bekendmakingsplatform — decidesk never publishes to officielebekendmakingen.nl directly; DROP delivery is the connector's job.
- STOP/TPOD XML authoring UI — the export package is generated from stored data; no interactive XML editor.
- Juridical drafting/redlining of regulation texts — the existing amendment-diff machinery covers proposal texts; consolidated regulation texts are uploaded/attached documents, not authored in decidesk.
- Provincial/waterschap-specific CVDR extensions — validate later on concrete demand.

## Approach

Thin-client per ADR-022: all three entities are OpenRegister schemas in a new register fragment (`53-verordeningenregister.json`, ADR-037 merge-at-load). Lifecycles, relations, and notifications are declarative (ADR-031 dialects). Two narrow imperative services carry the accepted exceptions: consolidation resolution (pure read-side computation exposed to the UI and to other capabilities) and STOP/TPOD package generation + OpenConnector delivery (document-generation and external-integration exceptions, mirroring records-management-archiving's TransferPackage pattern). The public page reuses public-publication's eligibility + published-predicate approach. Details in design.md.

## New Dependencies

None. STOP/TPOD XML is generated with the PHP XML tooling already in use; delivery reuses the existing OpenConnector lazy-Source pattern.

## Impact

- New register fragment `lib/Settings/register.d/53-verordeningenregister.json` (number 53 is assigned to this change; 40–52 and 54–65 belong to sibling changes).
- New services `RegelingConsolidationService`, `RegelingExportService`; new controller surface for the public register page and export triggers.
- New Vue pages (regelingen list, regeling detail with version timeline, public register page) and Pinia store wiring.
- commissievergaderingen REQ-CVG-013 gains a real target for its immutable verordening-versie reference (consumption stays in that change).
- No existing schema is modified; all requirements are ADDED.

## Cross-Project Dependencies

- **openconnector** (soft): DROP delivery requires a configured Source; absence degrades honestly to manual download.
- **commissievergaderingen** (consumer, same repo): REQ-CVG-013 will reference `regeling-versie` objects once both changes land; no ordering constraint because the reference pattern degrades to a plain UUID field.
- **notubiz-ibabs-griffie-koppeling** (sibling): this change delivers the register foundation its out-of-scope note defers to; the sync change itself is untouched.

## Risks

### Risk 1: STOP/TPOD package rejected by DROP

**Severity:** Medium — **Mitigation:** decidesk validates the generated package structurally before marking it `ready` and stores validation errors on the package object; delivery failures surface to staff and are retryable without state corruption (same contract as records-management-archiving REQ-RMA-004/005). The authoritative acceptance check remains on the DROP side; the package is downloadable for manual submission as fallback.

### Risk 2: Immutability vs correction of an in-force version

**Severity:** Medium — **Mitigation:** immutability is enforced server-side once a version reaches `in-werking`; corrections follow the legal reality — a rectificatie is a *new* version traced to a new (rectification) decision, never an edit of the sealed one.

### Risk 3: In-force resolution ambiguity (overlapping or gapped versions)

**Severity:** Low — **Mitigation:** the resolution rule is specified deterministically (latest inwerkingtreding ≤ date, not expired, not superseded) and validation refuses activating a version whose inwerkingtreding precedes that of the currently sealed latest version.

## Rollback Strategy

Remove the register fragment `53-verordeningenregister.json`, the two services, the routes, and the Vue pages; re-run register import. No existing schema is touched, so rollback cannot affect other capabilities' data. Regeling objects already created remain inert in OR (soft-deletable via standard OR tooling).

## Open Questions

None blocking. Deferred questions are listed in the change summary (CVDR identifier assignment flow, DROP Source slug convention, provincial variants).
