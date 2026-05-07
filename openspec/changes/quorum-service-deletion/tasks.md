# Tasks: Quorum — Service deletion (chain spec 3 of 3)

> **`kind: code` (small)** — file deletions + DI line removal.
>
> **Depends on**: `quorum-guard-rewrite` (chain spec 2) closed. Hydra
> blocks this from building until chain spec 2's issue is closed
> (merged).

## 1. Regression scan first

- [ ] `grep -rn "QuorumService\|->validateQuorum\|->calculateQuorum"
      lib/ src/ tests/`.
- [ ] Expected hits BEFORE deletion:
  - `lib/Service/QuorumService.php` (the file)
  - `lib/AppInfo/Application.php` (DI registration)
  - `tests/Unit/Service/QuorumServiceTest.php` (if exists)
- [ ] **No hits expected** in `lib/Lifecycle/`, `lib/Controller/`,
      `src/`, or any other test file.
- [ ] If unexpected caller appears: STOP, document the caller in this
      spec's design.md, and either expand this spec's scope to migrate
      that caller too (small) or escalate to a new chain spec (if
      non-trivial).

## 2. Delete the files

- [ ] `git rm lib/Service/QuorumService.php`
- [ ] `git rm tests/Unit/Service/QuorumServiceTest.php` (skip if file
      doesn't exist).

## 3. Remove DI registration

- [ ] In `lib/AppInfo/Application.php`, locate any line that registers
      QuorumService (typically `$context->registerService(QuorumService::class, ...)`
      or implicit via `register()` → Container, depending on the
      app's pattern). Remove that line(s).
- [ ] Drop the `use OCA\Decidesk\Service\QuorumService;` import from
      Application.php if present.

## 4. Cross-reference cleanup

- [ ] `grep -rn "QuorumService" .` (whole repo, including docs).
  - Markdown / docs references → update to past-tense or remove.
  - PHPDoc `@spec` tags pointing at QuorumService → update or remove.
- [ ] Update `docs/data-model.md` (or equivalent) Meeting section if
      it referenced QuorumService — replace with a one-liner naming
      `quorumMet` as the canonical quorum source.

## 5. Verify

- [ ] `grep -rn "QuorumService\|->validateQuorum\|->calculateQuorum"
      lib/ src/ tests/` returns **zero hits**.
- [ ] `composer check:strict` exits 0.
- [ ] `phpunit` exits 0 (no QuorumService test, no consumers; the
      MeetingTransitionGuardTest from chain spec 2 still passes).
- [ ] All hydra-gates pass.

## 6. PR description

- [ ] PR title: `chore(quorum-chain-3): delete QuorumService (now obsolete) (#<issue>)`
- [ ] PR body links chain spec 1's merged PR, chain spec 2's merged
      PR, and notes the chain is now complete.

## Deduplication Check

- [ ] No new code introduced; only deletions. No duplication risk.
