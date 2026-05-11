# Quorum — Declarative Migration

## Problem

Decidesk's quorum logic (whether enough governance-body members are present
for a meeting to take valid decisions) lives in `lib/Service/QuorumService.php`
(168 LOC, 2 public methods). It is consumed exclusively by
`lib/Lifecycle/MeetingTransitionGuard.php` to decide whether a meeting may
transition `scheduled → opened`.

Per ADR-031 (schema-declarative business logic over service classes),
behaviour that fits an `x-openregister-*` extension belongs in the schema
register, not in a PHP service. Quorum is a derived property of a Meeting
and its related Participants — a textbook fit for `x-openregister-aggregations`
(participant counts) plus `x-openregister-calculations` (the boolean
`quorumMet` derived from those counts and the meeting's `quorumRequired`).

External consumers (GraphQL, dashboards, MCP discovery, future
manifest-driven UIs) cannot read `quorumMet` today without a service-layer
round-trip. Lifecycle guard logic also has to import + call a service to
ask a question the schema engine could answer with no PHP at all.

## Proposed Solution

Migrate Meeting's quorum properties to schema metadata in
`lib/Settings/decidesk_register.json`:

1. **`x-openregister-aggregations` on Meeting**: declare two cross-schema
   counts of related Participants (total members, present members),
   filtered on `governanceBody == @self.governanceBody`.
2. **`x-openregister-calculations` on Meeting**: declare `quorumPercentage`
   (presentCount / totalCount × 100) and `quorumMet` (presentCount ≥
   quorumRequired) as derived fields available on every Meeting object
   without a service round-trip.
3. **`MeetingTransitionGuard`** reads `meeting.quorumMet` from the object
   instead of calling `QuorumService::validateQuorum()`. The guard itself
   stays in PHP — ADR-031 explicitly preserves lifecycle guards as a
   legitimate PHP seam.
4. **Delete `lib/Service/QuorumService.php`** once the guard no longer
   imports it. Update DI registration in `Application.php`.

The migration depends on the OpenRegister aggregation engine supporting
**cross-schema filters via relation** (`@self.governanceBody`). This
capability is required by the design; if the engine doesn't yet support
it, the migration falls under ADR-031 exception 1 (extension missing or
insufficient) — see `design.md` for the decision and the OR feature ask.

## Capabilities

### Modified Capabilities

- `meeting-management` — Meeting schema gains declarative quorum aggregations
  + calculations; transition guard depends on the new derived fields.

### New Capabilities

(none)

## Stakeholders

- **Decidesk maintainers** — own the migration, sign off on schema changes.
- **OpenRegister team** — own the cross-schema aggregation engine; may need
  to extend it to support `@self.{relation}` filters (see design.md).
- **Hydra reviewers** — first ADR-031-aware spec on decidesk; the
  declarative-vs-imperative decision in `design.md` is the canonical
  worked example for the rest of decidesk's service migrations.

## References

- ADR-031 (hydra) — Schema-declarative business logic over service classes
- ADR-022 (hydra) — Apps consume OR abstractions
- `decidesk/lib/Service/QuorumService.php` — current implementation
- `decidesk/lib/Lifecycle/MeetingTransitionGuard.php` — sole consumer
- `decidesk/lib/Settings/decidesk_register.json` — Meeting schema (target)
- ActionItem in same register — working `x-openregister-aggregations` +
  `x-openregister-calculations` reference
