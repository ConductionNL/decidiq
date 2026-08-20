---
kind: code
---

# Proposal: interests-and-integrity

## Summary

Add the two integrity registers every Dutch governance body needs and decidesk lacks: a structured **nevenfunctiesregister** (other-positions register — person-linked declarations with bezoldigd/onbezoldigd, hours, q.q. flag, a declarative disclosure lifecycle `gemeld → openbaar | intern → beëindigd`, and a live public register per body via the OR published-predicate) and a **geschenken/uitnodigingenregister** (gifts register — recipient, giver, estimated value, per-body threshold policy, decision aanvaard/geweigerd/overgedragen, optional public register). A small per-body `Integriteitsbeleid` policy object drives disclosure defaults (council: statutorily public per Gemeentewet; corporate boards: internal by default), the gift threshold, and a configurable integrity notification to a designated role (burgemeester/voorzitter/compliance officer). Declarative annual-review rappels, a my-declarations self-service page, per-body register pages, a compliance view, and a dashboard KPI complete the capability. All three schemas ship as register fragment `lib/Settings/register.d/62-interests-and-integrity.json` (ADR-037).

## Motivation

Novelty verification (2026-07-17) confirmed the gap is real and specific:

- **Per-agenda-item COI is covered, structural interests are not.** `conflict-of-interest` (REQ-COI-001..004) fully covers declaring belangenverstrengeling against a specific agenda item with recusal notes and audit. But the *standing* registers behind those declarations — which nevenfuncties does this member hold, which gifts did they accept — do not exist anywhere in decidesk.
- **`Membership.otherPositions` is a free-text string array** (corp-mode convenience from `popolo-decision-makers`): no lifecycle, no bezoldigd/q.q. structure, no publication, no review. It cannot satisfy Gemeentewet openbaarmaking of nevenfuncties for raadsleden and wethouders.
- **`fractievoorzitter-fractie-koppeling` REQ-012** proposes a *council-scoped* nevenfuncties register with burgemeester notification and an annual rappel. That intent is correct but too narrow: associations, corporate boards (MCCG), works councils, and project boards all keep interest/gift registers. This change generalizes it across all five decidesk governance domains (see Cross-Project Dependencies for the explicit supersedes/composes split).
- **Missing outright:** a geschenkenregister (standard in every gemeentelijke gedragscode: gifts above ~EUR 50 must be declared and refused/handed over), structured nevenfunctie objects with a public-disclosure lifecycle, and cross-register integrity views (who has not reviewed their declarations this year).

Without these registers a griffie or bestuurssecretaris keeps a parallel Excel for statutory integrity obligations — an adoption blocker orthogonal to meeting management.

## Affected Projects

- [ ] Project: `decidesk` — new `Nevenfunctie`, `Geschenk`, `Integriteitsbeleid` schemas (register.d fragment 62 with lifecycle/notification dialects, public-predicate RBAC rules, and seed data), manifest.d fragment with self-service + register pages, dashboard KPI, assistive COI-panel integration, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, RBAC published-predicate, aggregations are existing capabilities).

## Scope

### In Scope

1. **Nevenfunctie schema** (register.d fragment 62, slug `nevenfunctie`): person ref, governance-body ref, organisation, function description, bezoldigd/onbezoldigd, hours indication, start/end dates, q.q. flag (uit hoofde van het ambt), declared-at date, reviewed-at date, declarative lifecycle `gemeld → openbaar | intern → beëindigd`.
2. **Per-body disclosure policy** via a small `Integriteitsbeleid` schema: disclosure default (council: public per Gemeentewet art. 12/13; corporate boards: internal), gift threshold (default EUR 50 per model-gedragscode), gift-register publicness, designated integrity-notification recipient.
3. **Public nevenfunctiesregister per body** via the OR RBAC published-predicate *on the live object* (`publicatiedatum <= $now` read rule for the `public` group) — same carve-out pattern as `toezeggingen-register` REQ-005; the `public-publication` eligibility gates and derived-payload machinery are not touched.
4. **Geschenk schema** (slug `geschenk`): recipient, giver, description, estimated value, date, type (geschenk/uitnodiging), decision (aanvaard/geweigerd/overgedragen), threshold-driven badge + notification, optional public register per body policy.
5. **COI integration (assistive display)**: the agenda-item COI declaration dialog (REQ-COI-001) and the chair's COI summary panel (REQ-COI-002) surface the participant's registered active nevenfuncties, with simple subject-keyword highlighting — display only, no automatic conflict detection.
6. **Declarative annual review**: `x-openregister-notifications` scheduled rappel prompting each member to review their own declarations; self-service confirm/update writes `reviewedAt`.
7. **Integrity notification**: declarative created/updated triggers notifying the body's configured integrity recipient on new/changed nevenfuncties and above-threshold geschenken — generalizing fractievoorzitter REQ-012's burgemeester notification.
8. **Pages + KPI**: MyDeclarations self-service page, per-body Nevenfuncties and Geschenken index/detail pages with CSV export, a compliance panel (members without a current reviewed declaration), and a dashboard stat widget for overdue reviews.

### Out of Scope

- Integrity **investigations/case management** (vermoedens, onderzoeken, sancties) — different process, future change.
- **Gedragscode text authoring** — the code itself is a governing document; the `governing-documents-register` sibling stores it. This change only carries the numeric threshold and disclosure defaults as policy data.
- **Wfpp party-finance reporting** (giften aan fracties/partijen) — belongs to the fractievoorzitter context, not personal integrity registers.
- **Automatic conflict detection** beyond the assistive display (no NLP matching, no blocking of votes).
- Migration tooling for existing `Membership.otherPositions` free-text values (manual re-entry; see Risks).

## Approach

Pure thin-client, fully declarative extension per ADR-022/ADR-031/ADR-037: three schemas in `lib/Settings/register.d/62-interests-and-integrity.json` (never editing `decidesk_register.json`), lifecycle via `x-openregister-lifecycle` (canonical `initial` keyword), rappels and integrity notifications via `x-openregister-notifications`, publication via an `authorization.read` public-group predicate on the live object. UI is a `src/manifest.d/interests-and-integrity.json` fragment (pages `MyDeclarations`, `Nevenfuncties`, `NevenfunctieDetail`, `Geschenken`, `GeschenkDetail` + menu) plus a one-widget edit to the Dashboard page in `src/manifest.json`. No new PHP controllers or services are expected — publish/withdraw and annual confirm are field writes through the shared object stores. Details in design.md.

## New Dependencies

None. All capabilities used (lifecycle, notifications, RBAC published-predicate, stat-widget aggregation, ExportService) already exist in OpenRegister, nc-vue, and decidesk.

## Impact

- `lib/Settings/register.d/62-interests-and-integrity.json` (new — 3 schemas + dialects + RBAC rules + seed data).
- `src/manifest.d/interests-and-integrity.json` (new — 5 pages + menu entries).
- `src/manifest.json` (edit — 1 Dashboard stat widget; fragments replace same-id pages wholesale, so the Dashboard page cannot be extended from a fragment).
- COI panel surfaces (frontend) — the AgendaItem COI dialog and meeting COI summary gain an assistive nevenfuncties block.
- Docs + tests per hydra gates. No API surface changes; no existing schema is modified.

## Cross-Project Dependencies

None hard (decidesk-internal; OpenRegister consumed, not changed). Explicit relations to sibling decidesk changes and canonical specs:

- **`conflict-of-interest` (canonical, done)** — *integrates, never re-specs*: COI declarations stay the REQ-COI-001 notes mechanism; this change only adds assistive context to the REQ-COI-001 dialog and REQ-COI-002 panel.
- **`fractievoorzitter-fractie-koppeling` REQ-012 (sibling change, planned)** — *superseded for the register mechanics, composed for the portal surface*: the nevenfuncties register, public disclosure, burgemeester notification, and annual rappel proposed there are delivered here, generalized to all bodies and via the OR predicate surface instead of an app-local `/raad/nevenfuncties` page; the fractie-portaal keeps only a deep link into these pages. That change should drop or thin REQ-012 to a reference when it lands after this one.
- **`person-and-membership` (canonical, done)** — `Membership.otherPositions` is *superseded for new data* by structured `Nevenfunctie` objects; the field remains valid legacy data and is not removed (no MODIFIED delta).
- **`member-onboarding` (sibling change)** — consumes this change's stable names for its `nevenfuncties-intake` step: capability `interests-and-integrity`, schema slug `nevenfunctie`, deep-link page `MyDeclarations`.
- **`toezeggingen-register` / `public-publication` (canonical)** — the predicate-on-live-object pattern and its rationale are reused verbatim; eligibility gates untouched.
- **`governing-documents-register` (sibling change)** — stores the gedragscode text this change's threshold policy refers to (reference only).

## Risks

### Risk 1: Public predicate exposes personal data beyond the statutory duty

**Severity:** High — **Mitigation:** the `Nevenfunctie` schema is publishable by construction (function, organisation, bezoldigd, q.q., dates only — no address, no remuneration amounts, no free-form remarks field); publication is always an explicit staff action (setting `publicatiedatum` on verified `openbaar` objects); geschenken are public only when the body's policy opts in; the giver is a plain string, never a Person object, so no citizen PII enters the people register.

### Risk 2: Divergence with fractievoorzitter REQ-012 lands two nevenfuncties registers

**Severity:** Medium — **Mitigation:** the supersedes/composes split is stated normatively in this change's spec Notes and in this proposal; the schema slug `nevenfunctie` and the page ids are the single stable contract; reviewers of `fractievoorzitter-fractie-koppeling` are pointed here before its apply.

### Risk 3: Notification dialect cannot resolve a per-body dynamic recipient

**Severity:** Medium — **Mitigation:** the policy stores an NC group per body as the integrity recipient; if the dialect cannot read the recipient from a related policy object, fall back to one configured integrity group per schema trigger with the per-body role documented in the policy object (documented in design.md, never silent).

### Risk 4: Compliance view needs a cross-schema join no declarative widget provides

**Severity:** Low — **Mitigation:** the dashboard KPI counts overdue-review `Nevenfunctie` objects (single-schema declarative aggregation); the members-without-declaration panel is a client-side join of two standard OR list queries on the register page, flagged assistive — no backend endpoint, no custom aggregation API.

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers the schemas and pages on next load/build (ADR-037 fragments are additive; no existing schema or page is edited except the one Dashboard widget, which the revert also removes). Created Nevenfunctie/Geschenk/Integriteitsbeleid objects remain soft-retained in OpenRegister; published nevenfuncties are withdrawn by clearing the predicate (`depublicatiedatum`) via the normal staff flow if desired. No data migration to undo.

## Open Questions

- Can an `x-openregister-notifications` trigger resolve its recipient group from a field on a *related* policy object, or only from a literal group per trigger? (Risk 3 fallback stands either way; verify against OR during apply.)
- Should a member with zero nevenfuncties record an explicit nil-declaration ("geen nevenfuncties"), as many gemeentelijke registers do? Deferred: v1 shows such members in the compliance panel as "geen opgave geregistreerd"; a nil-declaration flag would need conditional required-field validation.
