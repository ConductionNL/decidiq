# Design: voting-chair-close-and-amendment-rounds

Kind: code. One read endpoint, two guard calls moved to the per-meeting form, one panel prop, one new tab component and one manifest widget. No schema change.

Read at development `4d7430ff`.

## What is there today

### The server already knows who presides

- `lib/Controller/VotingController.php:199` `close()` resolves the meeting from the round (`VotingRoundGuard::resolveMeetingIdFromVotingRound()`, `lib/Service/VotingRoundGuard.php:196`) and then calls `requireChairOrSecretary(meetingId)` (`:83`). The per-meeting check is `ParticipantResolver::hasRole()` (`lib/Service/ParticipantResolver.php:261`), which matches the caller's Nextcloud uid against the meeting's participants and their `role`.
- When no meeting resolves, `requireRoles()` (`VotingRoundGuard.php:130`) falls back to `isGloballyAuthorized()` (`:173`): members of the `chair_group` app setting, or Nextcloud admins when that setting is empty.
- `open()` (`VotingController.php:100`) uses the per-meeting check with the `meetingId` from the request body.
- `publish()` (`:246`) and `tally()` (`:327`) call `requireChairOrSecretary()` with no meeting, so they only ever run the global fallback. A meeting's secretary who is not an admin is refused by both.
- `lib/Service/MeetingRoleGate.php` already answers "chair or secretary of this meeting, or admin" for `MeetingController`. It is the same question with a slightly different fallback (admin only, no `chair_group`).

### The page asks a different question

- `src/components/VotingRoundPanel.vue:549` `isChairOrSecretary()` returns `this.settingsStore.isAdmin === true` (`src/store/modules/settings.js:33`).
- That one computed gates the close button (`:296` to `:302`), the full live tally (`:230`), the tie-break controls (`:371`, `:400`) and the publish control (`:418`).
- The open button (`:22` to `:35`) is gated on the subject lifecycle being `deliberating` and a linked meeting, not on the role at all. A member who is not the chair sees it and gets a 403.
- The panel's props are `motionId`, `motionLifecycle` and `meetingId` (`:479` to `:483`). `openRound()` (`:720` onwards) posts `motionId` and `meetingId` and never `subjectType`, so every round it opens is a motion round.

### Amendments

- The server side is built. `VotingOpenRequestParser` accepts `subjectType` `motion` or `amendment` (`lib/Service/VotingOpenRequestParser.php:58`, `:93`). `VotingRoundOpener::openVotingRound()` enforces the parliamentary order (`lib/Service/VotingRoundOpener.php:214`, `AmendmentOrderService::assertOrdering()` at `lib/Service/AmendmentOrderService.php:115`). `VotingRoundPreflight::buildRoundPayload()` relates the round to the subject under the subject's schema name (`lib/Service/VotingRoundPreflight.php:244`). `VotingRoundGuard::resolveMotionId()` (`VotingRoundGuard.php:255`) resolves an amendment round's meeting through the amendment's `amends` link to its parent motion.
- The page side is missing. `AmendmentDetail` (`src/manifest.json:964`) has three widgets, `amend-diff`, `amend-data` and `amend-parent`, and its `slots` map (`:993` to `:996`) wires only the first and last. No widget mounts `VotingRoundPanel`.
- `MotionVotingRoundTab` (`src/components/tabs/MotionVotingRoundTab.vue`, registered in `src/registry.js:66` and `:233`, wired at `src/manifest.json:911` and `:924`) resolves the motion id from the route because CnDetailPage does not pass `objectId` to a custom widget, then reads the motion's `lifecycle` and `meeting`. The amendment tab needs the same trick plus one hop to the parent motion for the meeting.

## Decisions

### D1. The page asks, the server decides

A new read, `voting#permissions`, `GET /api/meetings/{meetingId}/voting-permissions`, `#[NoAdminRequired]`. It returns four booleans for the signed-in user: `canOpen`, `canClose`, `canEnterTally` and `canCastChairVote`. The first three come from `VotingRoundGuard::requireChairOrSecretary(meetingId)`, the last from `requireChair(meetingId)`. A `null` guard result is `true`.

The response is computed from the guard, never from a second copy of the rule. That is the whole point: the bug exists because the page had its own rule.

The panel fetches it once per meeting in `fetchCurrentRound()` and keeps it in `data`. `isChairOrSecretary` becomes `permissions.canClose`, and the chair casting control reads `canCastChairVote`. The open button additionally requires `canOpen`.

An unknown or missing meeting returns all four `false` rather than a 404, because the panel asks before it knows whether a meeting is linked.

### D2. Tally and publish check the meeting

`tally()` and `publish()` call `resolveMeetingIdFromVotingRound($id)` first and pass the result to `requireChairOrSecretary()`, exactly as `close()` does at `VotingController.php:199` to `:205`. The global fallback still applies when no meeting resolves.

### D3. One panel, a subject type prop

`VotingRoundPanel` gains `subjectType` (`motion` by default, or `amendment`) and sends it in the open request body. `motionId` keeps its name to keep the diff small; its doc comment says it carries the amendment id on an amendment round, which is how the server names it too (`VotingOpenRequestHandler.php:67`).

### D4. An amendment voting round widget

A new `AmendmentVotingRoundTab.vue` in `src/components/tabs/`, registered in `src/registry.js`, wired on `AmendmentDetail` as widget `amend-voting-round` with a `slots` entry `widget-amend-voting-round`. It reads the amendment id from the route, the amendment's `lifecycle`, and the parent motion through `amends`, then the parent's `meeting`. It mounts the panel with `subjectType="amendment"`.

The motion page keeps its tab. The parent motion's `MotionAmendmentOrderTab` is unchanged: it sets the order, and this widget is where each amendment in that order is voted.

## Declarative or imperative

Nothing here is lifecycle, aggregation, calculation, notification, relation or widget data that `x-openregister-*` could declare. The change is an authorization answer and page wiring. The authorization answer stays in PHP under the ADR-031 exception for guards, and it reuses the existing guard rather than adding one. The widget is a manifest entry (ADR-024) around a custom component, the same pattern as `motion-voting-round`.

## Seed data

No schema changes, so no new seed objects. The e2e test needs one seeded meeting whose chair is a non-admin user. The existing governance fixture (`tests/e2e/workflows/governance-fixture.ts`) is the place to add that participant.

## Risks

- `ParticipantResolver::hasRole()` walks every participant of the meeting. The panel calls the permissions read once per mount, not per poll, so the cost does not grow with the poll interval.
- The open button disappears for members who could never use it. That is the intended change, and the Playwright spec asserts it.
