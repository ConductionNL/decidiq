## Why

Governance bodies — municipal councils, water boards, corporate boards, and associations — make binding decisions through formal motions and votes. Without digital motion and voting management, council members submit motions on paper, clerks track amendments by hand, and vote tallies are counted manually with no reliable audit trail. This change addresses the highest-demand motion and voting capabilities: Budget amendment motions (demand 2025), Proxy voting (demand 1242), and the core motion/voting cluster (Amendment workflow, Quorum checking, Voting result publication, Motion status tracking, Voting round management, Vote casting and tallying — demand 140 each). All capabilities build on the `Motion`, `Amendment`, `Vote`, and `VotingRound` entities delivered as part of ADR-000 and require no schema additions.

## What Changes

- **New**: Motion management — raadsleden and board members can submit, debate, and withdraw formal motions (motie, amendement, order, procedureel) linked to an AgendaItem; Motion lifecycle tracks from `submitted` → `debating` → `voting` → `adopted` / `rejected` / `withdrawn`
- **New**: Co-signatory collection (demand Story 8) — digital co-signature request to other Participants; `coSigners` array updated when each member digitally confirms support; threshold enforcement configurable per GovernanceBody
- **New**: Budget amendment motion workflow (demand 2025) — motion proposer links a motion to specific budget lines and enters the amount delta and policy rationale; the financial controller sees computed budget impact immediately via an OpenRegister relation to the motion
- **New**: Amendment workflow (demand 140) — Participants submit amendments to existing motions; griffier is alerted when multiple amendments conflict on the same text passage (Story 9); amendments follow the same lifecycle as motions (submitted → debating → voting → adopted/rejected)
- **New**: Voting round management (demand 140) — chair opens and closes VotingRounds per motion or amendment; configures voting method (for-against-abstain, ranked-choice, weighted, show-of-hands); quorum is verified automatically before the round can be opened (demand 140)
- **New**: Vote casting and tallying (demand 140) — Participants cast their vote (for / against / abstain) within an open VotingRound; votes are collected in real-time and the result (adopted/rejected/tied/invalid) is calculated and stored automatically on round close
- **New**: Proxy voting (demand 1242) — a Participant can delegate their voting right to another active Participant for a specific VotingRound; proxy votes are flagged `isProxy: true` on the Vote object; a Participant may hold at most one proxy per round
- **New**: Email vote casting (Story 11) — for remote participation, a vote notification email is sent when a VotingRound opens; the Participant replies with "Voor", "Tegen", or "Onthouding"; the reply is parsed and the vote registered in OpenRegister; a confirmation reply is sent automatically
- **New**: Voting result publication (demand 140) — after VotingRound close, results (votesFor / votesAgainst / votesAbstain / result) are shown in the meeting UI and optionally published to the ORI API via a `POST /api/voting-rounds/{id}/publish` endpoint
- **New**: Automatic dossier folder (Story 18) — when a motion is adopted, a structured Nextcloud Files folder (via `_files` metadata) is created containing the motion text, amendments, voting results, and any attached documents

## Capabilities

### New Capabilities

- `motion-management`: Create, debate, co-sign, and withdraw Motion objects linked to an AgendaItem; track lifecycle with `CnTimelineStages`; collect digital co-signatures from Participants; support budget amendment motions with financial impact data
- `amendment-workflow`: Submit Amendment objects against existing Motions; detect text-passage conflicts between amendments and alert the griffier; vote on amendments via a dedicated VotingRound
- `voting-round-management`: Open and close VotingRounds per Motion or Amendment; configure voting method per round; enforce quorum check before opening; schedule voting deadline with calendar event (Story 12)
- `vote-casting`: Participants cast votes (for / against / abstain) in open VotingRounds via the UI or email reply; proxy votes tracked with `isProxy: true`; real-time tally visible to all during the round
- `voting-result-publication`: Automatic result calculation on round close; display per-motion vote tally and majority threshold; publish results to ORI API endpoint; complete audit trail via Nextcloud Activity

### Modified Capabilities

- `agenda-item-crud` *(from p1-crud-operations)*: Extend AgendaItemDetail to show linked Motions panel (count, lifecycle badges) and trigger "Motie indienen" action for `decision`-type items

## Impact

- Uses `Motion`, `Amendment`, `Vote`, and `VotingRound` entities from ADR-000 — no schema changes required
- Motion lifecycle uses OpenRegister built-in `status` field: `submitted`, `debating`, `voting`, `adopted`, `rejected`, `withdrawn`
- Amendment lifecycle uses the same OpenRegister `status` field: `submitted`, `debating`, `voting`, `adopted`, `rejected`
- Budget impact data stored in OpenRegister built-in `notes` on Motion (structured with `type: budget-impact`) — no extra schema property needed
- Proxy delegation stored as OpenRegister `relation` from Vote → Participant (proxy giver) — uses built-in relation mechanism
- Calendar voting deadline event created via `CalendarEventService` when a VotingRound is opened with a `closedAt` timestamp
- Dossier folder created via `FileService._files` metadata on the Motion object after adoption — no custom file controller
- ORI publication calls an external HTTP endpoint via a new `OriPublicationService` — the only true external integration in this spec
- Downstream specs (p2-minutes-and-decisions) read Motion and VotingRound results to generate Decision objects — no breaking changes
- No new PHP controllers or services beyond `MotionService`, `VotingService`, and `OriPublicationService`
