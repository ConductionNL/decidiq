# Tasks: decision-detail-fullpicture

Config-first (ADR-031/ADR-032): schema + manifest before Vue. Acceptance criteria and verification are PLAIN bullets (not checkboxes) to stay under the 20 column-0 cap.

## 1. Config — Decision schema (`lib/Settings/decidesk_register.json`)

- [x] 1.1 Bump the Decision schema `version` and add the four modification relations to `x-openregister-relations` — `supersedes`, `repeals`, `implements`, `refersTo` (one-to-many → Decision); widen the existing `amends` description to "this decision modifies that decision" (do NOT add a second `amends`, per design D1); document the Akoma Ntoso / ORI / schema.org mapping in the descriptions.
- [x] 1.2 Add the `effectiveStatus` declarative calculation to `x-openregister-calculations` (materialised, derived from inbound decided/enacted `supersedes`/`repeals`, precedence repealed > superseded > lifecycle) — OR, if OR cannot express the inverse-relation lookup, omit it and record that the detail view derives it client-side (design D2).
- [x] 1.3 Add the ADR-031 `x-openregister-notifications` rule notifying the governance body when a decision becomes superseded/repealed (canonical dialect; passes notification-dialect gate).
- [x] 1.4 Add the `besluit-begroting-2027` seed (meeting-outcome, enacted, adopted) carrying `supersedes` → existing `besluit-begroting-2026`; optionally attach a short 3-stage route so the route timeline is non-empty in seed.

## 2. Config — manifest (`src/manifest.json`)

- [x] 2.1 Add two `DecisionDetail.config.sidebarTabs` entries: `route` (DecisionRouteTab, order ~12) and `related` (RelatedDecisionsTab, order ~35).
- [x] 2.2 Add an in-force filter to the Decisions index (`/decisions`) over the derived `effectiveStatus` — `quickFilters` (All / In force / Superseded / Repealed) if CnIndexPage supports it, else `filter` fallback (design D3).

## 3. Code — Vue tabs + registration (`src/`)

- [x] 3.1 `DecisionRouteTab.vue`: read the `route` relation (via `useRelationStore`/`useObjectStore`; register `decision-stage` schema if absent), render the ordered stage timeline (sequence/label/decision-maker name/stageType/method/status/outcome/decidedAt), highlight `currentStage`, show progress (`decidedStageCount`/`stageCount`), empty state for stageless decisions, and the effective-status banner above the timeline with chain navigation; surface "still to do" (current stage + open action-item count). Read-only.
- [x] 3.2 `RelatedDecisionsTab.vue` (+ add/remove dialogs in `src/modals/` per modal-isolation): peer-relation pattern (REQ-RTU-002) — outgoing + derived incoming groups, add via NcSelect (with `inputLabel`) object search + relation-type selector, remove via CnDeleteDialog, navigate on row activation, incoming read-only, inline server validation errors, empty-`objectId` short-circuit; reusable for future peer-typed relations.
- [x] 3.3 Register `DecisionRouteTab` + `RelatedDecisionsTab` as `kind: "page"` entries in `src/registry.js`; add i18n keys (English source) with nl + en translations for all new strings.

## 4. Code — integrity seam (only if not declarative)

- [ ] 4.1 If relation integrity (self-reference + effect-bearing cycle rejection) cannot be expressed declaratively, add a thin server-side validation seam hooked into the decision save path — relation CRUD stays on the OR object API (no pass-through controller; per-object authority guard, no bare `#[NoAdminRequired]`). Skip this task if the declarative constraint covers it.

## 5. Tests + verification

- [ ] 5.1 PHPUnit/vitest: derivation precedence (repealed > superseded > lifecycle), draft-exerts-no-effect, self-reference + cycle rejection, dual audit entries; vitest for both tab components (timeline render, empty state, peer-tab grouping, empty-objectId guard, inline errors).
- [ ] 5.2 Newman (`tests/integration/`): 403 on effect-bearing relation write without authority (IDOR), validation 4xx contract, derived inverse-query shape.
- [ ] 5.3 Playwright (UI only): route timeline with current stage highlighted; add a supersedes relation via the tab; effective-status banner + chain navigation on the superseded target; in-force filter excludes superseded/repealed; cycle error inline. Annotate for gate-19 (API/backend excludes already inline in the spec deltas).
- [ ] 5.4 Run hydra gates (notification-dialect, no-admin-idor, redundant-controller, modal-isolation, nc-input-labels, route-auth/reachability, spec-coverage `@spec` tags, e2e-coverage) + `composer check:strict`; fix anything pre-existing the touched files surface; bump `appinfo/info.xml` version and live-verify against the dev container (seed: 2026 superseded by 2027).

## Acceptance criteria

- The route tab renders a Decision's ordered DecisionStage route with the current stage highlighted and "N of M stages decided" progress; a stageless decision shows an empty state without error.
- The Related decisions tab adds/removes typed peer relations to existing decisions, shows derived incoming relations read-only, and surfaces server validation errors inline.
- A decision targeted by an enacted supersedes/repeals shows effectiveStatus superseded/repealed + a banner, while its lifecycle status and audit trail are unchanged; the in-force filter excludes it.
- Self-references and effect-bearing cycles are rejected; effect-bearing relation writes require governance-body authority (403 otherwise).
- The Decision schema change is additive and declarative (no DB migration); the supersedes seed makes the feature demonstrable on a fresh sync.

## Verification

- `python3 -c "import json; json.load(open('lib/Settings/decidesk_register.json'))"` and `... open('src/manifest.json')` parse cleanly.
- `openspec validate decision-detail-fullpicture --strict` passes.
- Live: opening `besluit-begroting-2026` shows the "Superseded by Programmabegroting 2027" banner + effectiveStatus superseded; `/decisions` in-force filter excludes it; route tab non-empty on a routed decision.
- All hydra gates green on touched files; `composer check:strict` passes.

## Quality checklist

- All new/changed business logic covered by PHPUnit / vitest.
- New/changed API behaviour covered by Newman; UI by Playwright.
- nl_NL + en_US translation strings added for new user-facing strings (ADR-007), English as the i18n key.
- Feature docs updated in `docs/` if user-facing (ADR-010).
- `openspec validate` passes.
