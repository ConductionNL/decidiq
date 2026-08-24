# Tasks: audit-log-chain-tail-hash

## Implementation Tasks

### Task 1: Add a bounded "last audit-trail row" query
- **spec_ref**: `openspec/changes/audit-log-chain-tail-hash/specs/audit-trail-integrity/spec.md#requirement-req-alci-001-resolving-the-previous-hash-must-not-load-the-whole-chain`
- **files**: `lib/Service/AuditLogService.php`
- **acceptance_criteria**:
  - GIVEN the audit-trail schema has N rows THEN resolving `previousHash` for a new entry issues
    an OpenRegister query bounded to a single row (`limit: 1`, `order: timestamp DESC`), not a
    query bounded to N (or 10000) rows
- [ ] Add a private `loadLastEntry(object $objectService): ?array` method using
      `$objectService->findAll(['register' => 'decidiq', 'schema' => 'audit-trail', 'order' =>
      ['timestamp' => 'DESC'], 'limit' => 1])` and returning the single row (or `null` when the
      log is empty).
- [ ] Test: `loadLastEntry()` returns the most-recent row (by `timestamp`) for a fixture chain of
      3+ rows, and `null` for an empty chain.

### Task 2: Rewire `resolvePreviousHash()` to the bounded query
- **spec_ref**: `openspec/changes/audit-log-chain-tail-hash/specs/audit-trail-integrity/spec.md#requirement-req-alci-001-resolving-the-previous-hash-must-not-load-the-whole-chain`
- **files**: `lib/Service/AuditLogService.php:457-465`
- **acceptance_criteria**:
  - GIVEN an existing chain THEN `resolvePreviousHash()` returns the same `currentHash` value it
    returned before this change (functional parity), sourced from `loadLastEntry()` instead of
    `loadChain()`
  - GIVEN an empty chain THEN `resolvePreviousHash()` still returns `self::GENESIS_HASH`
- [ ] Replace the `loadChain()` + `end($chain)` call in `resolvePreviousHash()` with
      `loadLastEntry()`.
- [ ] Test: `append()` produces an unbroken hash chain (each entry's `previousHash` equals the
      prior entry's `currentHash`) across 3+ sequential `append()` calls, using the new bounded
      query path.
- [ ] Test (regression): a fixture chain with more than 1 row (e.g. 5 rows) still resolves the
      TRUE last row's hash, not an arbitrary row — guards against a future re-introduction of an
      unbounded/truncated query.

### Task 3: Leave `verify()` / `export()` untouched
- **spec_ref**: `openspec/changes/audit-log-chain-tail-hash/specs/audit-trail-integrity/spec.md#requirement-req-alci-002-whole-chain-reads-remain-on-the-existing-path`
- **files**: `lib/Service/AuditLogService.php` (no changes)
- **acceptance_criteria**:
  - GIVEN `verify()` or `export()` run THEN they continue to use `loadChain()` (they need the
    full ordered chain; only the hot write path changes)
- [ ] Confirm (no code change) `verify()` (`:210,214`) and `export()` (`:284,299`) still call
      `loadChain()`.
