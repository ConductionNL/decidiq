# Resolution and Minutes Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- retire-board-portal

## Purpose

Resolutions and minutes are the formal output of decision-making for every
audience. Per ADR-005 a resolution is a `decision` with
`decisionType=resolution` (unified in C1); per ADR-006 corporate minutes are
`minutes` objects with mode=corp labels, not a parallel `BoardMinutes` schema.
This delta retires the parallel corporate `Resolution`, `BoardMinutes`,
`BoardVote`, and `BoardAuditLogEntry` schemas and folds them onto the universal
`decision` / `minutes` / `vote` / `auditTrail`.

## MODIFIED Requirements

### Requirement: Resolution Generation

The system MUST support generating formal resolution texts from adopted decisions. A resolution is a `decision` with `decisionType=resolution` (ADR-005, unified in C1) — there MUST NOT be a parallel `Resolution` schema. Resolutions MUST include the decision text, voting results, legal basis, date of adoption, and governing body, and MUST be stored as `decision` OpenRegister objects, optionally rendered as documents via Docudesk. This holds identically for every audience, including corporate boards (mode=corp); only the displayed labels adapt.

**Feature tier**: V1

#### Scenario: Generate a resolution from an adopted decision

@e2e exclude resolution records are generated server-side by the decision enact transition (decision-state-machine-v1); the triggering UI is the DecisionLifecycleTab covered by the decision-management spec's e2e suite — no separate minutes-side surface exists by design

- GIVEN a decision that has been adopted with voting results (14 for, 5 against, 1 abstain)
- WHEN the secretary triggers "Generate Resolution"
- THEN the system MUST create or update a `decision` object with `decisionType=resolution` carrying the decision text, voting results, adoption date, and governing body
- AND the resolution MUST have a unique sequential number per body (e.g., "2026-BES-042")
- AND the resolution MUST be available for export as PDF via Docudesk

#### Scenario: Generate a resolution with legal basis references

@e2e exclude backend template rendering with no UI surface of its own (PHPUnit-covered in MinutesGenerationServiceTest); the legal-basis text appears inside the generated document verified server-side

- GIVEN an adopted decision referencing Gemeentewet article 160
- WHEN the resolution is generated
- THEN the resolution MUST include the legal basis ("Gelet op artikel 160 van de Gemeentewet")
- AND the resolution text MUST follow Akoma Ntoso structure (preface, body, conclusions)

#### Scenario: Provide proof of proper adoption for notarial deed

- GIVEN a statute amendment resolution (a `decision` with `decisionType=resolution`) adopted with qualified majority
- WHEN the notary requests proof of proper adoption
- THEN the system MUST generate a complete package including: convocation proof, quorum verification, voting results, and the resolution text
- AND the package MUST be verifiable and tamper-evident

## REMOVED Requirements

### Requirement: Parallel corporate Resolution entity

**Reason**: Violates ADR-006 / ADR-005. The `Resolution` schema duplicated
`decision`; resolutions are now `decision` objects with
`decisionType=resolution`.

**Migration**: Done in C1 (`unify-decision-supertype`) — resolutions are stored
as `decision` objects with `decisionType=resolution`. This change deletes the
now-redundant `Resolution` schema and seeds, the `resolution#*` routes,
`ResolutionController`, `ResolutionService`, `WrittenResolutionService`,
`ResolutionLifecycleGuard`, the `ResolutionList`/`ResolutionDetail` Vue views,
and the `resolution` entry in `DecideskSearchProvider` (resolutions remain
searchable as `decision` objects).

### Requirement: Parallel corporate Board Minutes entity

**Reason**: Violates ADR-006. `BoardMinutes` duplicated `minutes` for the
corporate audience.

**Migration**: Corporate minutes are `minutes` objects with mode=corp labels.
A corporate minutes record is re-seeded by this change (slug
`notulen-rvc-2025-q2`). The `BoardMinutes` schema and seeds are deleted.

### Requirement: Parallel corporate Board Vote entity

**Reason**: Violates ADR-006. `BoardVote` duplicated `vote` / `voting-round`.

**Migration**: Corporate votes use the universal `vote` / `voting-round`
entities. The `BoardVote` schema and seeds, the `boardVote#*` routes,
`BoardVoteController`, and `BoardVoteService` are deleted.

### Requirement: Parallel corporate Board Audit Log entity

**Reason**: Violates ADR-006. `BoardAuditLogEntry` duplicated OpenRegister's
built-in `auditTrail`.

**Migration**: Corporate audit history uses OR's built-in `auditTrail`. The
`BoardAuditLogEntry` schema is deleted; the `auditLog#*` routes / `AuditLogController`
/ `AuditLogService` are retargeted onto the built-in audit trail or removed per
design.md (apply decides per file).

## Acceptance Criteria

- [ ] No `Resolution`, `BoardMinutes`, `BoardVote`, or `BoardAuditLogEntry` schema remains in the register.
- [ ] A corporate `minutes` seed exists (`notulen-rvc-2025-q2`).
- [ ] `DecideskSearchProvider` no longer references the deleted `resolution` schema; resolutions are searchable as `decision` objects.

## Notes

Related ADRs: ADR-005 (decision as universal supertype — resolution→decision,
C1), ADR-006 (mode adaptation), ADR-001 (Popolo, C2). eIDAS signing of minutes
becomes a Cycle-2 decision method (`signature`); this change only cleans eIDAS's
dangling board-schema references, it does not build the method.
