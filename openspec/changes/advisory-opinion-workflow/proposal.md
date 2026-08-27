---
kind: code
---

# Proposal: advisory-opinion-workflow

## Summary

Add a formal advisory-opinion workflow (adviesaanvraag → advies → verantwoording) to decidiq: an `Adviesaanvraag` register in which a deciding body formally requests advice from an external advisory body (jongerenraad, adviesraad sociaal domein, cliëntenraad — universal GovernanceBody objects), an `Advies` artifact in which the advisory body's secretary records the response document, summary, and strekking (positief / positief-met-kanttekeningen / negatief / geen-advies), and a mandatory verantwoording: when the deciding body's final decision deviates from the advies strekking, a motivering is required (fail-closed) before the decision can complete, recorded on both the Decision and the Adviesaanvraag. Delivered as a `lib/Settings/register.d/60-advisory-opinion-workflow.json` schema fragment plus manifest pages, with declarative lifecycle (`x-openregister-lifecycle`), declarative deadline rappels (`x-openregister-notifications`, the toezeggingen-register pattern), advisory-body workload views, public publication of advies + verantwoording via the predicate-on-live-object pattern, and dashboard KPIs.

## Motivation

A novelty sweep of the active specs and changes (2026-07-17) finds the advisory-opinion domain only PARTIALLY covered: `decision-methods` gives a route stage `method=advice` (a body records `advised`/`deferred` — the in-route MECHANICS exist) and `commissievergaderingen` models raadscommissies (internal committee advice with `CommissieAdvies`). But there is NO request object addressed to an external advisory body, NO advice document artifact for that body's formal response, and NO mandatory verantwoording when the deciding body deviates from the advice. Dutch municipalities are legally and politically bound to consult these organen (Participatiewet/Wmo adviesraden, cliëntenraden, jongerenraden) and to account for deviations, yet today the traject lives in mail + Word. Demand is strong: submit-youth-council-advice scores 831 and present-recommendations-to-council scores 740 in the demand clusters. The building blocks all exist — GovernanceBody (advisory bodies are bodies, with the p3-citizen-participation "citizen advisory body" Organization precedent), Meeting/AgendaItem, Decision + decision-route `method=advice`, the declarative rappel pattern (toezeggingen-register), the predicate-on-live-object publication pattern — this change assembles them around two new schemas and one fail-closed guard.

## Affected Projects

- [ ] Project: `decidiq` — new `Adviesaanvraag` + `Advies` schemas (register.d fragment 60), `advisory-body` value on the GovernanceBody `bodyType` enum (additive base register edit), a fail-closed verantwoording guard on decision completion, `verantwoording` fields on Decision (additive base register edit), manifest pages + menu (manifest.d fragment), advisory-body workload views, two dashboard KPI widgets, seed data, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, relations, RBAC published-predicate are existing capabilities).

## Scope

### In Scope

1. **Adviesaanvraag schema** (register.d fragment 60): requesting body (GovernanceBody ref), advisory body (GovernanceBody ref — advisory bodies are universal bodies, never a parallel schema), subject, linked Decision and/or agenda item, the question posed (adviesvraag), requested-by date, submitted documents (Files leaf / DigitalDocument relations), declarative lifecycle `verzonden → in-behandeling → advies-uitgebracht → verantwoord → afgerond`, with `niet-uitgebracht` for the advisory body declining or lapsing.
2. **Advies artifact schema**: the advisory body's formal response — response document, summary, strekking (`positief` / `positief-met-kanttekeningen` / `negatief` / `geen-advies`), advice date, recorded by the advisory body's secretary. In-route advice stages KEEP using decision-methods `method=advice` (`advised`/`deferred` set by the actor); this change adds the REQUEST/RESPONSE/ACCOUNTABILITY wrapper around it and covers the out-of-route case.
3. **Mandatory verantwoording (fail-closed)**: when the deciding body's final decision deviates from the advies strekking, a motivering is REQUIRED before the decision can complete — a fail-closed guard mirroring the decision lifecycle-guard precedents; the verantwoording text is recorded on both the Decision and the Adviesaanvraag.
4. **Advisory-body workload views**: open aanvragen per advisory body, deadlines, and declarative rappels on the requested-by date (`x-openregister-notifications` scheduled triggers, the toezeggingen-register dialect pattern).
5. **Public publication of advies + verantwoording** via the predicate-on-live-object pattern (`publicatiedatum` RBAC predicate, the toezeggingen precedent) — explicitly WITHOUT modifying public-publication's eligibility-gates requirement.
6. **Pages + KPIs**: adviesaanvragen index/detail as manifest-v2 pages (manifest.d fragment), advies section on the detail, dashboard KPIs for open aanvragen and adviezen awaiting verantwoording.
7. **`advisory-body` bodyType** on GovernanceBody (additive base edit, the works-council/shared-body precedent) plus seeded advisory bodies and trajecten so the domain is demonstrable on install.

### Out of Scope

- The advisory body's own internal meeting process — advisory bodies are GovernanceBody objects, so the existing meetings machinery (agenda, minutes, attendance) already covers it.
- Citizen panels and public opinion gathering — owned by `citizen-participation` (CitizenPanel, PublicConsultation); this change is a formal body-to-body request/response exchange.
- WOR trajecten — owned by the sibling change `works-council-consultation`: WOR = statutory employee participation (bestuurder → ondernemingsraad, art. 25/27 with opschortingstermijn); this change = advisory opinions from external maatschappelijke adviesorganen with a deviation-verantwoording. The two share request/response vocabulary but different actors, legal frames, and lifecycles.
- Raadscommissie advies on plenary agenda items — owned by `commissievergaderingen` (CommissieAdvies); a raadscommissie sits IN the political structure and its advice rides the decision route.
- Appointment/samenstelling management of advisory bodies (verordening, benoeming) — Person + Membership on the body covers membership; regulatory instruments are separate changes.

## Approach

Pure thin-client extension per ADR-022/ADR-037: two new schemas shipped as `lib/Settings/register.d/60-advisory-opinion-workflow.json` (never editing existing schemas from a fragment), workflow behaviour declared in OpenRegister dialects — lifecycle via `x-openregister-lifecycle`, rappels via `x-openregister-notifications`, linkage via `x-openregister-relations`, KPI counts via manifest stat-widget aggregations, publication via the `publicatiedatum` RBAC predicate on the live objects. UI is a `src/manifest.d/advisory-opinion-workflow.json` fragment; the dashboard KPI widgets are a direct edit to `src/manifest.json` (fragments replace same-id pages wholesale — the toezeggingen D6 precedent). Additive base-register edits: `advisory-body` on the GovernanceBody `bodyType` enum and verantwoording fields on Decision (an existing schema cannot be extended from a fragment). The only imperative code: the fail-closed `AdviceAccountabilityGuard` that blocks decision completion on an unverantwoorde afwijking (cross-object conditional requirement — not expressible in the lifecycle dialect). One capability spec: `specs/advisory-opinion-workflow/spec.md` (ADDED-only requirements; public-publication's eligibility-gates requirement is deliberately NOT modified). Details in design.md.

## New Dependencies

None. All capabilities used (lifecycle, notifications, relations, RBAC published-predicate, manifest pages/widgets) already exist in OpenRegister, nc-vue, and decidiq.

## Impact

- `lib/Settings/register.d/60-advisory-opinion-workflow.json` (new — Adviesaanvraag + Advies schemas + dialects + seed data).
- `lib/Settings/decidesk_register.json` (edit — additive `advisory-body` bodyType enum value; additive optional verantwoording fields on Decision).
- `src/manifest.d/advisory-opinion-workflow.json` (new — pages + menu).
- `src/manifest.json` (edit — two Dashboard stat widgets).
- `lib/Service/AdviceAccountabilityGuard.php` (new — fail-closed verantwoording guard on decision completion), wiring into the existing decision status-transition path.
- Docs + PHPUnit/Newman/e2e per hydra gates.

## Cross-Project Dependencies

- `works-council-consultation` (sibling decidiq change, in flight): boundary sibling only — no shared objects; vocabulary mirrored (request/response/besluit recording) where sensible. Independent landing order.
- `decision-route` / `decision-methods`: consumed, not modified — in-route advice stages keep `method=advice` semantics; the Adviesaanvraag references the routed Decision/stage.
- OpenRegister: consumed, not changed.

## Risks

### Risk 1: Fail-closed guard blocks legitimate decision completion

**Severity:** High — **Mitigation:** the guard blocks ONLY when a linked advies has a deviating strekking AND no verantwoording is recorded; conform decisions, `geen-advies`, `niet-uitgebracht`, and decisions without a linked aanvraag are never blocked. The guard fails closed on evaluation errors (never silently open — the unsafe-auth-resolver lesson) but its trigger condition is narrow and the blocking response names exactly what is missing. PHPUnit covers the full deviation matrix, mutation-guarded.

### Risk 2: Deviation detection between strekking and decision outcome is fuzzy

**Severity:** Medium — **Mitigation:** deviation is defined mechanically on the sign only: (`positief`|`positief-met-kanttekeningen`) vs `rejected`, and `negatief` vs `adopted`; `geen-advies` never deviates. Whether kanttekeningen were honoured is the deciding body's political judgment — the guard cannot and does not evaluate it; staff can always record a verantwoording voluntarily. Recorded explicitly in design.md.

### Risk 3: Base-file edits race with wave siblings

**Severity:** Medium — **Mitigation:** the base edits (one enum value, optional Decision fields, two dashboard widgets) are strictly additive; fragment number 60 is assigned to this change (40–59 and 61–65 belong to siblings); conflicts resolve by union merge, never by dropping a sibling's addition.

### Risk 4: Boundary confusion with commissievergaderingen and works-council-consultation

**Severity:** Low — **Mitigation:** the spec states the boundaries explicitly (raadscommissie advies = in-route, owned by commissievergaderingen/decision-methods; WOR = statutory employee participation, owned by works-council-consultation); distinct schemas, distinct nav entries, and the schema descriptions record the distinction.

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers the schemas/pages on next load/build (ADR-037 fragments are additive). The base-file edits (bodyType enum value, optional Decision fields, dashboard widgets) revert cleanly and are additive, so no sibling functionality is affected. Removing the guard wiring restores the previous decision-completion behaviour. Already-created Adviesaanvraag/Advies objects remain soft-retained in OpenRegister; a `bodyType=advisory-body` body would fail re-validation only on edit and can be re-typed manually. No data migration to undo.

## Open Questions

- Rappel window before the requested-by date (provisional: 14 days before, weekly after) — griffie tuning deferred to a future admin-settings change.
- Whether `positief-met-kanttekeningen` + adopted-with-material-changes should ever trigger the guard (currently: no — sign-only deviation, see Risk 2); revisit with adviesraad pilot feedback.
