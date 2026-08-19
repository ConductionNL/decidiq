---
kind: code
depends_on:
  - appointment-decision-type-schema
---

# Proposal: appointment-decision-type-membership

## Summary

Ships the imperative half of ADR-005's `decisionType=appointment` completion:
when an appointment decision reaches `enacted` with `outcome=adopted`,
`DecisionLifecycleService` materializes one `Membership` per candidate (Person
+ Post/role + GovernanceBody), populating the `appointedMemberships` field the
dependent-on change `appointment-decision-type-schema` already declared but
left inert. Also makes the new fields (`targetBody`, `targetPosts`,
`targetRole`, `candidates`, `nominatingParty`, `appointedMemberships`) visible
on the generic Decision detail page's field-scoped content widget, which
today excludes them.

## Motivation

`appointment-decision-type-schema` deliberately shipped config-only: the
folded fields exist, but nothing populates `appointedMemberships`, and the
`DecisionDetail` page's `decision-content` widget still uses the pre-fold
`content.include` list (which doesn't mention the new fields), so an
appointment decision's own detail page cannot show its candidates or target
body/post yet. This change closes both gaps: the missing behaviour (Membership
materialization — the same "Assistive Membership creation on benoeming" the
original, now-retired `Voordracht` design already concluded had to be
imperative) and the missing visibility (a one-line manifest edit).

## Affected Projects

- [x] Project: `decidesk` — `lib/Service/DecisionLifecycleService.php` gains a
      guarded post-transition effect; `src/manifest.json`'s `decision-content`
      widget gains 6 fields to its `content.include` scope.

## Scope

### In Scope

- Guard the `enact` transition for `decisionType=appointment` decisions:
  reject with a clear message when `targetPosts` has more than 1 entry and its
  length doesn't match `candidates`' length (see design.md D1 for the pairing
  rule) — a fail-closed gate, following the existing quorum-before-voting /
  outcome-before-enact pattern in `resolveRejection()`.
- On a successful transition into `enacted` where `decisionType=appointment`
  and `outcome=adopted`, materialize one `Membership` per candidate (`person`
  when the candidate carries one, else `label=externalName`), each with
  `role=targetRole`, `governanceBody=targetBody`, the paired `post` (per the
  D1 rule), and `startDate=enactedAt`. Patch the decision's
  `appointedMemberships` with the created Membership ids.
- Idempotency guard: skip materialization if `appointedMemberships` is
  already non-empty (defensive — `enacted` is a terminal state unreachable
  twice through the transition guard, but the check is cheap and matches the
  existing defensive style in this service).
- Add `targetBody`, `targetPosts`, `targetRole`, `candidates`,
  `nominatingParty`, `appointedMemberships` to the `decision-content` widget's
  `content.include` in `src/manifest.json`, so they render on the generic
  `DecisionDetail` page (no bespoke Vue form exists for Decision — verified:
  the archived `unify-decision-supertype` change touched zero `.vue` files,
  only `src/store/store.js`; Decision create/edit and detail rendering are
  entirely generic, manifest-driven).
- PHPUnit unit tests for the new guard and materialization logic.

### Out of Scope

- A bespoke Vue component or form for appointment fields — none is needed;
  the generic manifest-driven data widget already renders/edits every
  included schema property (verified against how `motionType`/`proposer`
  etc. already work for `decisionType=motion` on the very same widget).
- The "Nominations" nav entry (Besluiten pre-filtered to
  `decisionType=appointment`) — owned by `ia-six-clusters`.
- Re-deriving `RoosterRegel`/`TermijnRegeling` term data from the newly
  materialized Memberships — `RoosterService::deriveTerm()` already consumes
  any `Membership`, regardless of how it was created; no change needed there.

## Approach

Extend `DecisionLifecycleService::applyPostTransitionEffects()` — which
already runs a comparable non-declarative effect on `enacted`
(`generateResolutionRecord()`) — with a new private method,
`materializeAppointmentMemberships()`, following the same pattern: log loudly
on failure, do not roll back the already-persisted lifecycle transition. Add
one guard clause to `resolveRejection()` for the posts/candidates pairing
check. See design.md for the full pairing rule and field mapping.

## New Dependencies

None.

## Impact

- `lib/Service/DecisionLifecycleService.php` — one new guard clause, one new
  private method, called from the existing `applyPostTransitionEffects()`.
- `src/manifest.json` — `content.include` array extended by 6 entries on one
  existing widget.
- `tests/Unit/Service/DecisionLifecycleServiceTest.php` (or equivalent) —
  new test cases.

## Cross-Project Dependencies

Depends on `appointment-decision-type-schema` (`depends_on` in frontmatter):
requires the folded `Decision` fields and the retirement of `Voordracht` to
exist first. No other project dependency.

## Risks

### Risk 1: Candidates/posts pairing ambiguity produces a wrong Membership
**Severity:** Medium — **Mitigation:** The pairing rule (design.md D1) is
narrow and fails closed: index-pairing only when lengths match exactly, a
single shared post when `targetPosts` has exactly one entry, no post
(role-only Membership) when `targetPosts` is empty, and an explicit
transition-blocking rejection for any other mismatch — never a silent guess.

### Risk 2: Materialization runs twice on a retried/duplicated enact call
**Severity:** Low — **Mitigation:** `enacted` is reachable only from `decided`
in the guarded transition map and is itself a state with no outbound
transition except `archived`/`withdrawn` in the map (i.e. not re-enterable);
the idempotency check on `appointedMemberships` is a defensive second layer.

## Rollback Strategy

Revert the branch. No data migration — the newly materialized `Membership`
objects from any already-enacted appointment decisions during a rollback
window would remain as valid `Membership` records (they are correct data,
not artifacts of a bug), so no cleanup step is required; if desired, remove
manually via the standard `Membership` CRUD.

## Open Questions

None — the pairing rule and materialization trigger are resolved in design.md.
