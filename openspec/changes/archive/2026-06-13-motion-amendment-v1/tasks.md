# Tasks — motion-amendment-v1

## 1. Schema (additive only)

- [x] 1.1 `amendment`: add `proposedText` (string) + `votingOrder` (integer) to
      `lib/Settings/decidesk_register.json`.
- [x] 1.2 `meeting`: add `submissionDeadline` (date-time).

## 2. Backend — MotionService

- [x] 2.1 `transitionLifecycle()`: enforce `motion_min_cosigners` (default 0) on the
      motion `submitted → debating` edge; rejection message names minimum, current
      count and shortfall.
- [x] 2.2 `getAmendmentsForMotion()`: canonical resolver for BOTH link shapes (flat
      `parentMotion` property + structured relations), deduped; refactor
      `detectConflicts()` onto it (fixes property-linked amendments being invisible
      to conflict detection).
- [x] 2.3 `setAmendmentVotingOrder()`: validate ids belong to the motion, persist
      `votingOrder` 1..N.

## 3. Backend — VotingService + controllers + listener

- [x] 3.1 `openVotingRound()`: `subjectType` param (`motion`|`amendment`, fail
      closed); motion rounds rejected while amendments are undecided; amendment
      rounds rejected out of configured order; amendment rounds relate to the
      `amendment` schema and transition the amendment lifecycle.
- [x] 3.2 `closeVotingRound()`: amendment branch — transition amendment lifecycle;
      on adoption incorporate the amendment into the parent motion text via
      `applyAmendment()` (fail-soft).
- [x] 3.3 `castVote()` + `VotingController::resolveMeetingIdFromVotingRound()`:
      meeting resolution walks amendment → parent motion for amendment rounds.
- [x] 3.4 `VotingController::open()`: accept + validate `subjectType` (400 on
      invalid).
- [x] 3.5 `MotionController`: chair-only `amendmentOrder()` endpoint
      (`POST /api/motions/{id}/amendment-order`) + `requireChair()` guard (fail
      closed); `resolveMeetingIdFromMotion()` honours the flat `meeting` property;
      route registered in `appinfo/routes.php`.
- [x] 3.6 `SubmissionDeadlineListener` on `ObjectCreatingEvent`: reject late
      motion/amendment creations (stopPropagation + spec message); register in
      `Application.php`; add `ObjectCreatingEvent` test stub.
- [x] 3.7 `SettingsService`: `motion_min_cosigners` in CONFIG_KEYS.

## 4. Frontend

- [x] 4.1 `src/utils/textDiff.js`: LCS word diff (`diffWords`), `changeMagnitude`,
      `suggestVotingOrder` — pure, no new npm dependency.
- [x] 4.2 `AmendmentDiffView.vue`: green/red (+underline/strike) diff rendering with
      legend, CSS variables only.
- [x] 4.3 `AmendmentDiffTab.vue` on the AmendmentDetail manifest page (registry
      pattern); `proposedText` fallback to amendment text.
- [x] 4.4 `MotionAmendmentOrderTab.vue` on the MotionDetail manifest page: up/down
      reorder, scope-based suggestion, save via the chair-only endpoint.
- [x] 4.5 `src/registry.js` + `src/manifest.json` wiring.
- [x] 4.6 l10n: en.json + en_US.json + nl.json for all new strings (lossless merge,
      English keys).

## 5. Tests

- [x] 5.1 PHPUnit `VotingServiceAmendmentOrderTest`: ordering enforcement matrix.
- [x] 5.2 PHPUnit `MotionServiceCosignerThresholdTest` +
      `MotionServiceAmendmentOrderTest`.
- [x] 5.3 PHPUnit `SubmissionDeadlineListenerTest`: past/future/none/other-schema/
      lookup-failure.
- [x] 5.4 Vitest `tests/vitest/textDiff.spec.js`: empty/identical/full-replace/
      unicode/magnitude/suggestion.
- [x] 5.5 Playwright `tests/e2e/spec-coverage/motion-amendment.spec.ts`: diff tab +
      order tab render (@e2e annotations, defensive skips).
- [x] 5.6 Newman `tests/integration/decidesk-motion-amendment.postman_collection.json`
      wired into `tests/newman/run-all.sh`.

## 6. Verification

- [x] 6.1 `php -l` all changed PHP; full PHPUnit unit suite green (docker
      php:8.3-cli).
- [x] 6.2 `npm run build` green; vitest green.
- [x] 6.3 All 24 hydra gates green.
