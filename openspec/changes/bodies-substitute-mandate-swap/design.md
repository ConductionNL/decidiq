# Design: bodies-substitute-mandate-swap

Kind: code. One new schema and one participant property in a register fragment, one read and two write endpoints, one resolver consulted by three existing voting paths, and one live meeting panel.

Read at development `4d7430ff`.

## What is there today

- A meeting's participants are the participants of the meeting's governance body: `ParticipantResolver::resolveMeetingParticipants()` (`lib/Service/ParticipantResolver.php:141`) resolves the body from the meeting and returns every participant related to it. There is no per-meeting attendance object; `Participant.attendanceStatus` is one value "for the current or most recent meeting" (`lib/Settings/decidesk_register.json:1149`, `Participant`).
- `Participant` carries `role`, `party`, `votingWeight`, `leftAt` and `nextcloudUserId`, and no seat.
- Voting reads participants in three places:
  - who may cast: `VoteCastGuard::assertMeetingMembership()` (`lib/Service/VoteCastGuard.php:132`) against the meeting's participants;
  - quorum: `VotingRoundOpener::checkQuorum()` (`lib/Service/VotingRoundOpener.php:106`) counts participants without `leftAt`;
  - voting groups: `VotingRoundPreflight::splitPresetParticipants()` (`lib/Service/VotingRoundPreflight.php:194`) keeps preset ids that are meeting participants and excludes the rest.
- The only substitute concepts are the approval route's `askSubstituteAfter` (`lib/Settings/register.d/81-resolve-a-manager-and-declare-silence.json:57`) and `PositionHold.holdType` (`lib/Settings/register.d/70-configurable-types.json:514`), neither of which touches a meeting.
- The live meeting page (`src/views/LiveMeeting.vue`, route `/meetings/:id/live`, `src/manifest.json:720`) filters participants client side on `relations.meeting` (`:330` to `:336`), which its own comment at `:566` says the schema does not declare. `MeetingParticipantsTab` writes a `meetings` array onto participants (`src/components/tabs/MeetingParticipantsTab.vue:224`, `:240`) that the participant schema does not declare either.
- Who presides over a meeting: `MeetingRoleGate::isChairOrSecretary()` (`lib/Service/MeetingRoleGate.php`), admin or the meeting's chair or secretary.

## Decisions

### D1. The substitution is a record, not an edit of the participants

`Participant` is body wide. Rewriting the member's and the substitute's participant rows would change them for every later meeting. So a swap writes a new `mandate-substitution` object and leaves both participants as they are. The voting paths ask the record who holds the seat in this meeting.

`mandate-substitution` properties: `meeting` (uuid, required), `outgoingParticipant` (uuid, required), `incomingParticipant` (uuid, required), `seatNumber` (integer), `party` (string), `role` (string), `votingWeight` (number), `startedAt` (date-time, required), `endedAt` (date-time), `recordedBy` (string), `reason` (string). Seat, party, role and weight are copied from the outgoing participant when the swap starts.

`Participant.seatNumber`: integer, optional, the member's standing seat.

### D2. One resolver answers "who holds this seat now"

`lib/Service/SubstitutionResolver.php` loads the active substitutions of a meeting (no `endedAt`) once per request and answers three questions:

- `isSubstitutedOut(meetingId, participantId)`;
- `seatHolderFor(meetingId, participantId)`, the incoming participant or the member themselves;
- `activeSubstitutes(meetingId)`, the incoming participants.

The three voting paths call it:

- `assertMeetingMembership()`: refuses a member who is substituted out, and accepts an active substitute even when they are not a voting member of the body.
- `checkQuorum()`: a substituted-out member is not counted and their substitute is, so the seat counts once.
- `splitPresetParticipants()`: a preset id of a substituted-out member resolves to the substitute.

### D3. Starting and ending a swap

- `GET /api/meetings/{meetingId}/seats`, route `meeting#seats`: the meeting's participants with role, party, seat and weight, the active substitutions, and `canSubstitute` for the caller from `MeetingRoleGate`.
- `POST /api/meetings/{meetingId}/substitutions`, route `meeting#substitute`, body `{ "outgoingParticipantId", "incomingParticipantId", "reason" }`.
- `POST /api/meetings/{meetingId}/substitutions/{id}/end`, route `meeting#endSubstitution`.

All three are `#[NoAdminRequired]`; the two writes require `MeetingRoleGate::isChairOrSecretary()`. The start refuses when:

- a voting round of the meeting is open;
- the outgoing participant's role is not `member`, or they are already substituted out;
- the incoming participant is not a participant of the meeting's body, has a voting role (`chair`, `vice-chair`, `secretary`, `member`), or is already an active substitute;
- the two are the same participant.

The end refuses while a voting round of the meeting is open. An ended substitution stays on record.

### D4. The seats panel

`src/components/liveMeeting/SeatsPanel.vue` on the live meeting page, fed by the seats read (not by the client-side participant filter). It lists seats in seat number order: seat, name, party, and "substitute for Name" on a substituted seat. For a presiding user each voting member row has "Swap with substitute", which opens `src/modals/MandateSwapModal.vue` to pick the incoming participant and a reason, and a substituted seat has "End substitution". The same list feeds the roll-call entry sheet of `voting-named-paper-vote-entry` when both are built, so a substitute is recorded in the seat they took.

## Declarative or imperative

- Declared in a new fragment `lib/Settings/register.d/NN-substitute-mandate-swap.json` (next free number at build time): the `mandate-substitution` schema, its relations to `meeting` and `participant` as `x-openregister-relations`, a calculation `isActive` (`endedAt` empty), and `Participant.seatNumber`.
- Imperative: the swap's refusals and the resolver. They are authorization and eligibility rules consulted by the existing voting guards, which ADR-031 keeps in PHP, and they add no parallel store.

## Seed data

In `lib/Settings/profiles/municipality.json` (`x-openregister.seedData.objects`):

- a `meeting` `auditcommissie-2026-03-04` of body `auditcommissie-provincie-nh`;
- participants `pt-bos-auditcommissie` (member, party "VVD", seat 3), `pt-kaya-auditcommissie` (member, party "D66", seat 4) and `pt-de-wit-auditcommissie` (observer, party "VVD", plaatsvervangend commissielid);
- a `mandate-substitution` in that meeting: outgoing `pt-bos-auditcommissie`, incoming `pt-de-wit-auditcommissie`, seat 3, party "VVD", role `member`, weight 1, started 20:15, ended 21:40, reason "Mr Bos left for another appointment".

## Risks

- The resolver adds one query to cast, open and quorum. It is one `findAll` on `mandate-substitution` filtered by meeting, loaded once per request.
- A member substituted out who casts from another tab gets a refusal that names the substitution, not a generic 403, so a clerk can explain it.
