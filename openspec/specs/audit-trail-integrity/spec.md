# audit-trail-integrity Specification

## Purpose

Keeps writing a governance action to the tamper-evident audit trail cheap and constant, however
long the trail grows, by leaving the hash chain to OpenRegister.

## Requirements

### Requirement: REQ-ALCI-001 Appending an audit entry MUST NOT read the audit trail
`AuditLogService::append()` MUST write its entry through OpenRegister's audit-trail writer, which
resolves the previous hash and seals the row, and MUST NOT read audit-trail rows back to do so.
The cost of an append MUST NOT grow with the number of rows already in the trail, and a refused
append MUST NOT read or write anything.

#### Scenario: Appending against a trail that already holds entries
- **GIVEN** an audit trail that already holds decidiq entries
- **WHEN** `append()` logs three more governance actions
- **THEN** no audit-trail read is issued, the chain verifier is not called, and the three
  entries land after the existing ones in order

#### Scenario: A refused append touches nothing
- **GIVEN** an append with an unknown action, no object uids, or no uid that resolves
- **WHEN** `append()` is called
- **THEN** it answers `success: false` and the trail is neither read nor written

### Requirement: REQ-ALCI-002 Whole-chain reads stay on the read path
`AuditLogService::export()` and `query()` MUST read the decidiq slice of the trail oldest-first
in one bounded query, and `verify()` MUST delegate whole-chain verification to OpenRegister's
chain verifier without reading rows itself. Whole-chain work happens only when a person asks for
it, never on the write path.

#### Scenario: Exporting reads the slice once, oldest first
- **GIVEN** a trail with five decidiq entries
- **WHEN** `export()` runs over a period holding all five
- **THEN** it issues one read ordered by `created` ascending and bounded to 10,000 rows, and
  exports five entries

#### Scenario: Verifying the whole chain is the platform's
- **GIVEN** a trail of any size
- **WHEN** `verify()` is called without an entry uuid
- **THEN** OpenRegister's `verifyChain()` is called once for the whole chain and no row is read
