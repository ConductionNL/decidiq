---
kind: code
---

# Proposal: woo-diwoo-publication

## Summary

Add a Woo/DiWoo compliance layer on top of decidesk's existing publication machinery: a `WooCategorieMapping` configuration schema that maps every publishable object type (meeting agenda, besluitenlijst, verslag/Minutes, Motie, Toezegging, Raadsinformatiebrief, Regeling) to a Woo informatiecategorie expressed as a TOOI waardelijst URI; a `diwoo` metadata block (informatiecategorie, bestuursorgaan TOOI id, openbaarmakingsdatum, documenthandeling) decorated onto every publication payload the existing builders produce; a harvestable Woo-index (DiWoo sitemap) endpoint so KOOP/LV Woo can harvest decidesk publications, plus optional push delivery via OpenConnector with honest degradation; a coverage report showing which published types/objects lack a Woo category; and an admin mapping UI in settings. The change is strictly additive — it decorates the public-publication payloads and never changes their eligibility rules.

## Motivation

The Woo (Wet open overheid) obliges decentrale overheden to actively publish vergaderstukken, phased in via KOOP's Landelijke Voorziening Woo (LV Woo); publications must carry DiWoo metadata (informatiecategorie from the TOOI waardelijst, bestuursorgaan identifier) and be discoverable through the Woo-index. Novelty-verified missing in decidesk (2026-07-17): no informatiecategorie anywhere, no DiWoo metadata, no Woo-index koppeling; TOOI appears only as an MDTO archiefvormer org id in records-management-archiving; `openspec/config.yaml` lists Woo and TOOI as target standards but nothing implements them; portal-contribution merely labels `publicatiedatum` as the "WOO/DIWOO publication date". Competitors already ship this: Notubiz has a "Woo-publicatie module", "DiWoo Woo-index koppeling" and "TOOI thesaurus-binding"; GO ships "GO Wob/Woo" + "Woo Publicatie". Without this layer a municipality on decidesk cannot meet its actieve-openbaarmaking duty for vergaderstukken, which makes it a procurement blocker.

## Affected Projects

- [ ] Project: `decidesk` — new register fragment `lib/Settings/register.d/58-woo-diwoo-publication.json` (WooCategorieMapping schema + seeds), `diwoo` decoration in the existing publication payload builders, Woo-index sitemap endpoint, optional LV Woo push service via OpenConnector, coverage aggregations + dashboard widget, admin mapping UI section, docs, tests.
- [ ] Project: `openconnector` — consumed only (optional configured Source for push delivery to an LV Woo aggregation point, same lazy-lookup pattern as the `eidas-qes` and planned archive Sources). No openconnector code changes.
- [ ] Project: `openregister` — consumed only: object API, RBAC published-predicate surface, declarative dialects. No OR changes (ADR-022).

## Scope

### In Scope

1. **WooCategorieMapping config schema** (fragment 58): maps each publishable decidesk object type to a Woo informatiecategorie as a TOOI waardelijst URI (e.g. the *vergaderstukken decentrale overheden* category), with a per-type default and a per-object override captured at publish time.
2. **DiWoo metadata decoration**: when an object is published through the existing publication machinery, its public payload gains a `diwoo` block — informatiecategorie TOOI URI, bestuursorgaan TOOI id (same org-id convention as records-management-archiving's MDTO archiefvormer, e.g. `gm0344`), openbaarmakingsdatum, documenthandeling. Pure decoration of the payload builders; no eligibility change.
3. **Woo-index exposure**: a harvestable, publicly accessible DiWoo sitemap endpoint listing published objects with their DiWoo references (primary, harvest-based — this is how the LV Woo actually ingests), plus optional push delivery through an OpenConnector Source with honest degradation when absent (mirrors records-management REQ-RMA-005).
4. **Coverage report**: aggregate view + KPI of published object types/objects lacking a Woo category mapping or DiWoo block.
5. **Admin mapping UI**: a settings section where admins manage the type→categorie mappings and the bestuursorgaan TOOI id.

### Out of Scope

- **Woo verzoeken** (passieve openbaarmaking) — request intake/handling is a different capability; p2-minutes-and-decisions-core-t1 REQ-PPD-002 already covers generating the Woo-openbaarmakingsbesluit *document* for such a decision and stays untouched.
- **Anonymisation/PII rules** — the existing public-publication payload builders own PII stripping; this change adds metadata only.
- **Being the public portal** — rendering published information to citizens is portaliq/OpenCatalogi territory; decidesk only exposes the harvestable index.
- **Changes to public-publication eligibility gates or the type deny-list** — two sibling changes already layer on that requirement; this change never modifies it.

## Approach

Declarative-first per ADR-031: WooCategorieMapping is an OR schema in an ADR-037 register fragment (58) with seeds for the standard mappings; coverage counters use `x-openregister-aggregations` where expressible. Imperative PHP is limited to justified exceptions: a `DiWooMetadataService` that resolves the mapping and composes the `diwoo` block inside the existing payload-builder flow (derived-data composition at publish time), the public Woo-index sitemap controller (document/serialization exception, pattern: ori-api public endpoint), and an optional `WooIndexConnectorService` for push delivery (external-integration exception, pattern: `ArchiveConnectorService`). Admin UI extends the existing decidesk admin settings. Details in design.md.

## New Dependencies

None. OpenConnector remains an optional runtime dependency; the LV Woo push Source is configuration, not code. TOOI waardelijst URIs ship as seed data, not as a runtime dependency on the TOOI register.

## Impact

- `lib/Settings/register.d/58-woo-diwoo-publication.json` — new fragment: WooCategorieMapping schema, coverage aggregations, seeds.
- Existing publication payload builders (public-publication machinery) — gain a decoration step attaching the `diwoo` block; payload shape is extended additively, existing fields untouched.
- `appinfo/routes.php` + a new public controller — Woo-index sitemap endpoint (`#[PublicPage]`, read-only, serves only already-public data).
- Admin settings UI — new Woo mapping section.
- `openspec/specs/` — new `woo-diwoo-publication` capability spec; public-publication, ori-api, portal-contribution specs unchanged.

## Cross-Project Dependencies

- **OpenRegister** (hard, existing): object API, RBAC published-predicate surface, aggregation/seed dialects.
- **OpenConnector** (soft, optional): push delivery of Woo-index notifications to an LV Woo aggregation point; harvest path works without it.
- **Sibling decidesk changes** (soft, naming only): motie-amendement-administratie (`Motie`), toezeggingen-ingekomen-stukken (`Toezegging`), raadsinformatiebrieven (`Raadsinformatiebrief`, fragment 51), verordeningenregister (`Regeling`). Mappings for types whose change has not landed yet degrade to inactive seed rows — nothing breaks when a sibling is absent.

## Risks

### Risk 1: Wrong or fabricated TOOI concept URIs ship in seeds
**Severity:** High — **Mitigation:** seed URIs are taken verbatim from the published TOOI waardelijst *woo-informatiecategorieën* at implementation time (never invented); the schema validates the URI pattern (`https://identifier.overheid.nl/tooi/...`); the coverage report flags unmapped types instead of silently defaulting.

### Risk 2: DiWoo metadata declared compliant but rejected by the LV Woo harvester
**Severity:** Medium — **Mitigation:** the sitemap serialization follows the published DiWoo XML schema; build-time validation of the diwoo block against required fields; harvest is idempotent and re-runs, so a rejected entry is fixable by rectify + re-harvest.

### Risk 3: Public sitemap endpoint leaks non-public data
**Severity:** Medium — **Mitigation:** the endpoint enumerates only payload objects whose `publicatiedatum` is set and past (and not depublished) — the same predicate OR's anonymous surface enforces; it serves references + DiWoo metadata, never payload bodies; Newman negative tests assert withdrawn items disappear.

### Risk 4: Sibling schema names drift (Motie/Toezegging/Raadsinformatiebrief/Regeling not yet landed)
**Severity:** Low — **Mitigation:** mappings reference types by schema slug; seed rows for not-yet-landed types are inert until the schema exists; coverage report shows them as "type not installed" rather than erroring.

## Rollback Strategy

Purely additive: revert the PR and re-import the register — the fragment's schema and seeds are removed without touching existing objects; the `diwoo` block on already-published payloads is an optional property and remains valid-but-unused; the sitemap route disappears with the code. Publications already harvested by the LV Woo remain at the harvester (as with any depublication, the withdraw flow is the instrument, not rollback).

## Open Questions

- Which LV Woo connection mode does the first pilot municipality need — harvest of our Woo-index sitemap only (provisional choice), or additionally an active push/notify to an aggregation point? Provisional: sitemap mandatory, push optional behind OpenConnector.
- Should the per-object categorie override be restricted to categories from the TOOI waardelijst, or may municipalities add local extension URIs? Provisional: waardelijst-only, validated by URI pattern.
