---
kind: code
depends_on: []
---

# Proposal: live-room-conference-system-link

## Summary

The council chamber's conference and voting system knows who pressed a
microphone button and how every seat voted. decidiq knows the agenda and who
sits where. Today the two never meet, so a clerk types speaker lists and vote
results over by hand. This change links them through integriq: the published
agenda and the seat list go to the room system, and speaker markings and
per-seat votes come back into decidiq under decidiq's own voting rules.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### liv-22, exchange the agenda, speakers and vote results with the room's conference and voting system

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`.

Demand row: origin `featurePage`, originUrl
https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._av-koppeling/

Competitor cells rated yes, verbatim:

- notubiz = yes | evidence: https://www.notubiz.nl/onze-diensten/gekoppelde-modules states the live AV link imports speaker markings and votes in real time and exports agendas; https://www.notubiz.nl/over-ons/onze-partners names MVI, Arbor Media (Cmeets) and AVEX with two-way exchange
- ibabs = yes | evidence: https://support.ibabs.com/docs/koppeling.md states the link between iBabs, Company Webcast and the AV installation (MVI) carries vote results from delegate units, name display and speaker indexing
- go-raadsinformatie = yes | evidence: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._av-koppeling/ states the two way link sends the agenda to the AV system and takes vote results and speaker information back; https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._stemgedrag/ reads out voting units

## Why

A Dutch council chamber usually has a delegate system from a vendor such as
MVI, Televic or Bosch: a microphone and voting unit per seat. Three of the
competitors read those units and write the result into the meeting. In decidiq
the clerk re-enters it, which is slow in a live meeting and is exactly where a
named vote gets a wrong name.

decidiq already has the two places the data belongs: speeches on a member's
engagement record (`EngagementService::captureEngagement()`) and named votes
through `VoteCastingService::castVote()`, which checks that the round is open
and the member belongs to the meeting. The link only has to deliver messages
to those two doors.

## What changes

1. A `room-system` connection in `lib/Settings/connections.json`, so the
   organisation links its delegate system in integriq. integriq holds the
   vendor adapters and the synchronisation in both directions.
2. `Participant.roomSeat`: the seat or delegate unit a member uses, set by the
   clerk on the member.
3. A `RoomSystemMessage` schema that integriq writes into: a speech or a vote,
   with the seat, the agenda item or voting round, and the times.
4. A listener that applies each message through the existing services: a
   speech through `EngagementService`, a vote through `VoteCastingService` with
   `castAs: in-person`. A message that cannot be applied is kept as `rejected`
   with a reason, never dropped.
5. A Room system widget on `MeetingDetail` that lists the messages of the
   meeting and every rejected one, so the clerk can fix a seat and apply again.

## What this supersedes

The speaker recognition part of the stale open change
`raadsvergadering-livestream-transcript` ("Sprekers detecteert door koppeling
met de microfoonbron uit het zaalsysteem", a `Spreker` entity with a
`microfoonId` and a new table) is superseded by this change. Speakers land on
the existing engagement record, not on a new entity.

## Out of scope

- The vendor protocols. Each delegate system speaks its own protocol; the
  adapters and the synchronisation schedule are integriq's half, to be
  specified in integriq.
- Name display on the seat screens. The room system reads the seat list; how
  it shows names is the vendor's.
- Secret ballots. A per-seat reading unmasks a secret vote, so a room system
  vote on a secret round is rejected.
- Linking a speech to the video timeline. That is the livestream's, see
  `live-public-livestream`.

## Risks

- **A wrong seat mapping is a wrong named vote.** An unmapped or doubly mapped
  seat is rejected, not guessed, and the chair sees the imported votes on the
  round before closing it, as with any vote.
- **Two sources for one vote.** A member who votes in the app and on the desk
  unit keeps one vote: `castVote()` already replaces a member's earlier vote in
  the round. The vote records which channel cast it.
- **An outside system writing into the register.** Only the integriq
  synchronisation user may create `RoomSystemMessage` objects, and a message
  changes nothing by itself: decidiq's own guards decide.
