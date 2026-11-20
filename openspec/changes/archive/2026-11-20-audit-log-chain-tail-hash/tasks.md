# Tasks: audit-log-chain-tail-hash

## Note (20 Nov): the write path this change targeted no longer exists

The proposal was written against the app-local writer, whose `resolvePreviousHash()` loaded up
to 10,000 `audit-trail` rows on every `append()` to read the last hash. That writer has since
been repointed at OpenRegister's audit trail: `append()` now writes through
`AuditTrailMapper::recordObjectEvent()`, which chains and seals the row platform-side, and
`verify()` delegates to `AuditHashService::verifyChain()`. There is no `resolvePreviousHash()`,
`loadLastEntry()` or `GENESIS_HASH` left to bound.

The problem the proposal named (an O(n) read on every governance action, and a silent break in
continuity past 10,000 rows) is therefore gone, by a stronger route than the one proposed: the
write path reads nothing at all. The tasks below were restated to pin that invariant with
regression tests, and the spec delta was restated the same way, so the requirement that lands in
`openspec/specs/audit-trail-integrity` describes the code as it is.

### Task 1: The write path reads nothing back
- **spec_ref**: `openspec/changes/archive/2026-11-20-audit-log-chain-tail-hash/specs/audit-trail-integrity/spec.md#requirement-req-alci-001-appending-an-audit-entry-must-not-read-the-audit-trail`
- **files**: `tests/Unit/Service/AuditLogServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a trail that already holds rows WHEN `append()` writes more THEN no audit-trail read is
    issued and the chain verifier is not called
  - GIVEN a refused append (unknown action, no uids, no resolvable object) THEN nothing is read or
    written
- [x] Test: `testAppendNeverReadsTheTrailHowLongItIs` seeds five rows, appends three more and
      asserts zero `findAll()` calls, no `verifyChain()` call and the rows landing in order.
- [x] Test: `testRefusedAppendReadsNothing` covers the three refusals.

### Task 2: Whole-chain reads stay on the read path
- **spec_ref**: `openspec/changes/archive/2026-11-20-audit-log-chain-tail-hash/specs/audit-trail-integrity/spec.md#requirement-req-alci-002-whole-chain-reads-stay-on-the-read-path`
- **files**: `tests/Unit/Service/AuditLogServiceTest.php` (`lib/Service/AuditLogService.php` unchanged)
- **acceptance_criteria**:
  - GIVEN `export()` runs THEN it reads the decidiq slice once, oldest first, bounded
  - GIVEN `verify()` runs on the whole chain THEN it delegates to the platform and reads no rows
- [x] Test: `testExportReadsTheWholeSliceOldestFirst` asserts one read, `limit` 10000,
      `created ASC`.
- [x] Test: `testWholeChainVerifyReadsNoRows` asserts `verifyChain(null)` once and no row read.
