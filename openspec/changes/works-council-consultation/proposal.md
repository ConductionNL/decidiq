---
kind: code
---

# Proposal: works-council-consultation

## Summary

Add WOR consultation trajecten to decidesk: a `ConsultationRequest` register for adviesaanvragen (art. 25 WOR) and instemmingsverzoeken (art. 27 WOR) submitted by the bestuurder to an ondernemingsraad, tracked as a declarative statutory lifecycle from ontvangst through (optional) achterbanraadpleging and overlegvergadering to a formally adopted and sent advies/instemming, including recording of the bestuurder's decision and the art. 25 lid 6 one-month opschortingstermijn when the bestuurder deviates from the advice. Delivered as a `lib/Settings/register.d/47-works-council-consultation.json` schema fragment plus manifest pages, with declarative lifecycle (`x-openregister-lifecycle`), declarative deadline rappels (`x-openregister-notifications`, the toezeggingen-register pattern), and dashboard KPIs for open trajecten and responses past the requested date.

## Motivation

Decidesk explicitly targets works councils (ondernemingsraden/medezeggenschap) as a governance domain, yet a novelty sweep of the active specs (2026-07-17) finds zero coverage of ondernemingsraad, medezeggenschap, adviesaanvraag, or instemming. The adviesaanvraag/instemmingsverzoek traject is *the* core workflow of every Dutch OR — statutory (WOR art. 25/27), deadline-driven, document-heavy, and meeting-linked — and is the workflow OR-support tooling is bought for (demand cluster initiate-works-council-consultation, score 759, must-have). Without it an OR can use decidesk for its vergaderingen but must run its actual legal consultation trajecten in Word + Excel. The building blocks all exist: GovernanceBody (the OR is a governance body), Meeting/AgendaItem (overlegvergadering), Decision + decision-route `method=advice` (the advisory outcome), the Docudesk document-generation pattern (resolution-minutes), and the declarative rappel pattern (toezeggingen-register). This change only assembles them around one new schema.

## Affected Projects

- [ ] Project: `decidesk` — new `ConsultationRequest` schema (register.d fragment 47), `works-council` value on the GovernanceBody `bodyType` enum (base register edit), manifest pages + menu (manifest.d fragment), two dashboard KPI widgets, formal-response document generation service, seed data, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, calculations, relations are existing capabilities). Docudesk is an optional runtime dependency with an honest markdown fallback (existing pattern).

## Scope

### In Scope

1. **ConsultationRequest schema** (register.d fragment 47): `type` (adviesaanvraag / instemmingsverzoek), WOR article reference, subject, bestuurder (Person ref — the requester), submitted documents (Files leaf / DigitalDocument relations), received date, requested response date, the OR governance body, formal response fields (outcome, text, document, date), bestuurder decision fields, and cross-references (overlegvergadering Meeting + AgendaItem, optional achterbanraadpleging, optional related Decision).
2. **Statutory flow as a declarative lifecycle** (`x-openregister-lifecycle`, canonical `initial` keyword): `ontvangen → in-behandeling → (achterbanraadpleging) → overlegvergadering → vastgesteld → verzonden → besluit-ontvangen → afgerond`, with `ingetrokken` as a withdrawal terminal. The achterbanraadpleging step REFERENCES the sibling change `constituency-consultation` for the poll mechanics — no poll machinery is duplicated here.
3. **Bestuurder decision recording** + the art. 25 lid 6 one-month opschortingstermijn as a declarative calculation when the bestuurder deviates from the advies, with a notification at recording and at expiry.
4. **Deadline tracking + declarative rappels** (`x-openregister-notifications` scheduled triggers, the toezeggingen-register pattern): pre-deadline rappel and overdue rappel on `requestedResponseDate`; no imperative dispatch, no bespoke ReminderJob.
5. **Formal response document generation** reusing the resolution-minutes Docudesk delegation pattern (markdown canonical, PDF opportunistic, honest fallback).
6. **Linkage**: the traject is treated in OR meetings as an agenda item (Meeting + AgendaItem references); the formal response links to the Decision model via decision-route's `method=advice` semantics where the underlying ondernemersbesluit is modelled as a routed Decision.
7. **List + detail pages** per manifest-v2 conventions (manifest.d fragment, schema refs by slug) and two dashboard KPIs: open trajecten and responses past the requested date.
8. **`works-council` bodyType** on GovernanceBody plus a seeded ondernemingsraad body so the domain is demonstrable on install.

### Out of Scope

- Ondernemingskamer beroep (art. 26 WOR) and any legal-proceedings tooling — different legal process.
- Nietigheid inroepen / kantonrechter vervangende toestemming after a geweigerde instemming (art. 27 lid 4–6) — only the refusal itself is recorded.
- CAO interpretation and CAO-derived instemmingsplichten — legal-content concern, not workflow.
- Employer-side (bestuurder) tooling — decidesk serves the OR; the bestuurder is a Person reference, not a user persona of this change.
- Poll/raadpleging mechanics for the achterban — owned by the sibling change `constituency-consultation`; this change only links to it.
- OR verkiezingen, reglementen, faciliteiten (art. 17/18) — separate future changes.

## Approach

Pure thin-client extension per ADR-022/ADR-037: one new schema shipped as `lib/Settings/register.d/47-works-council-consultation.json` (never editing existing schemas from a fragment), all workflow behaviour declared in OpenRegister dialects — lifecycle via `x-openregister-lifecycle`, rappels + besluit/opschorting notifications via `x-openregister-notifications`, the opschortingstermijn via `x-openregister-calculations`, KPI counts via manifest stat-widget aggregations. UI is a `src/manifest.d/works-council-consultation.json` fragment (index + detail pages + menu entry); the two Dashboard KPI widgets are a direct edit to `src/manifest.json` (fragments replace same-id pages wholesale — the toezeggingen D6 precedent). The `works-council` bodyType enum value is a one-line direct edit to `decidesk_register.json` (an enum on an existing schema cannot be extended from a fragment). The only imperative code: a small `ConsultationResponseDocumentService` mirroring `MinutesDocumentService` (Docudesk delegation with honest markdown fallback). One capability spec: `specs/works-council-consultation/spec.md` (ADDED-only requirements). Details in design.md.

## New Dependencies

None. All capabilities used (lifecycle, notifications, calculations, relations, manifest pages/widgets, Docudesk delegation with fallback) already exist in OpenRegister, nc-vue, and decidesk.

## Impact

- `lib/Settings/register.d/47-works-council-consultation.json` (new — schema + dialects + seed data).
- `lib/Settings/decidesk_register.json` (edit — one enum value `works-council` on GovernanceBody `bodyType`).
- `src/manifest.d/works-council-consultation.json` (new — pages + menu).
- `src/manifest.json` (edit — two Dashboard stat widgets).
- `lib/Service/ConsultationResponseDocumentService.php` (new — formal-response document generation), controller route + wiring.
- Docs + PHPUnit/Newman/e2e per hydra gates.

## Cross-Project Dependencies

- `constituency-consultation` (sibling decidesk change, in flight): the optional achterbanraadpleging lifecycle step links to that change's raadpleging/poll objects. The reference is soft — a nullable reference field; the step is skippable and the traject degrades to a plain link if that change lands later. Not declared in `depends_on` to keep the two changes independently archivable.
- OpenRegister and Docudesk: consumed, not changed (Docudesk optional at runtime, honest fallback).

## Risks

### Risk 1: Lifecycle over- or under-fitting the statutory practice

**Severity:** Medium — **Mitigation:** the lifecycle mirrors WOR practice (ontvangst, behandeling, optionele achterbanraadpleging, overlegvergadering art. 25 lid 4, vaststelling, verzending, bestuurdersbesluit, afronding) with a repeat-overleg loop and a withdrawal terminal; states carry no legal effect beyond workflow, and the WOR article reference stays a free field so art. 30 adviesaanvragen (benoeming bestuurder) fit without schema change.

### Risk 2: Opschortingstermijn derivation not expressible declaratively

**Severity:** Medium — **Mitigation:** `opschortingTot` is specced as an `x-openregister-calculations` date derivation (besluitDate + 1 month, conditional on afwijkend besluit on an adviesaanvraag); if the calculation dialect cannot express date arithmetic, the documented fallback is a plain date field set in the besluit-recording flow with server-side validation — never a silently wrong value (recorded in design.md).

### Risk 3: Base-file edits race with wave siblings

**Severity:** Medium — **Mitigation:** the two base edits (one enum value, two dashboard widgets) are strictly additive; fragment number 47 is assigned to this change (40–46/48–65 belong to siblings); conflicts resolve by union merge, never by dropping a sibling's addition.

### Risk 4: Confusion with the existing citizen-participation PublicConsultation

**Severity:** Low — **Mitigation:** distinct schema (`ConsultationRequest`, slug `consultation-request`), distinct purpose recorded in the schema description (statutory WOR traject vs public consultation), separate nav entry; the spec names the distinction explicitly.

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers the schema/pages on next load/build (ADR-037 fragments are additive). The two base-file edits (bodyType enum value, dashboard widgets) revert cleanly and are additive, so no sibling functionality is affected. Already-created ConsultationRequest objects remain soft-retained in OpenRegister; existing GovernanceBody objects with `bodyType=works-council` would fail re-validation only on edit and can be re-typed manually. No data migration to undo.

## Open Questions

- Whether the `x-openregister-calculations` dialect supports date arithmetic (+1 month) for `opschortingTot` (see Risk 2); resolved during implementation against OpenRegister's calculation resolver.
- Rappel window before `requestedResponseDate` (provisional: 14 days before, weekly after) — griffie/ambtelijk-secretaris tuning deferred to a future admin-settings change.
