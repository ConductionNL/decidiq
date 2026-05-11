# Design: Quorum — Declarative Migration

## Status
proposed

## Background

QuorumService has two public methods, both called by `MeetingTransitionGuard`:

- `calculateQuorum(meetingId)` → `['quorumRequired', 'presentCount', 'percentage', 'met']`
- `validateQuorum(meetingId)` → `bool` (thin wrapper returning `met`)

The implementation:

1. Loads the Meeting object.
2. Reads `meeting.quorumRequired` (an integer minimum).
3. Loads the Meeting's related GovernanceBody.
4. Loads all Participant objects where `governanceBody == meeting.governanceBody`
   (paged at `_limit: 1000`).
5. Counts those whose `attendanceStatus == 'present'`.
6. Computes percentage and a `met` boolean (`presentCount >= quorumRequired`).

All four output fields are computable from Meeting + its related Participants
with no domain-specific logic the schema engine couldn't express, **provided
the engine supports cross-schema aggregation filters via `@self.{relation}`**.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Total participant count for the meeting's body | `x-openregister-aggregations.totalParticipantCount` on Meeting | Cross-schema count over Participant filtered by `governanceBody == @self.governanceBody`. Direct fit for the aggregation engine. |
| Present participant count for the meeting's body | `x-openregister-aggregations.presentParticipantCount` on Meeting | Same shape, additional `attendanceStatus == "present"` filter. |
| Quorum percentage (presentCount / totalCount × 100) | `x-openregister-calculations.quorumPercentage` on Meeting | Pure arithmetic over two aggregation results. Standard calculation expression. |
| Quorum met (presentCount ≥ quorumRequired) | `x-openregister-calculations.quorumMet` on Meeting | Pure comparison over an aggregation result and a property field. Standard calculation expression. |
| Transition gate ("can this meeting open?") | `MeetingTransitionGuard` (PHP) — reads `meeting.quorumMet` | Lifecycle guard, explicitly preserved as PHP per ADR-031 (`x-openregister-lifecycle.requires` calls into PHP for non-trivial preconditions). The guard itself shrinks to a one-liner: `return $meeting['quorumMet'] === true || $meeting['quorumRequired'] === null;`. |
| Two quorum rule formats (`fixed:N`, `percentage:N`) — currently documented in QuorumService docblock but only `fixed:N` is implemented | Property normalization on Meeting (`quorumRequired: integer`) | The current code already only honours integer `quorumRequired`; the `percentage:N` branch was never wired. Migration fixes documentation drift; no behavioural change. |

**Default chosen: declarative for all four derived fields. Exception (ADR-031
case 1) gated on engine capability — see "Engine dependency" below.**

## Engine dependency (ADR-031 exception clause)

The migration requires `x-openregister-aggregations` to support a filter
that resolves a foreign-object reference at evaluation time:

```jsonc
{
  "totalParticipantCount": {
    "metric": "count",
    "schema": "Participant",
    "filter": {
      "governanceBody": "@self.governanceBody"   // ← cross-schema reference
    }
  }
}
```

ActionItem's existing aggregations (`totalCompleted`, `byStatus`,
`completedThisMonth`, `totalOpen`) all aggregate the **same schema** they're
declared on. Cross-schema aggregation has not been observed in the existing
register. If the engine **does** support `schema:` + `@self.{relation}`,
ship the migration as designed. If it **doesn't**:

- File OR feature request: "schema-declarative aggregations: cross-schema
  filters via `@self.{relation}` references", referencing this design.md
  and ADR-031.
- Apply ADR-031 exception 1: keep `QuorumService` in place; mark Meeting
  schema with a `TODO(adr-031): migrate to x-openregister-aggregations
  once OR supports cross-schema filters` comment in the register file.
- Recheck this migration when the OR change ships.

The first task in `tasks.md` is the engine-capability spike: **prove the
cross-schema filter works (or doesn't) before committing to the rest of
the migration**.

## Impact on existing code

- `lib/Service/QuorumService.php` — deleted (assuming engine support).
- `lib/AppInfo/Application.php` — drop QuorumService DI registration.
- `lib/Lifecycle/MeetingTransitionGuard.php` — replace
  `$this->quorumService->validateQuorum($meetingId)` call with a direct
  `$meeting['quorumMet']` read. Drop the constructor's QuorumService dep.
- `tests/Unit/Service/QuorumServiceTest.php` — deleted.
- `tests/Unit/Lifecycle/MeetingTransitionGuardTest.php` — updated to
  fixture-load Meeting objects with `quorumMet` populated; no QuorumService
  mock needed.
- API surface: no controller currently exposes QuorumService directly
  (all consumers go through the lifecycle guard). External readers gain
  read-only access to `meeting.quorumPercentage` / `meeting.quorumMet`
  for free — desirable for dashboards and GraphQL.

## Seed data (ADR-001)

The Meeting schema already has seed objects in
`x-openregister-seeds`. After this migration each seed Meeting will
auto-gain `quorumPercentage` and `quorumMet` fields at materialise time
— no seed-data changes needed. Spot-check: pick one seed Meeting whose
governance body has 3+ Participant seeds and verify the materialised
`quorumMet` matches the property `quorumRequired` against the present
count.

## Reuse Analysis (ADR-001)

| OpenRegister abstraction | Used here |
|---|---|
| ObjectService | Already used by the guard for `$meeting`/`$body` lookups; unchanged |
| `x-openregister-aggregations` | New — `totalParticipantCount`, `presentParticipantCount` on Meeting |
| `x-openregister-calculations` | New — `quorumPercentage`, `quorumMet` on Meeting |
| `x-openregister-lifecycle.requires` (existing on Meeting `open` transition) | Continues to point at MeetingTransitionGuard — guard stays in PHP per ADR-031 |
| Aggregation engine cross-schema filter | **Engine feature dependency — see "Engine dependency" above** |

Nothing duplicates existing OR functionality. The migration **removes**
duplication (QuorumService duplicates what the aggregation + calculation
engines already provide for any other domain).

## Deduplication Check (ADR-001)

Searched `openspec/specs/` and `openregister/lib/Service/` for overlap
with quorum logic. No overlap found — quorum is decidesk-domain-specific.
The OR side of the equation is `x-openregister-aggregations` /
`x-openregister-calculations`, which are exactly what we're consuming
(not duplicating).

## Risks

1. **Engine doesn't support cross-schema aggregation.** Mitigated by
   gating the migration on the spike task (#1) and falling back to
   ADR-031 exception 1 with an OR feature request.
2. **Performance regression from on-read aggregation.** ActionItem's
   aggregations materialise on write/read — verify the engine
   materialises Meeting aggregations on **Participant attendance change**
   (not just on Meeting writes), or the `quorumMet` field stales.
3. **`materialise: true` on the calculation requires a recompute trigger.**
   Confirm the engine recomputes Meeting calculations when the underlying
   Participant aggregation refreshes; if not, treat aggregations as
   on-read (no `materialise`) and accept the per-read cost.
4. **Seed-data quorum sanity.** A seed Meeting whose seed Participants
   are all `attendanceStatus: null` will materialise `quorumMet: false`
   (presentCount=0). Acceptable: meetings start with no attendance; the
   field flips to `true` when the meeting opens. Document expected seed
   state in tasks.md.

## Out of scope

- Migration of the Meeting **lifecycle** itself (already shipped via
  `x-openregister-lifecycle` on Meeting in commit `905fa61`).
- Migration of voting-round quorum (a separate concept on `VotingRound`
  schema). Tracked under the separate VotingService → lifecycle migration.
- Adding a UI for displaying `quorumPercentage` / `quorumMet`. The fields
  are exposed read-only on the object; UI consumption is a follow-up.
