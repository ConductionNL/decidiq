---
kind: code
---

# Proposal: governing-documents-register

## Summary

Add a versioned register of an organisation's own constitutive and internal governing documents to decidiq: `GoverningDocument` objects (statuten, huishoudelijk reglement, reglement van orde, directiestatuut, splitsingsakte) owned by a GovernanceBody; immutable `GoverningDocumentVersie` objects where every amendment traces to the Decision that enacted it (besluit tot statutenwijziging etc.) and carries a consolidated-text document, with optional notarial-deed metadata (aktedatum, notaris) for statuten and splitsingsaktes; deterministic "which version is in force on date X" resolution with a version timeline on the detail page; a simple `{document, versie?, artikel?}` reference shape so decisions (and sibling changes) can cite a governing-document article; member access by default with an optional public predicate (e.g. statuten of a vereniging); and declarative notifications when a new version takes effect.

## Motivation

Novelty verification (2026-07-17) shows this is missing: statuten and reglement van orde appear in decidiq only as legal-reference prose (e.g. urgent-decision-procedure cites "BW 2:8/statuten" as a standard and explicitly puts "legal advice on which statutes/reglementen permit an urgent procedure" out of scope); `decidesk-contract-decision-hub` covers contracts and report adoption only; there are zero hits for governing document, bylaws, or consolidation of an organisation's own constitutive documents. Yet every one of decidiq's five governance domains runs on such documents: a gemeenteraad has a reglement van orde (Gemeentewet art. 16), a vereniging has statuten and a huishoudelijk reglement (BW 2:27), a corporate board has a directiestatuut, a VvE has a splitsingsakte (BW 5:111). Decidiq already produces the amending decision (decision-management) and the evolution linkage (decision-evolution-and-cascade), but the enacted result — the governing document as a versioned, consolidated text — has no home. Two sibling changes already need it as a reference target: urgent-decision-procedure's "which statutes permit urgency" and vve-alv-pack's splitsingsakte reference will point at these objects. The verordeningenregister sibling deliberately does not cover this: it owns public-law regelingen with CVDR/DROP publication; the organisation's own private-law/internal documents need their own register without any bekendmaking machinery.

## Affected Projects

- [ ] Project: `decidiq` — new OR schemas (`governing-document`, `governing-document-versie`) plus an additive `citesGoverningDocuments` property on the existing `decision` schema, all in register fragment `lib/Settings/register.d/55-governing-documents-register.json` with declarative lifecycle/relations/notifications (ADR-031 dialects); a `GoverningDocumentConsolidationService` (in-force resolution + activation-ordering guard); list/detail pages with a version timeline; seed data; tests.
- [ ] Project: `openregister` — consumed only: ObjectService storage, declarative lifecycle enforcement, file attachments for consolidated texts (OR file abstraction), published-predicate RBAC for the optional public exposure (ADR-022). No OR changes.

## Scope

### In Scope

1. **GoverningDocument schema**: type (`statuten` / `huishoudelijk-reglement` / `reglement-van-orde` / `directiestatuut` / `splitsingsakte` / `other`), owning GovernanceBody reference, citeertitel/name, status lifecycle (`geldend → vervallen`).
2. **GoverningDocumentVersie objects**: version number, `vastgesteldDoor` Decision link (the besluit tot statutenwijziging / vaststellingsbesluit — required for every amendment version; optional only for the first, historical/constitutive version), effective date (inwerkingtreding), consolidated-text document via OpenRegister's file abstraction, immutable once effective; for statuten and splitsingsaktes optional notarial-deed metadata as plain fields (aktedatum, notaris name).
3. **Current-version resolution**: deterministic resolution of which version is in force on an arbitrary date, exposed as a service method, an endpoint, and a version timeline on the detail page.
4. **Cross-links**: a simple, canonical reference shape (`document` object ref + optional sealed `versie` ref + optional `artikel` string); the `decision` schema gains an additive optional `citesGoverningDocuments` array (assistive reference, never blocking); the urgent-decision-procedure and vve-alv-pack siblings consume this shape — their wiring stays in those changes.
5. **Access**: internal member access by default via OR RBAC; optional public predicate on a live object (isPublished-style, e.g. statuten of a vereniging) exposed through OR's published-predicate RBAC surface, reusing — never modifying — public-publication's conventions.
6. **Notifications**: declarative notification to members when a new version takes effect.
7. **Seed data**: statuten of a vereniging (with notarial metadata), a reglement van orde of a gemeenteraad, and a VvE splitsingsakte, with version chains traced to seed decisions.

### Out of Scope

- Public-law regelingen with CVDR/DROP publication — the verordeningenregister sibling owns those. The boundary is explicit: verordeningen = public-law regulations subject to bekendmaking (CVDR/DROP/STOP-TPOD); this register = the organisation's own private-law/internal constitutive documents, no publication machinery.
- Juridical drafting/redlining of document texts — consolidated texts are uploaded/attached documents, not authored in decidiq.
- Notarial integration (KNB/notaris systems) — aktedatum and notaris are plain metadata fields, nothing more.
- The consuming wiring in urgent-decision-procedure and vve-alv-pack — those changes point at these objects themselves.

## Approach

Thin-client per ADR-022: both entities are OpenRegister schemas in the ADR-037 register fragment `55-governing-documents-register.json` (merge-at-load), which also carries the additive `citesGoverningDocuments` property on the existing `decision` schema (same fragment-located additive pattern as decidesk-contract-decision-hub REQ-DCDH-001). Lifecycles, relations, and notifications are declarative (ADR-031 dialects). One narrow imperative service carries the accepted exception: `GoverningDocumentConsolidationService` for date-parameterised in-force resolution and the activation-ordering guard — the same pattern as verordeningenregister's `RegelingConsolidationService`, whose RegelingVersie conventions this change deliberately mirrors (versienummer, vastgesteldDoor, inwerkingtreding, seal-on-effective). Details in design.md.

## New Dependencies

None.

## Impact

- New register fragment `lib/Settings/register.d/55-governing-documents-register.json` (number 55 is assigned to this change; 40–54 and 56–65 belong to sibling changes).
- New service `GoverningDocumentConsolidationService`; one small controller for the in-force endpoint.
- New Vue pages (governing-documents list, detail with version timeline) via a manifest fragment; Pinia store wiring.
- The `decision` schema gains one additive optional property; no required field, existing decisions stay valid; no existing requirement is modified — all spec deltas are ADDED.
- urgent-decision-procedure and vve-alv-pack gain a real target for their statuten/splitsingsakte references (consumption stays in those changes).

## Cross-Project Dependencies

- **openregister** (consumed): object storage, lifecycle enforcement, file abstraction, published-predicate RBAC (ADR-022). No changes.
- **urgent-decision-procedure / vve-alv-pack** (consumers, same repo): will reference `governing-document`/`governing-document-versie` objects via the REQ-GDR-005 shape once both land; no ordering constraint — the shape degrades to a plain UUID + string field.
- **verordeningenregister** (sibling boundary): owns public-law regelingen; this change adds no CVDR/DROP surface and never touches its schemas.

## Risks

### Risk 1: Domain overlap with the verordeningenregister sibling

**Severity:** Medium — **Mitigation:** the boundary is stated normatively in both the spec and design: public-law regelingen with bekendmaking belong to `regeling`/`regeling-versie`; the organisation's own private-law/internal constitutive documents belong here. The verordeningenregister's `reglement`/`statuut-extern` Regeling types cover externally imposed texts kept as reference; whether its `huishoudelijk-reglement-vng` seed should migrate to this register is recorded as a deferred question, not silently changed.

### Risk 2: Immutability vs correction of an effective version

**Severity:** Medium — **Mitigation:** immutability is enforced server-side once a version takes effect (same seal semantics as verordeningenregister REQ-VOR-003); corrections are a new version traced to its own decision, never an edit of the sealed one.

### Risk 3: Constitutive first versions have no enacting Decision

**Severity:** Low — **Mitigation:** the trace rule is precise: every version of a document that already has a sealed version MUST carry `vastgesteldDoor`; only the first (historical/constitutive) version MAY omit it, recording notarial-deed metadata instead. This keeps the amendment chain fully traced without fabricating fake decisions for founding deeds.

## Rollback Strategy

Remove the register fragment `55-governing-documents-register.json`, the service, the routes, and the Vue pages; re-run register import. The `decision` property is additive and nullable, so removing the fragment cannot invalidate existing decisions. Governing-document objects already created remain inert in OR (soft-deletable via standard OR tooling).

## Open Questions

None blocking. Deferred questions are listed in the change summary (migration of the verordeningenregister `huishoudelijk-reglement-vng` seed; whether the public predicate should also expose historical sealed versions or only the current one; OR immutable-after-state enforcement availability).
