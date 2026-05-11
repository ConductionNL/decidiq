# Tasks: Quorum — Guard rewrite (chain spec 2 of 3)

> **`kind: code` (small)** — 2 files edited, 1 test file updated. ~30 LOC change.
>
> **Depends on**: `quorum-schema-declaration` (chain spec 1) closed.
> Hydra's supervisor blocks this from building until spec 1's issue
> is closed (merged).

## 1. Edit MeetingTransitionGuard

- [ ] In `lib/Lifecycle/MeetingTransitionGuard.php`:
  - Drop the constructor parameter
    `private readonly QuorumService $quorumService`.
  - In the method that handles the `open` transition's precondition,
    locate the existing `$this->quorumService->validateQuorum(...)`
    call.
  - Replace it with reading the Meeting object's `quorumMet` field
    directly:
    ```php
    return ($meeting['quorumMet'] ?? false) === true;
    ```
  - The Meeting object is already loaded earlier in the guard — reuse
    the existing `$meeting` variable rather than fetching twice.
  - Drop the now-unused `use OCA\Decidesk\Service\QuorumService;` import.
- [ ] Update the file's `@spec` PHPDoc tag to point at this change's
      tasks.md.

## 2. Update Application.php DI

- [ ] In `lib/AppInfo/Application.php`, find the registration of
      `MeetingTransitionGuard` (or its auto-wiring) and remove the
      explicit `QuorumService` argument if one is passed.
- [ ] Do NOT remove the `QuorumService` registration itself. Chain
      spec 3 (`quorum-service-deletion`) handles that.

## 3. Regression scan

- [ ] Run `grep -rn "QuorumService\|->validateQuorum\|->calculateQuorum"
      lib/ src/`. The only remaining hits should be:
  - `lib/Service/QuorumService.php` (the service file itself)
  - `lib/AppInfo/Application.php` (the surviving service registration)
  - `tests/Unit/Service/QuorumServiceTest.php` (if it exists)
  - **Zero hits in `lib/Lifecycle/`, `lib/Controller/`, `src/`**.
- [ ] If any unexpected caller appears, **stop** and update this
      spec's design.md before continuing — chain spec 2's scope was
      drafted assuming MeetingTransitionGuard is the sole caller.

## 4. Tests

- [ ] Update `tests/Unit/Lifecycle/MeetingTransitionGuardTest.php`:
  - Remove the QuorumService mock setup (constructor used to take it;
    no longer does).
  - Update fixture-load Meeting objects to include `quorumMet` field.
  - Three required cases:
    - `testOpenAllowedWhenQuorumMet` — Meeting fixture with
      `quorumMet = true` → guard allows the `open` transition.
    - `testOpenBlockedWhenQuorumNotMet` — Meeting fixture with
      `quorumMet = false` → guard blocks.
    - `testOpenAllowedWhenNoQuorumRequired` — Meeting fixture with
      `quorumRequired = null` and `quorumMet = true` (the null
      branch) → guard allows.
- [ ] `phpunit tests/Unit/Lifecycle/MeetingTransitionGuardTest.php`
      exits 0.

## 5. Quality gates

- [ ] `composer check:strict` exits 0.
- [ ] All hydra-gates pass (see `hydra-gates` skill — license headers,
      no forbidden patterns, no stub scan, etc.).

## 6. PR description

- [ ] PR title: `feat(quorum-chain-2): MeetingTransitionGuard reads quorumMet declaratively (#<issue>)`
- [ ] PR body links chain spec 1's merged PR + chain spec 3's pending
      issue. Reviewers see the chain context immediately.

## Deduplication Check

- [ ] No duplicate logic introduced. The guard now reads a declarative
      field in place of calling a service; net code reduction.
