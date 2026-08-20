---
kind: code
---

# Proposal: shared-governance-bodies

## Summary

Add shared governance bodies (gemeenschappelijke regelingen per Wgr, joint committees, federaties) to decidesk: a `BodyParticipation` register that links a shared GovernanceBody to the member GovernanceBodies participating in it (with per-participant seats, voting weight, and toetreding/uittreding dates), membership provenance so a person's Membership in the shared body records namens which participating organisation they sit, and the core Wgr workflow — the zienswijzeprocedure: the shared body opens a zienswijzeronde on a document or decision (e.g. de ontwerpbegroting van de GR), every participating organisation receives it with a tracked deadline, records its zienswijze (standpunt + response document), and the shared body sees the aggregated zienswijzen overview and records how each was processed. Delivered as a `lib/Settings/register.d/56-shared-governance-bodies.json` schema fragment plus manifest pages, with declarative lifecycles (`x-openregister-lifecycle`), declarative deadline rappels (`x-openregister-notifications`, the toezeggingen-register pattern), and a dashboard KPI for open zienswijzen. Weighted voting inside the shared body reuses the existing Membership `votingWeight` machinery (meeting-attendees REQ-MAT-006) — no new voting mechanics.

## Motivation

A novelty sweep of the active specs and changes (2026-07-17) confirms GovernanceBody is typed `schema:Organization` and IS the organisation — there is no multi-org axis anywhere: no way to record that a body is shared by several organisations, no per-organisation voting weight in a shared body, and no mechanism for a shared body's decisions to flow back to its member organisations. Yet inter-municipal cooperation is ubiquitous in the target market: virtually every Dutch municipality participates in multiple gemeenschappelijke regelingen (veiligheidsregio, GGD, omgevingsdienst, uitvoeringsorganisaties), and live tender relevance exists today — the SED organisatie (Stede Broec, Enkhuizen, Drechterland) needs one shared RIS across three municipalities. The Wgr's core accountability instrument, the zienswijzeprocedure (art. 35 Wgr: ontwerpbegroting to member councils, who may submit zienswijzen before vaststelling), is deadline-driven, document-heavy, and currently run in email + Word on both sides. The building blocks all exist: GovernanceBody (the shared body and each member council are governance bodies), Person + Membership (Popolo, with `votingWeight` already exposed per attendee via REQ-MAT-006), the P&C-cyclus sibling (the GR begroting is a P&C artifact), and the declarative rappel pattern (toezeggingen-register). This change only assembles them around three new schemas and two additive base-register properties.

## Affected Projects

- [ ] Project: `decidesk` — new `BodyParticipation`, `Zienswijzeronde`, and `Zienswijze` schemas (register.d fragment 56), additive base-register edits (`shared-body` value on the GovernanceBody `bodyType` enum + optional `namens` provenance property on Membership), manifest pages + menu (manifest.d fragment), participation section on the GovernanceBody detail page, one dashboard KPI widget, a small zienswijze-generation service action, seed data, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, relations are existing capabilities). All participating organisations share one Nextcloud instance in this change.

## Scope

### In Scope

1. **BodyParticipation schema** (register.d fragment 56): links a shared GovernanceBody to a member GovernanceBody with per-participant `seats`, `votingWeight`, and `toetredingsDatum`/`uittredingsDatum` — giving the shared body a queryable member-organisation roster with the same active-window semantics as Membership.
2. **Membership provenance**: an optional additive `namens` property (GovernanceBody reference) on the existing Membership schema, so a person's Membership in the shared body records namens which participating organisation they sit. Least-invasive model; justified in design D3.
3. **Zienswijzeprocedure** (the core Wgr workflow): a `Zienswijzeronde` opened by the shared body on a document/decision with a deadline; one `Zienswijze` generated per active participating organisation (appears in their context, deadline tracked with declarative rappels per the toezeggingen-register dialect); each organisation records standpunt + response document + optional link to its own council Decision; the shared body sees the aggregated overview and records per-zienswijze verwerking.
4. **P&C-cyclus connection**: a Zienswijzeronde optionally links to a `CyclusStap` of the pc-cyclus sibling (the GR ontwerpbegroting is a P&C artifact) — soft nullable reference, changes land in any order.
5. **Weighted voting reuse**: per-participant weighted voting in the shared body's meetings reuses the EXISTING Membership `votingWeight` machinery (meeting-attendees REQ-MAT-006); the participation's `votingWeight` is the org-level master datum from the regeling used to inform memberships. **No new voting mechanics are added.**
6. **Views + KPI**: deelnemersregister (participation roster) on the shared body's detail page, zienswijzeronde index/detail pages per manifest-v2 conventions, and a dashboard KPI "Openstaande zienswijzen" (open zienswijzerondes/zienswijzen per organisation via the pre-filtered index).
7. **`shared-body` bodyType** on GovernanceBody plus seed data modelling a realistic GR (three municipalities sharing one uitvoeringsorganisatie board, like the SED organisatie) so the domain is demonstrable on install.

### Out of Scope

- **Cross-Nextcloud-instance federation**: all participating organisations share one Nextcloud instance in this change; OCM-based federation between instances is explicitly future work.
- **Wgr juridics**: the treaty/regeling texts themselves belong in the governing-documents-register and verordeningenregister siblings; this change stores no regeling text.
- **Multi-tenancy**: no per-organisation tenant isolation; OR RBAC on one instance is the boundary.
- **New voting mechanics**: no weighted-ballot tabulation changes; REQ-MAT-006 machinery is reused as-is.
- **GR-specific P&C templates**: cycle templates stay owned by the pc-cyclus sibling; this change only links a ronde to a step.

## Approach

Pure thin-client extension per ADR-022/ADR-037: three new schemas shipped as `lib/Settings/register.d/56-shared-governance-bodies.json` (never editing existing schemas from a fragment), all workflow behaviour declared in OpenRegister dialects — ronde/zienswijze lifecycles via `x-openregister-lifecycle`, deadline rappels via `x-openregister-notifications` (toezeggingen-register pattern), roster/overview via `x-openregister-relations` reverse lookups, KPI counts via manifest stat-widget aggregations. Two strictly additive base-register edits go directly into `decidesk_register.json` because fragments structurally cannot extend an existing schema: the `shared-body` bodyType enum value and the optional Membership `namens` property (the works-council-consultation sibling's D2 enum precedent — union-merge coordination applies). UI is a `src/manifest.d/shared-governance-bodies.json` fragment (zienswijzeronde index + detail pages + menu entry); the participation section on the existing `GovernanceBodyDetail` page and the one Dashboard KPI widget are direct edits to `src/manifest.json` (fragments replace same-id pages wholesale — the toezeggingen D6 precedent). The only imperative code: a small `ZienswijzerondeService` that generates one Zienswijze per active participation when a ronde opens (object generation is not expressible as a dialect — the pc-cyclus step-generation precedent). One capability spec: `specs/shared-governance-bodies/spec.md` (ADDED-only requirements). Details in design.md.

## New Dependencies

None. All capabilities used (lifecycle, notifications, relations, manifest pages/widgets) already exist in OpenRegister, nc-vue, and decidesk.

## Impact

- `lib/Settings/register.d/56-shared-governance-bodies.json` (new — three schemas + dialects + seed data).
- `lib/Settings/decidesk_register.json` (edit — additive `shared-body` bodyType enum value; additive optional `namens` property on Membership).
- `src/manifest.d/shared-governance-bodies.json` (new — pages + menu).
- `src/manifest.json` (edit — participation section on `GovernanceBodyDetail`; one Dashboard stat widget).
- `lib/Service/ZienswijzerondeService.php` (new — zienswijze generation on ronde opening), controller route + wiring.
- Docs + PHPUnit/Newman/e2e per hydra gates.

## Cross-Project Dependencies

- `pc-cyclus` (sibling decidesk change, in flight): a Zienswijzeronde optionally links to a `CyclusStap` (the GR ontwerpbegroting behandeling). The reference is soft — a nullable reference field; the ronde works standalone if that change lands later. Not declared in `depends_on` to keep the two changes independently archivable.
- `works-council-consultation` (sibling): both changes make additive edits to the same GovernanceBody `bodyType` enum in `decidesk_register.json` — union merge on conflict, never dropping a sibling's value.
- `toezeggingen-ingekomen-stukken` (sibling/landed): the deadline/rappel notification dialect is reused as a pattern, not a dependency.
- OpenRegister: consumed, not changed.

## Risks

### Risk 1: Base-file edits race with wave siblings

**Severity:** Medium — **Mitigation:** both base edits (one enum value, one optional Membership property) are strictly additive; fragment number 56 is assigned to this change (40–55 and 57–65 belong to siblings); conflicts resolve by union merge, never by dropping a sibling's addition — the works-council `works-council` enum value in particular must survive alongside `shared-body`.

### Risk 2: Zienswijze deadline rappels need the deadline on the zienswijze object

**Severity:** Medium — **Mitigation:** the notification dialect filters on the object's own fields; the ronde's deadline is therefore copied onto each generated Zienswijze at generation time (documented denormalisation, single write moment, ronde deadline changes propagate via the same service action). Recorded in design D5.

### Risk 3: Modelling provenance on Membership pollutes the shared Popolo model

**Severity:** Low — **Mitigation:** `namens` is a single optional GovernanceBody reference, absent for all non-shared bodies, aligned with Popolo's `on_behalf_of` semantics (already used for `party`); alternatives (a separate provenance schema, or referencing the BodyParticipation) are heavier and are documented as rejected in design D3.

### Risk 4: Confusion between org-level participation weight and person-level voting weight

**Severity:** Low — **Mitigation:** the spec states explicitly that `BodyParticipation.votingWeight` is master data from the regeling and that operative vote weighting stays on Membership per REQ-MAT-006; no vote-computation path reads BodyParticipation.

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers the schemas/pages on next load/build (ADR-037 fragments are additive). The base-file edits (enum value, `namens` property, detail-page section, dashboard widget) revert cleanly and are additive, so no sibling functionality is affected. Existing BodyParticipation/Zienswijzeronde/Zienswijze objects remain soft-retained in OpenRegister; a `bodyType=shared-body` body would fail re-validation only on edit and can be re-typed manually; Membership objects carrying `namens` keep the value as an ignored extra property until re-validation. No data migration to undo.

## Open Questions

- Whether the notification dialect's scheduled trigger can filter a Zienswijze on the *ronde's* deadline via a relation path — if so, the D5 deadline denormalisation can be dropped; verified against OpenRegister's trigger resolver during apply.
- Whether the `namens` picker can be scoped declaratively (via `x-relation-filter`) to organisations that actually participate in the membership's governance body — nice-to-have; plain unscoped reference is the fallback.
