# Design: Quorum — Schema declaration (chain spec 1 of 3)

## Status
pr-created

## Spec kind & chain position (ADR-032)

- `kind: config` — only declarative JSON edits + integration test.
  Zero `lib/**/*.php` edits beyond the new test.
- Chain position: head (1 of 3). No `depends_on`. Successors
  (`quorum-guard-rewrite`, `quorum-service-deletion`) wait on this
  spec's issue closing.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Total participant count for the meeting's body | `x-openregister-aggregations.totalParticipantCount` on Meeting | Cross-schema count over Participant filtered by `governanceBody == @self.governanceBody`. Direct fit for the aggregation engine (engine support spike in task 1). |
| Present participant count for the meeting's body | `x-openregister-aggregations.presentParticipantCount` on Meeting | Same shape, additional `attendanceStatus == "present"` filter. |
| Quorum percentage (presentCount / totalCount × 100) | `x-openregister-calculations.quorumPercentage` on Meeting | Pure arithmetic over two aggregation results with zero-divide guard. Standard calculation expression. |
| Quorum met (presentCount ≥ quorumRequired, OR quorumRequired is null) | `x-openregister-calculations.quorumMet` on Meeting | Pure comparison. The null-quorumRequired branch keeps quorum-not-required meetings legal. |
| `MeetingTransitionGuard` reads `quorumMet` instead of calling `QuorumService` | **Chain spec 2** — out of scope here | Code change. Lives in `quorum-guard-rewrite`. |
| Delete `QuorumService.php` | **Chain spec 3** — out of scope here | Code change. Lives in `quorum-service-deletion`. |

## Engine dependency

Same engine question as the original (failed) `quorum-declarative-migration`
spec: does OR's aggregation engine support `schema:` + `@self.{relation}`?
Existing decidesk aggregations on ActionItem are all within-schema; this
spec is the first cross-schema aggregation.

Task 1 in `tasks.md` is the spike — declare a temporary aggregation,
import, query one seeded Meeting, verify the count. **Decision point:**

- If the count returns correctly → declare the four real blocks +
  integration test → spec is `done`. Successors unblock.
- If the engine errors → file OR feature request `[feature]
  Cross-schema aggregations via @self.{relation} filter`, paste this
  design.md's "Engine dependency" section, mark this spec
  `status: blocked-on-or`. Successors stay blocked.

## Impact on existing code

- `lib/Settings/decidesk_register.json` — add 4 declarative blocks to
  Meeting + bump Meeting's schema `version`.
- `tests/Integration/Meeting/QuorumDeclarativeTest.php` — new test
  file (this is the only PHP authored in this spec).
- `lib/Service/QuorumService.php` — **unchanged**. Continues to power
  the existing guard until chain spec 2 lands.
- `lib/Lifecycle/MeetingTransitionGuard.php` — **unchanged**. Still
  calls QuorumService.
- API surface: external readers gain read-only access to
  `meeting.quorumPercentage` / `meeting.quorumMet` for free.
  Useful for dashboards / GraphQL / MCP discovery.

## Seed data (ADR-001)

Meeting + Participant seeds already exist. After this spec, seed
Meetings auto-gain `quorumPercentage` + `quorumMet` at materialise
time. Spot-check in tasks.md task 8.

## Reuse Analysis (ADR-001)

| OpenRegister abstraction | Used here |
|---|---|
| `x-openregister-aggregations` | New — first cross-schema aggregation usage in decidesk |
| `x-openregister-calculations` | New on Meeting (existing on ActionItem) |
| ObjectService | Used by integration test only |
| Aggregation engine cross-schema filter | **Engine feature dependency — task 1 spike** |

No duplication of existing OR functionality. The schema engine extension
is consumed (ADR-022) rather than duplicated.

## Deduplication Check (ADR-001)

Searched `openspec/specs/` and `openregister/lib/Service/` for overlap
with quorum logic. None — quorum is decidesk-domain. The OR-side
aggregation+calculation engines are what we're consuming, not
duplicating.

## Risks

1. **Cross-schema aggregation engine gap.** Same risk as the original
   spec; gated on task 1 spike. Chain pauses cleanly if the engine
   isn't ready.
2. **Materialisation refresh on Participant attendance change.** The
   `quorumMet` calculation depends on the `presentParticipantCount`
   aggregation. If the engine doesn't recompute Meeting calculations
   when an underlying Participant write happens, the field stales.
   Task 8 verifies; if it stales, drop `materialise: true` and accept
   per-read cost (small N).
3. **Schema version bump coordination.** Other in-flight specs may
   also touch Meeting (e.g. analytics chain spec 1 if it lands in
   parallel). Coordinate version bumps so only one bump per release
   cycle.

## Out of scope

- All code changes (those live in chain specs 2 + 3).
- `actionItemCompletionRate` / Meeting analytics declarations — those
  are in the analytics chain, not quorum's.
