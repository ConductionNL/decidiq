## Why

Governance bodies — municipal councils, water boards, corporate supervisory boards, and associations — make binding decisions through formal motions and votes. The core motion-and-voting workflow (submission, debate, co-signing, voting, ORI publication) was delivered in p2-motion-and-voting. Market research across 851 user stories and 2,000+ tender documents now reveals the highest-demand extensions that go beyond the initial implementation.

The three most in-demand capabilities are tightly linked: **Update motion execution status and Track adopted motion execution** (demand 39 each, 11 tender mentions each) — after a council adopts a motion, the clerk and executive must track whether implementation actually happens, but today there is no digital loop closing that adoption to execution. **Motions > Nominal votes: anonymization** (demand 35, 11 tender mentions) — privacy regulations (GDPR/AVG) and governance codes increasingly require that individual vote records can be anonymised after a session closes while preserving aggregate counts for the public record. **Remote Shareholder Resolution Approval Without Meeting** (demand 33, 10 tender mentions) — Dutch corporate law (BW 2:238) and the Dutch Corporate Governance Code permit written/circular resolutions without a formal meeting; today this requires manual email chains with no audit trail. The **Quorum Calculator** (demand 27) rounds out the tranche: secretaries and chairs spend significant preparation time manually computing quorum thresholds for different attendance scenarios.

Without these capabilities, clerks track motion execution in spreadsheets, vote anonymisation is done manually (or not at all), circular resolutions are managed in email, and quorum is computed by hand before every meeting. This change closes the governance lifecycle from adoption to execution and adds the compliance and efficiency tools the market demands most.

## What Changes

- **New**: Motion execution tracking — after a Motion transitions to `adopted`, the clerk or executive can update its execution status (`execution-pending`, `executing`, `executed`) using new lifecycle stages; an execution note captures progress details; execution ActionItems are created automatically with `dueDate` set to the configured execution deadline; a "Uitvoering" panel on the Motion detail page shows execution status, linked ActionItems, and a completion flag
- **New**: Vote anonymisation — after a VotingRound is closed, the chair or secretary can trigger anonymisation; individual Vote objects have their person relation nullified and voter identity removed; the VotingRound is tagged `votes-anonymized`; aggregate counts (`votesFor`, `votesAgainst`, `votesAbstain`, `result`) are preserved for the public record; the operation is logged in the audit trail with actor and timestamp
- **New**: Quorum calculator — an interactive panel on the GovernanceBody detail page and the VotingRound creation form shows the computed quorum threshold for the configured `quorumRule`; the secretary can preview different expected-attendance scenarios before opening a round; a `GET /api/governance-bodies/{id}/quorum-preview` endpoint exposes the calculation
- **New**: Written/circular resolution approval — a new `written-resolution` value is added to the Motion `motionType` field; the clerk opens a VotingRound with `votingMethod: written-resolution` and an extended `closedAt` deadline; all active members receive a Nextcloud notification and optional email with a direct vote link; votes are cast via the standard vote-casting endpoint; result calculation and ORI publication are identical to regular VotingRounds

## Capabilities

### New Capabilities

- `motion-execution-tracking`: Transition adopted Motions through execution lifecycle states (`execution-pending` → `executing` → `executed`); attach execution notes and completion evidence; auto-create execution ActionItems with configurable deadline; surface a "Uitvoering" panel with linked ActionItems on the Motion detail page
- `vote-anonymisation`: Anonymise voter identity on closed VotingRounds via `VotingAnonymizationService`; null voter relations on Vote objects; tag VotingRound `votes-anonymized`; full audit trail entry; irreversible operation with confirmation dialog
- `quorum-calculator`: `QuorumCalculatorService` computes quorum threshold from GovernanceBody `quorumRule` and expected attendance; exposed via `GET /api/governance-bodies/{id}/quorum-preview`; embedded as `QuorumCalculatorPanel.vue` in GovernanceBody detail and VotingRound creation
- `written-resolution-approval`: Circular/written resolution workflow for remote shareholder and board approval without a formal meeting; extends VotingRound with `written-resolution` voting method; bulk notification to all active members; standard vote-casting, tallying, and ORI publication flow

### Modified Capabilities

- `voting-round-management` *(from p2-motion-and-voting)*: Extended to support `written-resolution` as a `votingMethod` value and to surface the quorum calculator in the round-creation form
- `motion-management` *(from p2-motion-and-voting)*: Extended with execution lifecycle states and the "Uitvoering" panel on Motion detail

## Impact

- No schema additions to ADR-000 entities — all new behaviour uses existing fields (`lifecycle`, `motionType`, `votingMethod`, `tags`) and built-in mechanisms (`relations`, `notes`, `tasks`, `status`)
- Extends Motion `lifecycle` enum with `execution-pending`, `executing`, `executed` (non-breaking: new allowed values)
- Extends Motion `motionType` enum with `written-resolution` (non-breaking)
- Extends VotingRound `votingMethod` enum with `written-resolution` (non-breaking)
- Vote anonymisation uses built-in OpenRegister relation deletion and `tags` on VotingRound — no schema change
- 2 new PHP service classes (`VotingAnonymizationService`, `QuorumCalculatorService`); 1 extended service class (`MotionService` gains execution methods); 1 new controller (`QuorumController`); 2 extended controllers (`MotionController`, `VotingController`)
- Downstream: p2-minutes-and-decisions can read Motion execution status to include execution summary in meeting minutes; p3-governance-bodies can display execution completion rates per GovernanceBody
- No breaking changes to existing Motion, VotingRound, or Vote API responses
