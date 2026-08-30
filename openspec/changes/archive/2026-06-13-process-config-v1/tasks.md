# Tasks: process-config-v1

## 1. Schema + built-in templates
- [x] 1.1 Add `processTemplate` schema as an ADR-037 fragment (`lib/Settings/register.d/43-process-config-v1.json`): name, description, context, builtIn, initialState, stateMachine{states[],transitions[]}, votingRule{voteThreshold,abstentionHandling,tieBreakRule}, quorumRule, quorumRequired, allowDecideWithoutVote.
- [x] 1.2 Seed five built-in templates via `x-openregister-seeds` (association-alv, association-board, corporate-board, municipal-council, operational-team), each `builtIn:true` with legally-appropriate states + voting rules.

## 2. Backend services
- [x] 2.1 `ProcessTemplateService`: list / get / create / update / duplicate / delete over ObjectService (find/findAll/saveObject/deleteObject, named args). builtIn templates refuse edit/delete; duplicate clears builtIn.
- [x] 2.2 `ProcessTemplateService::validateStateMachine()`: reject empty states, dangling transitions (state not in states[]), unreachable states, unknown guard tokens. Fail-closed (400 on invalid).
- [x] 2.3 `ProcessTemplatePolicyResolver` (pure): translate a template into the guard policy shape `{quorumEnforced, chairOnlyTransitions, allowDecideWithoutVote}`; return null when the template is malformed (→ fallback, never fail-open).
- [x] 2.4 `ProcessTemplateService::resolveVotingRule()`: return a body's template votingRule defaults, or null.

## 3. Additive guard wiring
- [x] 3.1 `DecisionTransitionGuard`: add optional `?array $policyOverride = null` to getDomainPolicy / isTransitionAllowed / requiresChairAuthorization / isQuorumRequired / getAvailableActions. Null → today's hardcoded constants.
- [x] 3.2 `WorkflowService`: same optional `?array $policyOverride = null` on getDomainWorkflow + dependents.
- [x] 3.3 `DecisionLifecycleService`: resolve the linked meeting's governanceBody → template → policy override via the resolver; pass it through the guard calls. Null when no template assigned.
- [x] 3.4 `VotingService::openVotingRound()`: optional `?string $governanceBodyId`; when supplied, source rule defaults from the body template (explicit caller values win).

## 4. Admin surface (admin-gated)
- [x] 4.1 `ProcessTemplateController` with `#[AuthorizedAdminSetting(AdminSettings::class)]` on every method; routes in appinfo/routes.php.
- [x] 4.2 `ProcessTemplates.vue` admin section (list + create + duplicate + delete) inside the existing Decidesk admin panel (Settings.vue).
- [x] 4.3 `ProcessTemplateEditModal.vue` (src/modals/) with `StateMachineEditor.vue` + voting-rule form; NcSelect inputLabel; client-side graph validation.
- [x] 4.4 Pinia store module `processTemplates.js`; registry wiring.

## 5. i18n + manifest
- [x] 5.1 English source keys in l10n/en.json + en_US.json; nl.json translations. Do NOT touch de/fr/es/it.

## 6. Tests
- [x] 6.1 PHPUnit: `ProcessTemplateServiceTest` (CRUD, builtIn protection, duplicate), graph-validation (reject unreachable/dangling), `ProcessTemplatePolicyResolverTest` (translate + malformed→null).
- [x] 6.2 PHPUnit: guard-consults-template-with-fallback (override applied; null → hardcoded), voting-rule defaults applied.
- [x] 6.3 vitest: editor graph-validation logic.
- [x] 6.4 Playwright `tests/e2e/spec-coverage/process-configuration.spec.ts` with @e2e annotations + defensive skips.
- [x] 6.5 Newman `tests/integration/decidesk-process-config.postman_collection.json` (admin 200 / non-admin 403) wired into tests/newman/run-all.sh.

## 7. Wrap-up
- [x] 7.1 `npm run build` green; full PHPUnit + vitest + Newman green.
- [x] 7.2 All 24 hydra gates green.
- [x] 7.3 openspec validate + archive; update main spec frontmatter to done with honest residue note.
