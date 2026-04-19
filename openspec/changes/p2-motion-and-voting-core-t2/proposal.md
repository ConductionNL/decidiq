## Why

The first motion-and-voting tier (p2-motion-and-voting) delivered the core workflow: submitting motions, collecting co-signatures, managing amendments, opening and closing voting rounds, casting and tallying votes, and publishing results to the ORI API. That foundation is now in production across gemeenteraden, waterschappen, and corporate boards. Market intelligence shows five high-demand capabilities that were left out of T1 because they depend on a working vote ledger to be meaningful: Real-Time Vote Tabulation (demand 288), Individual Member Voting Behavior Tracking (demand 253), Roll-Call Vote Publication with simultaneous anonymisation (demand 206), Live Voting Projection preselection (demand 203), and Default Voting Group Presets (demand 197). Two further capabilities that directly extend T1 objects have accumulated enough validated demand to warrant inclusion: Motion Forwarding Permission Controls for organisation admins (demand 89), and Amendment Diff Visualisation — inline text comparison of original versus amended passage (demand 68/44 dual signal). All seven capabilities operate exclusively on the `Motion`, `Amendment`, `Vote`, and `VotingRound` entities already committed in ADR-000 and require zero schema changes.

## What Changes

- **New**: Real-time vote tabulation visible to all participants (demand 288) — live tally panel on `VotingRoundPanel.vue` refreshes every 3 seconds; chair sees per-member tally; member sees aggregate count only; secret-ballot rounds suppress individual attribution until round close
- **New**: Individual member voting behaviour tracking (demand 253) — `VotingBehaviourService` aggregates per-Participant vote history (for/against/abstain counts, participation rate, proxy usage) across all closed VotingRounds for a GovernanceBody; exposed via `GET /api/voting-behaviour/{participantId}` and displayed in a new `MemberVotingHistoryView.vue`
- **New**: Roll-call vote publication with simultaneous end, publish, and anonymise (demand 206) — `VotingService::closeVotingRound()` extended with an `anonymise` flag; when set, individual `Vote.value` fields are nulled before the result is pushed to ORI; the chair performs end + publish + anonymise in a single atomic action from the close dialog
- **New**: Live voting projection preselection (demand 203) — a dedicated `/projection/:votingRoundId` fullscreen route renders `ProjectionView.vue`; when only one vote option is currently leading, that option tile is visually preselected for the next voter; shown on an in-room projector screen without exposing individual vote identity to the audience
- **New**: Default voting group presets (demand 197) — admin settings page gains a "Stemgroepen" section; presets define named groups of Participants (e.g., "Voltallige raad", "Commissie AZ") with stored membership lists; when a chair opens a VotingRound they can select a preset to pre-populate eligible voters
- **New**: Motion forwarding permission controls for organisation admins (demand 89) — admin settings gains a "Doorzending" section; toggles control which roles may forward a motion to another GovernanceBody and whether forwarding requires approval by the receiving body's chair
- **New**: Amendment diff visualisation (demand 68 + 44) — `AmendmentDetail.vue` gains a "Vergelijken" tab showing a character-level diff of the parent motion text versus the amended text using Myers algorithm; change recommendations (additions, deletions) are highlighted with WCAG-accessible colour tokens; sorted lists in the diff render correctly

## Capabilities

### New Capabilities

- `real-time-vote-tabulation`: Live vote count refreshed every 3 seconds during an open VotingRound; chair sees per-member breakdown, members see aggregate; secret ballots suppress attribution until close
- `member-voting-behaviour-tracking`: Per-Participant vote statistics (participation rate, for/against/abstain distribution, proxy usage) aggregated by `VotingBehaviourService` and surfaced in `MemberVotingHistoryView.vue`
- `roll-call-publication`: Atomic close + publish + anonymise action on `VotingRoundPanel.vue`; `VotingService::closeVotingRound()` nulls individual Vote values when `anonymise: true` before ORI push
- `live-voting-projection`: Fullscreen `ProjectionView.vue` at `/projection/:votingRoundId`; leading-option preselection for in-room display; no individual identity shown
- `voting-group-presets`: Named Participant groups stored as app config; selectable when opening a VotingRound to pre-populate the eligible voter list
- `motion-forwarding-controls`: Admin toggle controlling which roles can forward motions and whether receiving-body approval is required; enforced in `MotionService::forwardMotion()`
- `amendment-diff`: Myers character-level diff rendered in `AmendmentDetail.vue` "Vergelijken" tab; handles sorted-list reordering correctly; WCAG AA colour tokens

### Modified Capabilities

- `voting-round-management` *(from p2-motion-and-voting)*: `VotingService::closeVotingRound()` gains `anonymise` parameter; `VotingController` close endpoint gains optional `anonymise: boolean` body field
- `amendment-workflow` *(from p2-motion-and-voting)*: `AmendmentDetail.vue` gains "Vergelijken" tab — no backend changes required

## Impact

- Uses `Motion`, `Amendment`, `Vote`, and `VotingRound` entities from ADR-000 — no schema changes required
- Voting group presets stored in `IAppConfig` as JSON-encoded arrays — no OpenRegister entity needed
- Forwarding permission flags stored in `IAppConfig` — two boolean keys: `motion_forwarding_roles` (array), `motion_forwarding_requires_approval` (boolean)
- Amendment diff computed entirely in the frontend using a pure JS Myers diff implementation — no new PHP endpoint
- Roll-call anonymisation: `Vote.value` set to `null` via `ObjectService.saveObject()` for each Vote in the closed round when `anonymise: true`; `VotingRound.isSecret` is NOT changed — anonymisation is a post-close operation, not a secret-ballot setting
- Projection view is a new route registered in `appinfo/routes.php` as a public page (`#[PublicPage]`) so it can be displayed on a separate screen without Nextcloud login
- Downstream: p2-minutes-and-decisions reads VotingRound results — anonymised rounds show `null` vote values in minutes; the minutes spec already allows null
- No new PHP controllers beyond `VotingBehaviourController`; all other changes extend existing services and frontend components
