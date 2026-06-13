---
kind: code
---

# Proposal: Motion & Amendment v1 — Diff View, Chair-Controlled Voting Order, Co-Signer Threshold, Submission Deadlines

## Problem

The seeded spec `openspec/specs/motion-amendment/spec.md` (status: partial) lists four
residual gaps after the 2026-06-12 audit:

1. **Visual amendment diff view** — the spec requires amendments to "clearly show what
   text is being added, removed, or modified (diff view)" with "additions in green,
   removals in red". Today the amendment detail page shows only the raw amendment text;
   conflict detection exists (`MotionService::detectConflicts()`) but no visual diff.
   The amendment schema also has no field carrying the proposed replacement text, so a
   diff against the parent motion text cannot even be computed.
2. **Chair-controlled amendment voting order** — the spec requires that amendments are
   voted before the main motion, "most far-reaching first", with the chair able to set
   the order. Today `VotingService::openVotingRound()` only opens rounds on motions,
   never enforces amendment-first sequencing, and no order can be configured.
3. **Co-signer minimum threshold** — the spec scenario "Reject motion below minimum
   co-signer threshold" requires a configurable minimum number of co-signers before a
   motion can proceed. Today `MotionService::transitionLifecycle()` allows
   `submitted → debating` regardless of co-signer count.
4. **Submission deadline enforcement** — the spec requires motions to "follow the
   governing body's rules for submission (e.g., minimum co-signers, submission
   deadline)". No deadline field exists on the meeting and nothing rejects late
   submissions server-side.

## What Changes

- **MODIFIED** `lib/Settings/decidesk_register.json` — ADDITIVE fields only:
  - `amendment`: `proposedText` (string — the full proposed replacement text for the
    affected motion passage, diffed against the parent motion text), `votingOrder`
    (integer — chair-assigned voting position, lower = voted earlier).
  - `meeting`: `submissionDeadline` (date-time — motions/amendments for this meeting
    must be submitted before this moment; empty = no deadline).
- **MODIFIED** `lib/Service/MotionService.php`:
  - `transitionLifecycle()` enforces the configurable co-signer minimum (app config
    `decidesk`/`motion_min_cosigners`, default 0 = disabled) on the
    `submitted → debating` edge for motions; the rejection message names the shortfall.
  - New `getAmendmentsForMotion()` — canonical amendment resolver honouring BOTH link
    shapes (the flat `parentMotion` property the UI writes and structured relations);
    `detectConflicts()` is refactored onto it (fixing the pre-existing gap where
    property-linked amendments were invisible to conflict detection).
  - New `setAmendmentVotingOrder()` — persists the chair-chosen order as `votingOrder`
    1..N on each amendment of a motion.
- **MODIFIED** `lib/Service/VotingService.php`:
  - `openVotingRound()` accepts a `subjectType` (`motion` | `amendment`, fail closed).
    Opening a round on a MOTION is rejected while any of its amendments is still in
    lifecycle `submitted`/`debating`/`voting` (amendments are voted first). Opening a
    round on an AMENDMENT out of the configured order is rejected (the next undecided
    amendment by `votingOrder` must be voted first). Amendment rounds relate to the
    `amendment` schema and transition the amendment lifecycle.
  - `closeVotingRound()` resolves amendment rounds: transitions the amendment to
    `adopted`/`rejected` and, on adoption, incorporates the amendment into the parent
    motion text via the existing `applyAmendment()` (spec: "the final motion text MUST
    incorporate all adopted amendments").
  - `castVote()` meeting-membership resolution (#300) now also walks
    round → amendment → parent motion → meeting for amendment rounds.
- **ADDED** `lib/Listener/SubmissionDeadlineListener.php` — OpenRegister
  `ObjectCreatingEvent` pre-save hook: motion/amendment creations whose linked meeting
  has a `submissionDeadline` in the past are rejected server-side (propagation stopped,
  HTTP 422 from the OR object API) with the spec message.
- **MODIFIED** `lib/Controller/MotionController.php` + `appinfo/routes.php`:
  - New chair-ONLY endpoint `POST /api/motions/{id}/amendment-order` (per-meeting chair
    role via ParticipantResolver, fail closed; global chair_group/admin fallback).
  - `resolveMeetingIdFromMotion()` also honours the flat `meeting` property (pre-existing
    gap: only structured relations were read, so property-linked motions always fell
    back to the global guard).
- **MODIFIED** `lib/Controller/VotingController.php` — `open()` accepts + validates
  `subjectType`; meeting resolution for the close guard handles amendment rounds.
- **MODIFIED** `lib/Service/SettingsService.php` — `motion_min_cosigners` joins
  CONFIG_KEYS so admins can configure the threshold via the existing settings endpoint.
- **ADDED** `src/utils/textDiff.js` — pure LCS-based word-level diff (no new npm
  dependency): `diffWords()`, `changeMagnitude()`, `suggestVotingOrder()` (most
  far-reaching first).
- **ADDED** `src/components/AmendmentDiffView.vue` — visual diff (green additions /
  red removals via NC CSS variables) + `src/components/tabs/AmendmentDiffTab.vue`
  surfacing it on the AmendmentDetail page (registry pattern, ADR-036).
- **ADDED** `src/components/tabs/MotionAmendmentOrderTab.vue` — chair ordering UI
  (up/down reordering, scope-based suggestion, save via the chair-only endpoint) on the
  MotionDetail page.
- **ADDED** tests: PHPUnit (ordering enforcement matrix, co-signer threshold, deadline
  listener, order persistence), Vitest (`textDiff.spec.js` — empty/identical/
  full-replace/unicode), Playwright UI additions, Newman collection
  `tests/integration/decidesk-motion-amendment.postman_collection.json` wired into
  `tests/newman/run-all.sh`.

## Impact

- Backwards compatible: motions without amendments open voting rounds exactly as
  before (`subjectType` defaults to `motion`); `motion_min_cosigners` defaults to 0
  (disabled); meetings without `submissionDeadline` accept submissions at any time.
- All schema changes are additive; no existing fields are touched.
- `openVotingRound()` becomes stricter for motions that DO have pending amendments —
  this is the spec's parliamentary rule, not a regression.
- The OR object API returns 422 for late submissions (HookStoppedException contract).
