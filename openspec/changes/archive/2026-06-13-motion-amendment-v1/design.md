# Design — motion-amendment-v1

## 1. Amendment diff view

### Data

The amendment schema gains `proposedText`: the full proposed replacement text of the
affected motion passage. The diff is computed client-side between the parent motion's
`text` (original) and the amendment's `proposedText` (falling back to the amendment's
`text` when `proposedText` is unset, so legacy amendments still render a diff).

### Algorithm — `src/utils/textDiff.js`

Pure, dependency-free, word-level LCS diff:

1. Tokenise both texts into words with a Unicode-aware split (`/\s+/u`), so Dutch
   diacritics and non-Latin scripts are single tokens.
2. Trim the common prefix and suffix (cheap, makes typical amendments — one changed
   paragraph — near-O(n)).
3. Run a classic dynamic-programming LCS on the remaining middle windows. Guard:
   when `m × n` exceeds 250 000 cells the middle is emitted as one removal + one
   addition block instead (bounded memory; correctness preserved, granularity reduced).
4. Backtrack into `{ type: 'equal' | 'removed' | 'added', text }` segments, merging
   consecutive same-type tokens.

`changeMagnitude(original, proposed)` = added + removed word count — the scope metric.
`suggestVotingOrder(amendments, motionText)` sorts a copy most-far-reaching-first by
`changeMagnitude(motionText, proposedText || text)` (ties: earlier `submittedAt` first).

### UI

- `AmendmentDiffView.vue` — presentational; renders segments as `<ins>` (background
  `var(--color-success-hover)`, fallback success tint) and `<del>`
  (`var(--color-error-hover)` tint, strike-through) inside a `<p role="document">`
  block; includes a colour-independent legend (WCAG 1.4.1 — additions also underlined,
  removals struck through, so colour is not the only carrier).
- `AmendmentDiffTab.vue` — sidebar tab on the manifest `AmendmentDetail` page
  (registry `kind: "page"` entry, same contract as `AmendmentParentMotionTab`).
  Resolves the amendment + parent motion via `ensureRelationType` and feeds the view.

## 2. Chair-controlled amendment voting order

### Link-shape reality

Amendments are linked to motions two ways in production data: the flat `parentMotion`
property (what the UI writes) and structured `relations` entries (what some backend
paths write). `MotionService::getAmendmentsForMotion()` is the new canonical resolver:
it queries BOTH shapes (`filters: {parentMotion}` plus `_relations.motion` with an
exact relation re-check) and dedups by id. `detectConflicts()` moves onto it — fixing
the pre-existing bug where property-linked amendments never reached conflict detection.

### Ordering model

- `votingOrder` (integer, additive) on the amendment. The chair sets it via
  `POST /api/motions/{id}/amendment-order` with `orderedAmendmentIds: [..]`; the
  service validates every id belongs to the motion and persists positions 1..N.
- Deterministic comparison everywhere: sort by (`votingOrder` ?? ∞, `submittedAt`,
  id). Unordered amendments therefore queue after ordered ones, oldest first.

### Enforcement — `VotingService::openVotingRound()`

New `subjectType` parameter (`motion` default | `amendment`, unknown values throw —
fail closed):

- `subjectType=motion`: reject when any amendment of the motion is still in
  `submitted` / `debating` / `voting` ("amendments are voted before the main motion").
- `subjectType=amendment`: resolve the parent motion, sort siblings by the
  deterministic comparison, find the FIRST sibling not yet decided
  (lifecycle ∉ {adopted, rejected}); reject when the requested amendment is not that
  one. The round's relation uses schema `amendment` and the lifecycle transition runs
  with `objectType: 'amendment'`.
- `closeVotingRound()` learns the amendment branch: result `adopted`/`rejected`
  transitions the amendment; on adoption the parent motion text is updated via the
  existing `applyAmendment()` (fail-soft, logged) so the final motion text
  incorporates adopted amendments.
- `castVote()`'s #300 meeting-membership check walks
  round → amendment → parentMotion → motion → meeting for amendment rounds, so the
  membership guard is not silently skipped.

### Authorization

Setting the order is the chair's prerogative (not the secretary's): a new
`MotionController::requireChair()` mirrors `VotingController::requireChair()` —
per-meeting `ParticipantResolver::hasRole(roles: ['chair'])`, global
`chair_group`/admin fallback when no meeting is resolvable, fail closed (403).
`resolveMeetingIdFromMotion()` additionally reads the flat `meeting` property so
property-linked motions get the per-meeting check instead of the global fallback.

## 3. Co-signer minimum threshold

App config `decidesk`/`motion_min_cosigners` (string-stored like all decidesk config,
cast to int; default `0` = disabled; exposed through `SettingsService::CONFIG_KEYS`).
Enforced inside `MotionService::transitionLifecycle()` on the motion
`submitted → debating` edge only — submission itself stays possible (the spec lets
the member "add more co-signers and resubmit"). The `InvalidArgumentException` message
names the requirement, the current count and the shortfall, and surfaces as the
existing 400 contract of `MotionController::transition()`.

## 4. Submission deadline enforcement

### Why the meeting (not the agenda item)

The spec's Motion Submission requirement scopes submission rules to "the governing
body's rules" for a meeting ("the motion MUST appear on the agenda for
consideration") — motions are submitted TO a meeting before agenda placement, so the
deadline lives on the `meeting` schema (`submissionDeadline`, date-time, additive).

### Enforcement point

Motions/amendments are created straight through the OpenRegister object API (the UI
uses the shared object store), so a decidesk controller cannot intercept creation.
OpenRegister dispatches `ObjectCreatingEvent` (StoppableEventInterface) before the
insert and converts a stopped event into `HookStoppedException` → HTTP 422. The new
`SubmissionDeadlineListener`:

1. Ignores everything except `ObjectCreatingEvent` for schema slug
   `motion` / `amendment` (slug resolution copied from `MeetingFolderListener`).
2. Resolves the meeting: motion → flat `meeting` property or `relations` entry;
   amendment → `parentMotion` → motion → meeting.
3. No meeting or no `submissionDeadline` → allow (deadlines are opt-in; the gate is a
   validation rule, not an auth guard, so absence of configuration fails open by
   design — chair/role checks elsewhere remain fail-closed).
4. Deadline in the past → `setErrors(['message' => …])` + `stopPropagation()` with the
   spec message: "The submission deadline for this meeting has passed; new motions and
   amendments can no longer be submitted."
5. Infrastructure errors during lookups log a warning and allow (never break the OR
   write path for unrelated schemas).

Registered in `Application.php` next to the existing OR listeners. Unit tests use a
new `tests/Stubs/Event/ObjectCreatingEvent.php` stub (existing stub pattern).

## Test strategy

- PHPUnit: ordering enforcement matrix (motion blocked / allowed, amendment in/out of
  order, unknown subjectType), co-signer threshold (disabled / met / shortfall message
  / amendment edge unaffected), `setAmendmentVotingOrder` validation + persistence,
  deadline listener (past / future / none / other schema / lookup failure).
- Vitest: diff helper edge cases — both empty, identical, full replace, unicode,
  prefix/suffix trim, magnitude, suggestion order.
- Playwright: diff tab renders on amendment detail, order tab on motion detail
  (defensive skips when no seed objects).
- Newman: `decidesk-motion-amendment.postman_collection.json` (decidesk-lifecycle auth
  model): order endpoint 401/200, motion round blocked 400, out-of-order amendment
  400, in-order 201, late submission 422, co-signer threshold 400 via settings toggle.
