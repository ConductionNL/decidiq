## ADDED Requirements

### Requirement: REQ-ALCI-001 Resolving the previous hash MUST NOT load the whole chain
`AuditLogService::append()` MUST resolve the previous entry's `currentHash` via a query bounded
to a single row (the most recent `audit-trail` entry by `timestamp`), and MUST NOT load the
entire audit-trail chain to do so. The per-write cost of resolving `previousHash` MUST NOT scale
with the total number of audit-trail rows accumulated so far.

#### Scenario: Appending an entry against a large existing chain
- **GIVEN** an audit-trail schema with 10,000+ existing entries
- **WHEN** `AuditLogService::append()` is called to log a new governance action
- **THEN** the OpenRegister query used to resolve `previousHash` is bounded to a single row
  (`limit: 1`), not a query that fetches the full chain

#### Scenario: Chain integrity is preserved after the change
- **GIVEN** a sequence of 3 or more `append()` calls
- **WHEN** each entry's `previousHash` is inspected
- **THEN** entry N's `previousHash` equals entry N-1's `currentHash`, exactly as before this
  change (no functional regression in the hash chain)

#### Scenario: Empty chain still resolves to the genesis hash
- **GIVEN** no audit-trail entries exist yet
- **WHEN** `append()` resolves `previousHash`
- **THEN** it resolves to the `GENESIS_HASH` sentinel

### Requirement: REQ-ALCI-002 Whole-chain reads remain on the existing path
`AuditLogService::verify()` and `AuditLogService::export()` MUST continue to load the complete
ordered chain (via the existing `loadChain()` method) since both operations are legitimately
whole-chain by nature (tamper verification and bulk export). This requirement documents that the
bounded-query optimisation in REQ-ALCI-001 is scoped to the write path only.

#### Scenario: Verifying the chain still inspects every entry
- **GIVEN** an audit-trail chain of any size
- **WHEN** `verify()` is called with no `entryUuid` (whole-chain verification)
- **THEN** every entry in the chain is checked, unaffected by the REQ-ALCI-001 write-path change
