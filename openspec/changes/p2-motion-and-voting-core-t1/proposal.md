## Why

Governance bodies — municipal councils, water boards, provincial states, corporate boards, and associations — depend on formal motions and votes to reach binding decisions. Today the top pain points are clear from market demand: proxy voting is the single highest-demand capability in the entire Decidesk feature set (demand: 1662), because absent members routinely lose their vote when no digital delegation mechanism exists. Amendment workflows (demand: 1046) remain paper-based, fiscal impact reviews are disconnected from the formal record (demand: 771), and amendment notifications are missed (demand: 758). At the same time, decentralised voting security (demand: 611) and full voting-result transparency (demand: 467) are hard requirements for any governance platform seeking public trust.

This change delivers the Core T1 motion-and-voting foundation: the capabilities that every governance body needs from day one of a Decidesk deployment. It builds on the `Motion`, `Amendment`, `Vote`, and `VotingRound` entities defined in ADR-000, adding the service and UI layers that turn raw data structures into a complete digital deliberation workflow — from motion submission through proxy delegation, amendment debate with fiscal impact review, quorum-checked voting, and transparent result publication.

## What Changes

- **New**: Motion index and detail pages with lifecycle workflow (`submitted` → `debating` → `voting` → `adopted` / `rejected` / `withdrawn`), visualised via `CnTimelineStages`; lifecycle transitions enforced by role (chair/secretary advance, proposer may withdraw before voting)
- **New**: Digital co-signatory collection — proposer invites Participants via Nextcloud notifications; each confirmation appends to `coSigners` array on the Motion; threshold display shows required vs. collected signatures
- **New**: Amendment workflow — Participants submit Amendments against existing Motions; conflict detection alerts the secretary when two amendments modify the same text passage; amendments follow their own lifecycle parallel to the parent motion
- **New**: Fiscal impact review on amendments — a structured budget-impact note (budget line, amount delta, rationale) can be attached to any amendment-type Motion; the note is visible to financial controllers and stored in the Motion's built-in notes field
- **New**: Amendment notification dispatch — when an Amendment is submitted or its lifecycle changes, relevant Participants (proposer, chair, secretary) receive Nextcloud notifications via `NotificationService`
- **New**: Proxy voting — a Participant can delegate their voting right to another active Participant for a specific VotingRound; proxy votes are flagged `isProxy: true`; the system enforces one proxy per Participant per round; proxy may be revoked before the round opens
- **New**: Voting round management — chair opens and closes VotingRounds per Motion or Amendment; configures voting method (for-against-abstain, ranked-choice, weighted, show-of-hands); quorum is verified automatically before a round can be opened
- **New**: Vote casting — Participants cast votes (voor / tegen / onthouding) within an open VotingRound via the UI; remote and mobile participants receive an email invitation and can reply with their vote; real-time tally visible to chair/secretary during the round
- **New**: Voting schedule configuration — a VotingRound can be given a deadline (`closedAt`) when opened; a calendar event is created via `CalendarEventService`; a deadline notification is sent to all Participants when the round is close to expiring
- **New**: Voting results transparency — after VotingRound close, the full result (votesFor / votesAgainst / votesAbstain / result) is displayed, including per-Participant vote breakdown (for non-secret rounds) and per-faction/party aggregation; results are publishable to the ORI API

## Capabilities

### New Capabilities

- `motion-management`: Full CRUD for Motion objects with lifecycle tracking, `CnTimelineStages` visualisation, role-enforced transitions, and digital co-signatory collection; budget-impact note attachment for amendment-type motions
- `amendment-workflow`: Submit Amendment objects against Motions; conflict detection with secretary notification; amendment lifecycle parallel to parent motion; amendment notifications to relevant Participants on state change
- `proxy-voting`: Delegate and revoke voting rights per VotingRound; enforce one-proxy-per-round rule; flag proxy votes on Vote objects; display proxy status to delegate in the voting UI
- `voting-round-management`: Open and close VotingRounds per Motion or Amendment; configure voting method and secrecy; enforce quorum before opening; schedule voting deadline with calendar event
- `vote-casting`: Cast for/against/abstain votes in open VotingRounds via UI; support email-reply voting for remote Participants; show-of-hands data entry for in-person rounds; real-time tally for chair/secretary
- `voting-result-publication`: Automatic tally on round close; per-party vote aggregation display; majority threshold calculation; optional ORI API publication of results; full audit trail via `ActivityService`

### Modified Capabilities

- `agenda-item-crud` *(from p1-crud-operations)*: Extend `AgendaItemDetail.vue` to show linked Motions panel with count, lifecycle badges, and "Motie indienen" action for `decision`-type items

## Impact

- Uses `Motion`, `Amendment`, `Vote`, and `VotingRound` entities from ADR-000 — no schema changes required
- Motion lifecycle maps to OpenRegister built-in `status` field: `submitted`, `debating`, `voting`, `adopted`, `rejected`, `withdrawn`
- Amendment lifecycle maps to the same `status` field: `submitted`, `debating`, `voting`, `adopted`, `rejected`
- Budget impact data stored in built-in Motion notes (structured JSON with `title: "Budget impact"`) — no schema extension needed
- Proxy delegation stored as OpenRegister relation Vote → Participant (`delegator`) — uses built-in relation mechanism
- Calendar voting deadline events created via `CalendarEventService` — no custom calendar controller
- ORI publication calls external HTTP endpoint via `OriPublicationService` — the only external integration in this spec
- Downstream: p2-minutes-and-decisions reads Motion and VotingRound results to generate Decision objects — no breaking changes
- Adds `MotionService`, `VotingService`, `OriPublicationService`, `MailReplyHandler` to backend
- Adds `MotionIndex.vue`, `MotionDetail.vue`, `AmendmentDetail.vue`, `VotingRoundPanel.vue`, `VoteCard.vue` to frontend
- Extends Dashboard with 2 new `CnStatsBlock` KPI cards: active voting rounds and open motions count
