# Tasks — voting-rules-v1

## 1. Schema (additive only)

- [x] 1.1 `voting-round`: add `voteThreshold`, `abstentionHandling`, `tieBreakRule`,
      `chairCastingVote`, `revoteOfRound` to `lib/Settings/decidesk_register.json`.
- [x] 1.2 `participant`: add `participantType` (`in-person` | `remote`).
- [x] 1.3 `vote`: add `castAs` (`in-person` | `remote` | `unknown`).

## 2. Backend — VotingService

- [x] 2.1 `tallyResults()`: threshold-aware result per design.md formula; returns and
      persists computed base + applied rules; preserves existing weight handling.
- [x] 2.2 `openVotingRound()`: accept + validate `voteThreshold`,
      `abstentionHandling`, `tieBreakRule` (fail closed on unknown values) and
      `revoteOfRoundId` (revote-once guard); skip motion transition for revotes;
      preserve quorum gate, activity hook, votingOpened notifications.
- [x] 2.3 `closeVotingRound()`: optional `chairCasting` param — only honoured when
      `tieBreakRule == 'chair-decides'`, persisted as `chairCastingVote` before
      re-tally; preserve ORI publication, anonymisation, lifecycle hooks.
- [x] 2.4 `castVote()`: stamp `castAs` from the caster participant's
      `participantType` (`unknown` fallback); preserve delegation gate, proxy
      checks, secret-ballot tokens, idempotency slugs.

## 3. Backend — ProxyVoteService + controller

- [x] 3.1 `ProxyVoteService::register()`: per-holder per-meeting ACTIVE-proxy cap from
      app config `decidesk`/`max_proxies_per_holder` (default 2), fail closed.
- [x] 3.2 `VotingController::open()`: accept + validate rule fields and
      `revoteOfRound` (400 on invalid enum values).
- [x] 3.3 `VotingController::close()`: accept `chairCasting`, guarded by per-meeting
      chair-only role check (fail closed, 403).

## 4. Frontend

- [x] 4.1 `src/utils/votingRules.js`: pure helpers (computeBase, label maps).
- [x] 4.2 `VotingRoundPanel.vue` open-round dialog: threshold / abstention / tie-break
      selectors with defaults (English i18n keys).
- [x] 4.3 `VotingRoundPanel.vue` tally + results: show active rules and computed base;
      chair casting-vote controls and revote button on tied rounds.
- [x] 4.4 l10n: en.json + en_US.json + nl.json for all new strings (lossless merge).

## 5. Tests

- [x] 5.1 PHPUnit `VotingServiceTallyMatrixTest`: exhaustive threshold × abstention ×
      tie matrix incl. all tie-break rules and chair-casting resolution.
- [x] 5.2 PHPUnit `ProxyVoteServiceTest` additions: cap reached / under cap /
      configurable cap / count-failure fail-closed.
- [x] 5.3 PHPUnit `VotingServiceCastAsTest`: castAs stamping (in-person, remote,
      unknown fallback).
- [x] 5.4 Vitest `votingRules.spec.js`.
- [x] 5.5 Playwright `tests/e2e/spec-coverage/voting-rules.spec.ts` (@e2e annotations,
      defensive skips).
- [x] 5.6 Newman `tests/integration/decidesk-voting-rules.postman_collection.json`
      wired into `tests/newman/run-all.sh`.

## 6. Verification

- [x] 6.1 `php -l` all changed PHP; full PHPUnit unit suite green (docker php:8.3-cli).
- [x] 6.2 `npm run build` green; vitest green.
- [x] 6.3 All 24 hydra gates green.
- [x] 6.4 Spec delta synced; main spec frontmatter updated honestly.
