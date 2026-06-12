# Tasks: Decision state machine v1

## 1. Schema (lib/Settings/decidesk_register.json)
- [x] 1.1 Add `lifecycle` enum (`draft|proposed|deliberating|voting|decided|enacted|archived`, default `draft`) and `enactedAt` (date-time) to `Decision`; bump schema version; document the three axes (lifecycle/outcome/isPublished) in property descriptions. Additive — no renames, `isPublished` untouched.
- [x] 1.2 Add `decision-transition` to the `BoardAuditLogEntry` `action` enum (additive) and to `AuditLogService::ACTIONS`.
- [x] 1.3 Add the ADR-031 `decisionProposed` notification rule (updated-trigger, filter lifecycle=proposed, governing-body recipients).
- [x] 1.4 Give the Decision seeds explicit lifecycle values (enacted for published+adopted, decided otherwise).

## 2. Backend (lib/)
- [x] 2.1 `lib/Lifecycle/DecisionTransitionGuard.php` — pure guard: transition map, per-domain policy (quorumEnforced / chairOnlyTransitions / allowDecideWithoutVote, default-deny fallback), `getAvailableActions()`, `resolveTransition()`, `isTransitionAllowed()`, `requiresChairAuthorization()`, `isQuorumRequired()`, `isVotingOpenAllowed()` (reads meeting `quorumMet`), `isEnactAllowed()` (outcome=adopted). SPDX header, @spec tags.
- [x] 2.2 `lib/Service/DecisionLifecycleService.php` — `transition(decisionId, action, userId, comment)` orchestration: OR find (RBAC/404) → map validation → domain policy → chair-only gate (fail closed) → quorum gate on openVoting → enact outcome gate → saveObject (named args; sets enactedAt on enact) → AuditLogService append `decision-transition`. Plus `getAvailableTransitions(decisionId)` for the UI.
- [x] 2.3 `DecisionController`: add `transition()` (POST) and `transitions()` (GET) — `#[NoAdminRequired]`, session check, per-object authorization via the service's ObjectService RBAC path; register routes in `appinfo/routes.php` (specific before wildcard).
- [x] 2.4 Register `DecisionLifecycleService` in `Application.php` and extend the `DecisionController` registration with the new dependency.

## 3. Frontend (src/)
- [x] 3.1 `src/components/tabs/decisionLifecycle.js` — pure helper: ordered STATES, label/color maps, `buildTimeline(current)`.
- [x] 3.2 `src/components/tabs/DecisionLifecycleTab.vue` — state visualization (done/current/upcoming chips) + allowed-transition buttons backed by `GET/POST /api/decisions/{id}/transition(s)`; error NcNoteCard; refresh after transition.
- [x] 3.3 `src/components/tabs/DecisionVotingTab.vue` — decision → motion → voting-rounds → votes; for/against/abstain tally per round + votes table (MotionVotesTab pattern).
- [x] 3.4 Register both tabs in `src/registry.js`; wire them into `DecisionDetail.sidebarTabs` in `src/manifest.json`; add the `lifecycle` badge column to the Decisions index page.
- [x] 3.5 i18n: English source keys via `t('decidesk', …)`; translations in `l10n/` for nl, de, fr, es, it (en source files updated).

## 4. Tests
- [x] 4.1 PHPUnit `tests/Unit/Lifecycle/DecisionTransitionGuardTest.php` — exhaustive allowed/forbidden matrix over all 7 states × all actions (MANDATORY), domain policy (chair-only, quorum flag, decide-without-vote, unknown-domain default-deny), quorum read, enact gate.
- [x] 4.2 PHPUnit `tests/Unit/Service/DecisionLifecycleServiceTest.php` — not-found, unknown action, invalid from-state, chair rejection + chair-unresolvable fail-closed, quorum block, enact-not-adopted block, happy propose with audit append, enactedAt set on enact.
- [x] 4.3 PHPUnit `tests/Unit/Controller/DecisionControllerTest.php` — transition/transitions: 401 unauthenticated, 422 missing action, 200 happy, 422 on service failure.
- [x] 4.4 Vitest `tests/vitest/decisionLifecycle.spec.js` — timeline builder + maps.
- [x] 4.5 Newman (`tests/integration/decidesk.postman_collection.json`) — lifecycle section: GET transitions contract, happy propose, invalid transition 422, unknown action 422, unauthenticated 401.
- [x] 4.6 Playwright `tests/e2e/spec-coverage/decision-lifecycle.spec.ts` — UI-only: lifecycle tab renders state flow + current state; voting tab renders tallies; gate-19 @e2e annotations for all new/modified scenarios (reason-bearing excludes for backend-only contracts). Update the stale "Symfony Workflow" comment in decision-management.spec.ts.

## 5. Verification
- [x] 5.1 `php -l` on all changed PHP; PHPUnit unit suite green (`phpunit -c phpunit-unit.xml`); vitest green.
- [x] 5.2 Run hydra gates (run-hydra-gates.sh) on the diff — all pass; @spec tags on every changed method.
- [x] 5.3 `openspec validate decision-state-machine-v1`; archive; update the main spec frontmatter status.
