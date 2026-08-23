---
kind: code
---

# Proposal: urgent-decision-procedure

## Summary

Add an urgent/expedited decision procedure (spoedprocedure) to decidiq: an authorised actor (chair or a role configured per body) can flag a `Decision` as urgent with a recorded justification, run it through an expedited route variant — an emergency meeting with shortened convocation (recording the deviation from the body's regular notice period) or an expedited written round with a response deadline in hours — and a mandatory ratification (bekrachtiging) stage is auto-appended so the provisionally effective decision MUST land on the agenda of the ratifying body's next regular meeting, where ratification confirms it or triggers reversal via the existing decision-evolution relations. The whole flow reuses the existing route/stage machinery (`DecisionStage` with `stageType=ratifying`), the existing written-resolution path (BW 2:40, `VotingRound.votingDeadline`), and the existing convocation/notice-period computation — no parallel system.

## Motivation

Market evidence (2026-07-16 deep-dive, intelligence-DB feature `trigger-urgent-decision-process`, demand 1467, priority "must" — the 2nd-highest unresolved demand for decidiq): governance bodies across all 5 decidiq domains regularly need decisions faster than the regular meeting cadence — a college takes spoedbesluiten later bekrachtigd door de raad, corporate boards act under statutory urgent-resolution clauses subject to RvC/AVA ratification, association boards act between ALVs. Decidiq today covers only the regular route (decision-route: college → commissie → raad, MT → RvB → RvC) and BW 2:40 written resolutions between meetings (decision-management user story 4, `resolutionType=written-resolution`, `VotingRound.votingDeadline`). There is no urgency trigger, no shortened-notice path (the notice machinery only *warns* when a convocation is late — it cannot *record a deliberate deviation*), and no mandatory ratification follow-up. Without ratification tracking, urgent decisions are the exact place where governance accountability silently leaks.

## Affected Projects

- [ ] Project: `decidiq` — Decision urgency fields + guard, Meeting shortened-notice recording, expedited written round deadline, auto-appended ratifying stage + agenda placement, ProcessTemplate urgency policy, list/detail/dashboard indicators, declarative notifications, seed data

## Scope

### In Scope

1. **Urgency trigger on Decision**: `isUrgent` flag + required `urgencyReason`, `urgencyDeclaredBy`, `urgencyDeclaredAt`; only the meeting chair or a per-body configured role may trigger it; the declaration is recorded in the decision's immutable audit trail.
2. **Expedited route variant** reusing existing machinery:
   - (a) emergency meeting with shortened convocation: reuse `meetingType=extraordinary`, add recording of the deviation (`shortenedNotice`, actual notice vs the body's regular `noticePeriodDays`, deviation reason);
   - (b) expedited written round: reuse the BW 2:40 written-resolution path with `VotingRound.votingDeadline` set from a response deadline expressed in **hours**, bounded by per-body configuration.
3. **Mandatory ratification stage**: a `DecisionStage` with `stageType=ratifying` assigned to the configured ratifying body is auto-appended to the urgent decision's route; the decision is provisionally effective; the ratification item MUST be placed on the agenda of the next regular meeting of the ratifying body; ratification outcome confirms (stage `outcome=adopted`) or reverses (stage `outcome=rejected` → reversal recorded via the existing `repeals`/`supersedes` decision-evolution relations and derived `effectiveStatus`).
4. **Indicators**: "urgent — awaiting ratification" badge on the decision detail and list views; dashboard KPI counting urgent decisions awaiting ratification.
5. **Notifications**: declarative `x-openregister-notifications` rules on the Decision schema for urgency declared and ratification due, following the verified dialect and recipient rules of the decidesk-notifications spec.
6. **Per-body urgency configuration**: an `urgencyPolicy` object on `ProcessTemplate` (process-configuration) — allowed trigger roles, minimum notice floor (hours), response-deadline bounds (hours), ratification requirement — with fail-closed defaults when absent.

### Out of Scope

- Legal advice on which statutes/reglementen permit an urgent procedure — that is exactly why the policy is per-body configuration.
- Changing quorum rules — the existing quorum guards (meeting-quorum, decision state machine) apply unchanged to emergency meetings and expedited rounds.
- Emergency communication channels — the Talk leaf (discussion-via-talk-leaf) already exists; this change only sends standard NC notifications.
- New lifecycle states on `Decision` — urgency is orthogonal to the existing declarative lifecycle, exactly as `outcome` and `isPublished` already are.
- A separate "UrgentDecision" schema or parallel route system (ADR-005/ADR-006 forbid type-splitting the universal Decision supertype).

## Approach

Extend, never fork: urgency is additive fields on the existing `Decision` schema; the expedited paths are the existing Meeting convocation and VotingRound written-round mechanisms with deviation/deadline recording added; ratification is the existing `DecisionStage` `ratifying` stage type driven by the existing declarative stage lifecycle and route-progress calculations. Declarative wherever OpenRegister supports it (schema fields, `awaitingRatification` calculation, notification rules per ADR-031); two thin imperative seams where declarations cannot reach — the urgency-trigger authorization guard (extending the existing `DecisionTransitionGuard`/lifecycle-guard pattern, fail closed) and the ratification orchestration (stage append + agenda placement on the next regular meeting, following the existing `DecisionCascadeService` cross-object pattern). Details, including the ADR-031 justification for each imperative exception, in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/decidesk_register.json` — Decision urgency properties, `awaitingRatification` calculation, two notification rules; Meeting shortened-notice properties.
- `lib/Settings/register.d/` — new additive fragment for the `ProcessTemplate.urgencyPolicy` object (mirrors 43-process-config-v1).
- `lib/Lifecycle/` / `lib/Service/` — urgency-trigger guard; ratification orchestration service (stage append + agenda item on next regular meeting + reversal linking).
- `src/` — urgency declaration action + badge on decision detail, list column/filter, dashboard KPI, emergency-meeting deviation recording in the notice UI (extends `src/services/noticeRules.js` consumers).
- Specs: one new capability spec (`urgent-decision-procedure`, which also owns the list/detail/dashboard indicators) plus delta specs extending decision-management (Decision urgency fields), meeting-management (shortened-notice deviation on the convocation flow), process-configuration (`urgencyPolicy`), and decidesk-notifications (urgency rules). decision-route and decision-methods machinery is reused unchanged — no delta.

## Cross-Project Dependencies

None — self-contained within decidiq on existing OpenRegister capabilities (lifecycle, calculations, notifications dialect already in use). No OpenRegister changes required.

## Risks

### Risk 1: Ratification silently never happens

**Severity:** High — **Mitigation:** ratification is not advisory: the ratifying stage is auto-appended (not user-opt-in), the decision surfaces "awaiting ratification" on list/detail/dashboard until the stage is decided, a declarative notification fires when ratification is due, and agenda placement on the next regular meeting is orchestrated server-side with a visible failure state when no regular meeting is scheduled yet.

### Risk 2: Urgency trigger becomes a bypass of normal governance

**Severity:** High — **Mitigation:** trigger is guarded (chair or configured role only, fail closed when the role cannot be resolved — same posture as existing chair-only transitions), requires a recorded justification, is audit-trailed, and never skips quorum or lifecycle guards; the expedited paths still run the full existing state machine.

### Risk 3: Notice-floor and deadline-bounds config is wrong or absent

**Severity:** Medium — **Mitigation:** fail-closed defaults mirroring process-configuration's established pattern (malformed/absent template → default-deny): absent `urgencyPolicy` means the urgent procedure is unavailable for that body, never available-with-no-limits.

### Risk 4: PUT-semantic saves drop urgency fields

**Severity:** Medium — **Mitigation:** OR `saveObject` is PUT-semantic (nulls omitted schema props); every write path that updates a Decision must carry the urgency fields forward; tests assert an unrelated update survives with `isUrgent`/`urgencyReason` intact.

## Rollback Strategy

All schema changes are additive (new optional properties, one new fragment, new notification rules) — reverting the app code leaves inert data fields that existing views ignore. The guard and orchestration service are new code paths only reachable via the urgency trigger; disabling the trigger (revert or feature-gate via absent `urgencyPolicy`) restores pre-change behaviour exactly. No data migration to unwind; already-appended ratifying stages remain valid ordinary route stages.

## Open Questions

- Whether the ratifying body should be configurable per decision at trigger time (dropdown) in addition to the per-body default — provisionally: config default, overridable at trigger by the same authorised actor.
