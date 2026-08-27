---
kind: code
---

# Proposal: vve-alv-pack

## Summary

Add the **VvE ALV statutory pack** to decidiq: a thin statutory layer that makes the existing association machinery serve a Vereniging van Eigenaars (VvE, Dutch homeowners' association). It ships (1) built-in VvE statutory decision templates (decharge bestuur, vaststelling jaarrekening, dotatie reservefonds, vaststelling/actualisatie MJOP, machtiging bestuur voor onderhoud boven een drempel, wijziging huishoudelijk reglement) each carrying its default required majority and quorum, (2) modelreglement presets (1992 / 2006 / 2017) as seeded voting-rule configurations a VvE picks at body setup — overridable per splitsingsakte, (3) breukdelen support: `votingWeight` rendered as a fraction (150/10.000), meeting totals, quorum, and vote results expressed in breukdelen alongside head-count, plus a sum-of-breukdelen = denominator validation warning, (4) a kascommissie flow (verslag upload + verklaring recording linked to the jaarrekening agenda item, feeding the decharge decision), and (5) a VvE-specific ALV statutory-items completeness warning (kascommissieverslag, jaarrekening, begroting, MJOP-status). Everything reuses the existing weighted-voting, qualified-majority, quorum, process-template, and agenda-warning machinery — this change adds statutory content, presentation, and validation, not new engines.

## Motivation

Novelty-verified MISSING (2026-07-17): `grep -rli 'splitsingsakte|breukdel|reservefonds|decharge|MJOP' lib src openspec/specs` returns zero hits; kascommissie exists only as a required ALV agenda-item warning entry in `src/services/agendaRules.js` (agenda-management spec); weighted voting and qualified majorities exist generically (`meeting-attendees` REQ-MAT-006 exposes `Membership.votingWeight`, `voting-system` owns the weighted tally and the simple/2-3/3-4/unanimous thresholds, `process-configuration` owns per-template voting-rule defaults) — but nothing renders breukdelen, nothing knows a modelreglement, and nothing configures splitsingsakte majorities. Market: dedicated VvE tools (Twinq, VvE-Overzicht) own this vertical outright; decidiq's association domain (domain 2 of 5) reaches it with a thin statutory layer instead of a new product. Demand: the mjop-management cluster scores 740 in the intelligence DB and the association segment is a named decidiq governance domain. A VvE ALV today fails on four concrete points: members see "weight 150" instead of "150/10.000e breukdeel", the chair cannot tell whether the 2/3-of-breukdelen quorum for a machtigingsbesluit is met, the kascommissieverklaring that legally precedes decharge has no home, and the statutory agenda warning misses the VvE-specific items.

## Affected Projects

- [ ] Project: `decidiq` — new `VveConfiguration`, `VveDecisionTemplate`, `ModelreglementPreset`, `KascommissieVerklaring` schemas (register.d fragment 57), built-in template + preset + demo-VvE seed data, majority-resolution wiring into the existing round-open defaults, breukdelen presentation + validation in the attendee/voting/results surfaces, kascommissie flow, additive VvE statutory agenda-item list, docs, tests.

No other apps change. OpenRegister is consumed as-is (seeds, FileService attachments, relations are existing capabilities).

## Scope

### In Scope

1. **VvE statutory decision templates as seed data**, following the process-configuration builtIn pattern (`builtIn: true`, read-only, duplicable): decharge bestuur, vaststelling jaarrekening, dotatie reservefonds, vaststelling/actualisatie MJOP, machtiging bestuur (onderhoud boven drempel), wijziging huishoudelijk reglement — each carrying its default required majority (gewone meerderheid vs gekwalificeerd 2/3 or 3/4 + quorum) sourced from the modelreglementen.
2. **Modelreglement presets (1992 / 2006 / 2017)** as seeded voting-rule configurations a VvE picks at body setup via a per-body `VveConfiguration` object, overridable per splitsingsakte. The splitsingsakte document itself is registered in the `governing-documents-register` sibling — this change references it, never duplicates it.
3. **Breukdelen support**: display `votingWeight` as a fraction (150/10.000), meeting totals and quorum expressed in breukdelen, vote results shown in breukdelen alongside head-count — REUSING the existing `votingWeight` machinery (REQ-MAT-006 + the voting-system weighted tally); this change adds only presentation and validation (sum of breukdelen = denominator warning).
4. **Kascommissie flow**: verslag upload + verklaring recording linked to the jaarrekening agenda item, feeding the decharge decision (the decharge itself is a normal Decision instantiated from the template).
5. **ALV statutory-items completeness for VvE bodies**: extend the agenda-management warning *concept* with the VvE items (kascommissieverslag, jaarrekening, begroting, MJOP-status) — as an ADDED requirement in this capability, never a MODIFIED delta on agenda-management.

### Out of Scope

- The annual cycle calendar — the `pc-cyclus` sibling owns recurring year cycles (stated boundary in its proposal); this change owns statutory decision *content*, never cycle steps.
- Financial administration / ledger — no bijdragen, no incasso, no bookkeeping; the reservefonds appears only as a besluit (dotatie), never as an account balance.
- MJOP authoring — only the vaststellings-/actualisatiebesluit and its document attachment; no MJOP editor, no cost tables.
- Kadaster integration — appartementsrechten and breukdelen are entered/seeded, never fetched from the Kadaster.
- Registering the splitsingsakte document — owned by the `governing-documents-register` sibling; this change stores a reference plus the per-akte majority overrides only.
- Changing the weighted tally engine, quorum calculation, or threshold enums — owned by `voting-system`/`process-configuration` and reused as-is.

## Approach

Pure thin-client extension per ADR-022/ADR-037: four new schemas in `lib/Settings/register.d/57-vve-alv-pack.json` (fragment number 57 is assigned to this change; 40–56 and 58–65 belong to siblings — never renumber, never edit `decidesk_register.json`). Statutory content ships as seeds: three `ModelreglementPreset` objects (1992/2006/2017) and six `VveDecisionTemplate` objects, all `builtIn: true`, read-only-but-duplicable exactly like process-configuration's built-ins. A `VveConfiguration` object per governance body binds the body to its preset, its breukdelen denominator, its splitsingsakte reference (governing-documents-register), and any per-akte majority overrides. Majority resolution rides the existing round-open default chain (explicit caller value > VvE resolution > template/method defaults — same precedence discipline as process-configuration). Breukdelen are presentation + validation over the existing `votingWeight`: fraction rendering in attendee lists, quorum display, live tally and results, plus a non-blocking sum-check warning. The kascommissie verklaring is a small object with a FileService verslag attachment linked to the jaarrekening agenda item and referenced by the decharge decision. The VvE statutory agenda-item list is an additive entry set in `src/services/agendaRules.js` activated for bodies that have a `VveConfiguration`. Details in design.md.

## New Dependencies

None. Seeds, FileService attachments, relations, weighted voting, qualified majorities, quorum, the builtIn-template pattern, and the statutory agenda warning all exist in OpenRegister, nc-vue, and decidiq.

## Impact

- `lib/Settings/register.d/57-vve-alv-pack.json` (new — 4 schemas + seed data; fragment number 57 assigned to this change).
- `src/services/agendaRules.js` (edit — additive `STATUTORY_VVE_ITEMS` list + VvE-aware missing-items helper; existing ALV list unchanged).
- Breukdelen presentation in the attendee/quorum/voting/results surfaces (`src/` — fraction formatter + display wiring; no tally change).
- Decision-from-template + majority-resolution wiring (thin service resolving template/preset/override into the existing round-open defaults; built-in read-only guard mirroring `ProcessTemplateService`).
- Kascommissie verklaring recording (dialog per modal-isolation gate + agenda-item linkage).
- Docs + PHPUnit/vitest/e2e per hydra gates.

## Cross-Project Dependencies

- `governing-documents-register` (sibling decidiq change, boundary only — no build dependency): it owns registering governing documents including the splitsingsakte; this change stores a reference and per-akte overrides. Neither change blocks the other (the reference is a plain relation that resolves once both land).
- `pc-cyclus` (sibling decidiq change, boundary only): it owns the recurring annual cycle and records decharge as a step *outcome*; this change owns the VvE statutory decision *templates* including decharge content. Both proposals state the boundary; neither blocks the other.

## Risks

### Risk 1: Wrong statutory majorities shipped as defaults

**Severity:** High — **Mitigation:** every seeded majority/quorum names its modelreglement source in the seed data and design tables; presets are read-only built-ins so a VvE can only *duplicate and adjust*, never silently mutate the canonical preset; the splitsingsakte override exists precisely because individual aktes deviate; exact article-level values are flagged as an open question for juridical review before release.

### Risk 2: Breukdelen scope creep into the tally engine

**Severity:** Medium — **Mitigation:** hard scope rule — presentation + validation only; the weighted tally, thresholds, and quorum calculation stay in voting-system/process-configuration; the sum-of-breukdelen check is a warning, never a save blocker (a VvE mid-mutation legitimately has a temporary mismatch).

### Risk 3: Boundary erosion with pc-cyclus and governing-documents-register

**Severity:** Low — **Mitigation:** boundaries stated in all three proposals; this change never creates cycle steps and never registers documents — references only; fragment number 57 is exclusive to this change.

## Rollback Strategy

Revert the PR — the register fragment disappears, seeds stop being planted, the agendaRules addition and presentation wiring vanish. Existing VvE objects (configurations, verklaringen, duplicated templates) remain soft-retained in OpenRegister; decisions created from templates are ordinary Decision objects and survive untouched. No data migration in either direction.

## Open Questions

- Exact article-level majority/quorum mapping per modelreglement (1992 art. 38 vs 2006/2017 art. 52 qualified-majority clauses, huishoudelijk-reglement wijziging) — seeded values are best-effort from the modelreglementen and MUST be juridically reviewed before release; the preset override mechanism absorbs corrections.
- Whether the annex-1992/2006 kwalificaties need a fourth preset variant (modelreglement 1973 exists in the wild) — deferred; duplicating the 1992 preset covers it.
