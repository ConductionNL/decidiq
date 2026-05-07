# Decidesk Data Model

This document describes the OpenRegister schemas used by Decidesk and their
computed/derived fields. Static properties (stored fields) are documented in
`openspec/architecture/adr-000-data-model.md`.

---

## Meeting

### Derived fields (read-only, computed by OpenRegister engine)

The following fields are declared as `x-openregister-aggregations` and
`x-openregister-calculations` in `lib/Settings/decidesk_register.json` and
are available on every Meeting object returned by the API. They are computed
at read time (or materialised on write when `materialise: true`).

| Field | Type | Source | Description |
|-------|------|--------|-------------|
| `totalParticipantCount` | integer | aggregation | Count of all Participant objects linked to the Meeting's GovernanceBody |
| `presentParticipantCount` | integer | aggregation | Count of Participant objects with `attendanceStatus = "present"` linked to the Meeting's GovernanceBody |
| `quorumPercentage` | number | calculation | `(presentParticipantCount / totalParticipantCount) × 100`; returns `0` when `totalParticipantCount = 0` |
| `quorumMet` | boolean | calculation | `true` when `quorumRequired` is `null` (quorum not enforced) OR `presentParticipantCount >= quorumRequired` |

**Spec reference:** `openspec/changes/quorum-schema-declaration/tasks.md`

**Materialisation note:** `quorumPercentage` and `quorumMet` both declare
`materialise: true`, meaning the engine writes the computed value back to the
object on save. If the engine does not recompute on Participant writes, the
field may be stale until the Meeting is next saved — see
`openspec/changes/quorum-schema-declaration/design.md` § Risks for the
trade-off analysis.

---
