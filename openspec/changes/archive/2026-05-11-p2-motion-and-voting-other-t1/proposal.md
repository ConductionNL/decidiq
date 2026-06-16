## Why

Governance bodies — municipal councils, water boards, corporate boards, and associations — require advanced voting capabilities beyond the core for/against/abstain workflow delivered in p2-motion-and-voting. The market analysis surfaces five high-demand "other" capabilities that remain unaddressed: secret ballot for board elections (demand 126), motion status management with custom metadata configuration (demand 119), voter and independent vote review and recount (demand 58), real-time ballot distribution (demand 40), and preferential ballot / ranked-choice voting (demand 26). Without these, boards cannot conduct legally valid anonymous elections, water boards cannot configure their domain-specific procedural rules, and contested vote results have no formal challenge workflow. All five capabilities build on the `Motion`, `VotingRound`, `Vote`, and `GovernanceBody` entities from ADR-000 and the foundational voting workflow from p2-motion-and-voting — no new entities are required.

## What Changes

- **New**: Secret ballot enforcement — when `VotingRound.isSecret` is `true`, individual Vote records are masked at the API level so that no user (including chair and secretary) can retrieve which participant voted which way; only aggregate counts (`votesFor`, `votesAgainst`, `votesAbstain`) are exposed; the UI shows "Uw stem is anoniem geregistreerd" and hides the per-participant vote table; enforced by a new `SecretBallotGuard` on `VotingController`
- **New**: Motion status management with custom metadata configuration — admin users can configure per-`GovernanceBody` which `motionType` values are permitted and which lifecycle transitions are allowed, stored as JSON in the existing `GovernanceBody.workflowTemplate` field; a new admin settings section exposes a visual editor with add/remove motion types and transition rules
- **New**: Vote review and independent recount — after a `VotingRound` closes, the chair or secretary can trigger a recount via `POST /api/voting-rounds/{id}/recount`; `VotingService::recount()` re-tallies all `Vote` objects and compares to the stored result; a discrepancy sets `VotingRound.result` to `"disputed"` and creates a note with the comparison; an auditor with read-only access can view vote records (non-secret rounds) per Story 4
- **New**: Real-time ballot distribution — when a `VotingRound` is opened, the backend calls `NotificationService` to push a Nextcloud notification containing a deep-link to every eligible Participant (active Membership, not yet voted); the `VotingRoundPanel` shows a live "Uitgenodigd: X / Gestemd: Y" tracker updated every 5 seconds; chair/secretary can trigger a repeat notification for participants who have not yet voted
- **New**: Preferential ballot — a new `votingMethod: "ranked-choice"` option for `VotingRound`; participants rank candidates or options by order of preference; `Vote.value` stores a JSON-encoded ordered list; `VotingService::tallyResults()` applies Borda count to determine the winner; the results view displays a ranking table with point totals

## Capabilities

### New Capabilities

- `secret-ballot`: Backend API masking of individual Vote data for secret rounds; `SecretBallotGuard` enforces anonymity at controller layer; UI shows only aggregate totals and "anoniem" confirmation; audit trail records vote was cast without revealing direction
- `motion-status-management`: Per-GovernanceBody configuration of permitted motion types and lifecycle transition rules via `GovernanceBody.workflowTemplate` JSON; admin settings section with visual editor; `MotionService::transitionLifecycle()` validates against the configured rules at runtime
- `vote-review-and-recount`: Post-close recount endpoint; re-tally via `VotingService::recount()`; discrepancy detection and `"disputed"` result state; read-only auditor access to Vote records on non-secret rounds
- `real-time-ballot-distribution`: Automatic Nextcloud notification to all eligible Participants on `VotingRound` open; live distribution tracker in `VotingRoundPanel`; repeat-notify action for non-voters
- `preferential-ballot`: `votingMethod: "ranked-choice"` with Borda count tallying; rank-entry UI for vote casting; ranked results table

### Modified Capabilities

- `voting-round-management` *(from p2-motion-and-voting)*: Extend `VotingService::openVotingRound()` to trigger ballot distribution notifications; extend `VotingService::tallyResults()` to handle ranked-choice method; extend `VotingController` with `SecretBallotGuard` and recount endpoint
- `vote-casting` *(from p2-motion-and-voting)*: Extend `VotingRoundPanel` to show ballot distribution tracker and ranked-choice entry UI; enforce secret-ballot UI masking

## Impact

- Uses `Motion`, `VotingRound`, `Vote`, and `GovernanceBody` entities from ADR-000 — no schema additions required
- `VotingRound.isSecret` (boolean, ADR-000) drives the secret ballot path — field already exists, no migration needed
- `GovernanceBody.workflowTemplate` (string, ADR-000) stores JSON config — field already exists, no migration needed
- `Vote.value` (string, ADR-000) stores JSON-encoded ranked list for preferential ballots — valid use of the existing field
- Recount comparison stored as a structured note on `VotingRound` (`title: "Hertelverzoek"`) — no new entity needed
- `SecretBallotGuard` is a lightweight PHP class injected into `VotingController`; does not touch `VotingService` or `ObjectService`
- Ballot distribution notifications use platform-provided `NotificationService` — no custom notification controller
- `VotingService::tallyResults()` extended with a ranked-choice branch; existing for/against/abstain path is unchanged
- Downstream specs (p2-minutes-and-decisions) read `VotingRound.result` — `"disputed"` is a new valid value that callers must handle gracefully
