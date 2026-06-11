# Tasks: Citizen participation

## 1. Schemas + lifecycle plumbing (decidesk_register.json)
- [ ] 1.1 Add `ConsultationReaction` schema (relation to PublicConsultation, `body`, `moderationStatus: pending|approved|rejected`, `submitterId`, `submittedAt`, `moderationReason`; `hardDelete: false`, `searchable: false`; NO contact/PII fields) with an ADR-031 `x-openregister-notifications` rule notifying staff on pending reactions.
- [ ] 1.2 Add `anonymousReactionsAllowed` (boolean, default false) and `moderationPolicy` (`pre-moderation|post-moderation`, default `pre-moderation`) to `PublicConsultation`.
- [ ] 1.3 Rename `PublicConsultation.status` enum value `summarised` → `results-published` via declarative schema version bump 0.1.0 → 0.2.0 with value migration (same pattern as the quorum/actionitem declarative migrations).
- [ ] 1.4 Verify the two existing ADR-031 rules (`consultationDeadline`, `budgetProposalVotingDeadline`) still validate against the dialect gate after the schema edits.

## 2. Lifecycle + intake backend (lib/)
- [ ] 2.1 `ParticipationLifecycleService`: staff-only transitions for consultations (`draft→open→closed→results-published`) and budget rounds (existing enum), with server-side deadline guards on every intake/vote operation independent of stored status. Authorization via OR RBAC — no bespoke role checks.
- [ ] 2.2 Scheduled auto-close background job for consultations past `submissionDeadline`, registered via `Application::register()` boot context (IBootstrap — NOT the invalid `IRegistrationContext::registerJob` pattern that previously left decidesk jobs unregistered).
- [ ] 2.3 `ReactionIntakeService` + `ParticipationController::submitReaction`: authenticated intake (`#[NoAdminRequired]` + open/deadline guard) and anonymous intake (`#[PublicPage]` + `#[AnonRateLimit(limit: 5, period: 3600)]` + `#[BruteForceProtection]`), per-consultation `anonymousReactionsAllowed` gate, payload size cap, pseudonymous `submitterId` for anonymous, pending-by-default moderation status (anonymous always pre-moderated; authenticated auto-approve only under `post-moderation`).
- [ ] 2.4 Moderation endpoints: approve/reject with reason (staff RBAC guard per method — no `#[NoAdminRequired]` without a per-object guard, per the no-admin-idor gate); approval increments the derived `submissionCount`; rejection soft-retains the object.
- [ ] 2.5 Routes in `appinfo/routes.php` for transition, intake, moderation, and publication trigger ONLY — plain CRUD stays on the OR object API per ADR-022 (run the redundant-controller gate to confirm no pass-throughs).

## 3. Budget proposals + advisory voting (reuse voting machinery)
- [ ] 3.1 Proposal submission: authenticated create during `submission` phase with server-side `requestedAmount` validation (positive, ≤ `totalAmount`); staff validation transition `submitted→validated|rejected`.
- [ ] 3.2 Extend the voting tally machinery with advisory mode: factor the atomic tally update + duplicate detection so the `CitizenVote` path delegates to the shared service (no parallel tally implementation); no quorum, no secret ballot, no proxy, voor/tegen only.
- [ ] 3.3 Vote endpoint: one `CitizenVote` per authenticated citizen per `validated` proposal, conflict on duplicate, closed outside the `voting` window, tallies written atomically to `votesFor`/`votesAgainst`.
- [ ] 3.4 Result calculation: ranking by `votesFor` with greedy allocation within `totalAmount`, marking proposals funded/not-funded; advisory results never feed statutory decision outcomes.

## 4. Result publication (OpenCatalogi / published-predicate)
- [ ] 4.1 Verify on the deployed OR version that `@self.published` can be set via the OR object API for decidesk register objects (known magic-mapper gap — if blocked, raise an OR issue and gate this phase on it; NO app-local public page fallback).
- [ ] 4.2 `ParticipationPublicationService`: on `results-published` (consultation) / `resultsPublished: true` (budget), build the result summary object (reaction digest + staff response; ranked proposals + allocation + participation count), strip ALL voter/submitter identifiers, set `@self.published`.
- [ ] 4.3 OpenCatalogi routing: when installed and a target catalog is configured for the governance body, create the publication in that catalog; degrade with a staff-visible warning when absent.
- [ ] 4.4 Per-reaction opt-in publication by moderators (set `@self.published` on individual approved reactions; never blanket).

## 5. Frontend (staff + citizen views, NC-account only)
- [ ] 5.1 Staff views: consultation list/detail with lifecycle actions and configuration form (deadline, anonymous toggle, moderation policy); budget round list/detail with phase transitions and proposal validation; moderation queue view (approve/reject modals in `src/modals/`, NcSelect with `inputLabel`, i18n keys in English source).
- [ ] 5.2 Citizen participation view for authenticated users: open consultations with reaction form; budget rounds with proposal form (submission phase) and voting cards (voting phase).
- [ ] 5.3 Admin settings: instance defaults (default moderation policy, target catalog per governance body, anonymous rate-limit budget) via IInitialState/loadState — settings rendered by the NC settings framework, NOT added to the vue-router (admin-router gate).
- [ ] 5.4 Plain object reads/writes via `useObjectStore` against the OR object API; nl + en translations.

## 6. Tests + verification
- [ ] 6.1 PHPUnit: lifecycle guards (deadline-over-status, non-staff 403), intake matrix (auth/anon × enabled/disabled × pre/post-moderation), tally atomicity + duplicate rejection, allocation ranking, publication payload PII-free, auto-close job, enum value migration.
- [ ] 6.2 Newman (`tests/integration/`): anonymous intake 201/401/429 contract, vote window 4xx contract, RBAC/IDOR on rounds and moderation endpoints, negative routing assertion (no unauthenticated read of participation data on app routes), published-predicate read of the summary.
- [ ] 6.3 Playwright (UI only, per the Playwright-UI/Newman-API split): staff creates + opens a consultation; authenticated citizen submits a reaction; moderator approves from the queue; staff runs a budget round through submission→voting→results; citizen votes; publish shows the OpenCatalogi-absent warning. Annotate scenarios for gate-19 (excludes already inline in the spec deltas for API/backend scenarios).
- [ ] 6.4 Run hydra gates (notification-dialect, no-admin-idor, redundant-controller, route-auth/reachability, spec-coverage with `@spec` tags on all new methods, e2e-coverage) and `composer check:strict`; fix anything pre-existing that the touched files surface.
- [ ] 6.5 Live verify against the dev container: full consultation round-trip and budget round-trip, anonymous intake with rate-limit, published summary readable anonymously via the OR/OpenCatalogi surface; bump `appinfo/info.xml` version (immutable-cache bust).

## 7. Docs + follow-ups
- [ ] 7.1 Update docs/intro + feature docs for the participation domain (what is implemented vs. the dormant panel/deliberation schemas).
- [ ] 7.2 File follow-up issues: citizen panels + deliberation spec coverage (dormant schemas), weighted/ranked advisory methods, p3 `Notification` schema retirement, ORI export of participation data (companion `publish-decisions-via-opencatalogi`).
