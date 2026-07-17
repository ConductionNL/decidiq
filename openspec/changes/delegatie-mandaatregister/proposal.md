---
kind: code
---

# Proposal: delegatie-mandaatregister

## Summary

Add a delegatie- en mandaatregister to decidesk: a queryable register of delegated authorities as `Bevoegdheidstoedeling` objects — type (delegatie / mandaat / volmacht / machtiging), delegans/mandaatgever, delegataris/gemandateerde, scope, limits (financial ceiling, subject constraints, ondermandaat allowed), wettelijke grondslag, a link to the authorizing delegatie-/mandaatbesluit (`Decision`), a validity window, and a declarative status lifecycle (`concept → van-kracht → ingetrokken | vervallen`) with intrekking traced to a revoking Decision. Includes ondermandaat chains (parent reference, depth display, cycle-free), register views (per delegans, per delegataris, in-force-on-date, full-text search, CSV export), public publication of the register through OpenRegister's published-predicate on the live object, an assistive (never blocking) bevoegdheidsgrondslag link from a Decision detail to the toedeling under which it was taken, and declarative expiry notifications.

## Motivation

Novelty verification (2026-07-17) shows zero coverage: `delegatie`, `mandaat`, `mandaatregister`, and `delegatiebesluit` have no hits anywhere in decidesk's specs, changes, or code; `bevoegdheid` appears only as committee-competence prose (commissievergaderingen) and the urgent change's spoedbevoegdheid. Yet every Dutch gemeente is legally required to maintain and publish a delegatie- en mandaatregister (Awb afdeling 10.1.1 mandaat, afdeling 10.1.2 delegatie; mandaatbesluiten are bekendgemaakt and the register must be raadpleegbaar). Market demand sits in the entity/authority-management cluster at 740 (intelligence DB). Decidesk already holds every building block — GovernanceBody (who can hold authority), Person/Membership (Popolo decision-makers), the Decision supertype (the delegatie-/mandaatbesluit and the revoking besluit), and the published-predicate publication pattern — but the register that connects them, the answer to "who may decide what, on whose behalf, within which limits, on date X", has no home. Governance suites (iBabs, Notubiz) do not cover this either; it lives today in unmaintained Word annexes to the mandaatbesluit.

## Affected Projects

- [ ] Project: `decidesk` — new OR schema `bevoegdheidstoedeling` in register fragment `lib/Settings/register.d/54-delegatie-mandaatregister.json` with declarative lifecycle and expiry notifications (ADR-031 dialects), a nullable `bevoegdheidsgrondslag` property on the existing `Decision` schema (assistive display only), a small ondermandaat cycle/permission guard, manifest register views with in-force-on-date filtering and CSV export, public publication via the OR published-predicate on the live object, seed data, tests, docs.
- [ ] Project: `openregister` — consumed only: ObjectService storage, declarative lifecycle enforcement, notifications dialect, anonymous published-predicate RBAC surface (ADR-022). No OR changes.

## Scope

### In Scope

1. **Bevoegdheidstoedeling schema**: `type` (delegatie / mandaat / volmacht / machtiging), delegans/mandaatgever (GovernanceBody reference or role description), delegataris/gemandateerde (GovernanceBody reference, function/role description, or Person reference), subject/scope description, limits (financial ceiling, subject constraints, ondermandaat allowed yes/no), wettelijke grondslag (same citation shape as verordeningenregister's `wettelijkeGrondslag`), authorizing Decision link (the delegatie-/mandaatbesluit), geldig-vanaf/geldig-tot, declarative status lifecycle `concept → van-kracht → ingetrokken | vervallen`, intrekking traced to a revoking Decision.
2. **Ondermandaat chains**: a Bevoegdheidstoedeling can reference its parent toedeling; chain depth is displayed; cycles are impossible; ondermandaat is only accepted when the parent allows it.
3. **Register views**: per delegans, per delegataris, in-force-on-date filter, full-text search, CSV export; public publication of the register via the predicate-on-live-object pattern (an intrekking is live on the public register without republication).
4. **Assistive linkage**: a Decision detail can reference the Bevoegdheidstoedeling under which it was taken (`bevoegdheidsgrondslag`, nullable, assistive display only — no enforcement, no blocking).
5. **Declarative notifications**: geldigheid expiring (geldig-tot approaching) via `x-openregister-notifications`.
6. **Seed data**: realistic municipal delegatie/mandaat/ondermandaat/volmacht examples traced to seed decisions.

### Out of Scope

- **Enforcement** — decidesk never blocks or warns away a decision taken without (or outside) a mandate; `bevoegdheidsgrondslag` is assistive display only. Bevoegdheidstoetsing is a legal judgement, not a data constraint.
- **HR function management** — delegataris function descriptions are plain text on the toedeling; no function/formatie register, no HR system coupling.
- **CVDR/DROP publication of the delegatiebesluit document itself** — the verordeningenregister sibling owns regeling-type document publication (a mandaatbesluit publishes there as a besluit van algemene strekking when applicable). The register rows here are *relations between bodies, roles, and decisions*, not documents; this change publishes the register, never the besluit text.
- Provincial/waterschap register variants beyond the generic model — validate later on concrete demand.

## Approach

Pure thin-client extension (ADR-022/ADR-037): one new schema in an additive `register.d` fragment, all workflow behaviour in OpenRegister dialects (`x-openregister-lifecycle` for the status machine, `x-openregister-notifications` for expiry rappels), manifest-v2 register views, and the public register on OpenRegister's anonymous published-predicate surface — following toezeggingen-register's predicate-on-live-object pattern because the register must stay live (an intrekking shows immediately) and the schema contains only publishable fields by construction. Imperative code is limited to one thin seam a dialect cannot express: the ondermandaat guard (parent must allow ondermandaat; the parent chain must be acyclic). The in-force-on-date view is a pure OR query (`status = van-kracht`, `geldigVanaf <= X`, `geldigTot` null or `>= X`) — no resolution service needed because toedelingen, unlike regeling versions, do not form an exclusive version timeline. Details, including the ADR-031 justification for the imperative exception, in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/54-delegatie-mandaatregister.json` — new: `bevoegdheidstoedeling` schema, lifecycle, relations (GovernanceBody, Person, Decision, self-reference for ondermandaat), expiry notifications, seed data.
- `lib/Settings/decidesk_register.json` — edit: one nullable `bevoegdheidsgrondslag` property on the existing `Decision` schema (same base-file edit pattern as urgent-decision-procedure's urgency fields; no lifecycle or dialect change).
- `lib/Service/` + `appinfo/routes.php` — new thin ondermandaat guard service invoked from the save flow.
- `src/manifest.d/` + `src/manifest.json` — register index/detail pages, filters, CSV export, Decision detail gains the assistive bevoegdheidsgrondslag reference display.
- Specs: one new capability spec (`delegatie-mandaatregister`) owning the schema, chains, views, publication, linkage, and notifications. No MODIFIED deltas on existing specs — the Decision property and the publication carve-out are ADDED requirements in this capability's own spec (see design).

## Cross-Project Dependencies

None — self-contained within decidesk on existing OpenRegister capabilities (lifecycle, notifications dialect, published-predicate RBAC already in use by siblings). No OpenRegister or OpenConnector changes required.

## Risks

### Risk 1: Live public predicate exposes a future internal-only field
**Severity:** Medium — **Mitigation:** same discipline as toezeggingen-register D4: the schema is designed to contain only publishable fields (internal working notes belong in the audit trail, never on the object); the constraint is recorded in the schema `description` so it travels with the schema, and adding any non-public property requires revisiting the publication decision.

### Risk 2: Ondermandaat cycle or unauthorized chain slips through
**Severity:** Medium — **Mitigation:** server-side guard on save (parent exists, parent `ondermandaatToegestaan`, walking the parent chain never revisits a node, bounded depth), fail closed; PHPUnit covers the cycle, self-parent, and forbidden-parent cases.

### Risk 3: Register is mistaken for an enforcement system
**Severity:** Low — **Mitigation:** out-of-scope statement here, an explicit "assistive, never blocking" requirement with a negative scenario (a Decision without bevoegdheidsgrondslag proceeds unhindered), and UI copy that presents the link as documentation.

### Risk 4: Overlap drift with the verordeningenregister sibling on besluit publication
**Severity:** Low — **Mitigation:** boundary stated in scope and in the spec Notes: regeling-type document publication (CVDR/DROP) is exclusively verordeningenregister's; this change stores relations and publishes the register page only.

## Rollback Strategy

Revert the PR: the register.d fragment disappears (schema unregisters on the next import), manifest pages unregister, the guard route is removed, and the `bevoegdheidsgrondslag` property edit on Decision reverts (existing values remain harmlessly in stored objects as unvalidated extra data under OR's additive schema handling). Published register rows are withdrawn by clearing the predicate (`depublicatiedatum`) via the normal staff flow if desired. No data migration in either direction — the register starts empty apart from seed data.

## Open Questions

- Rappel windows for geldig-tot expiry (provisional: 60 and 14 days before) — griffie-configurable tuning deferred to a future admin-settings change.
- Whether a volmacht/machtiging to a named natural person outside the governance register (e.g. an external bewindvoerder) needs a plain-text person fallback in addition to the Person reference — provisionally covered by the function/role description field.
