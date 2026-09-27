---
kind: code
---

# Proposal: voting-chair-close-and-amendment-rounds

## Summary

A chair who is not a Nextcloud admin cannot close a vote from the page, and an amendment has no place to open a vote at all. This change lets the voting round panel ask the server who may preside over votes in a meeting, so the meeting's own chair and secretary see the open, close and tally controls. It then mounts the same panel on the amendment page, where the server already accepts amendment rounds.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### vot-01, open a vote on a motion or amendment and close it

Own rating `partial`, built.state `built`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/components/VotingRoundPanel.vue:35 'Open voting round' (only when motion lifecycle is 'deliberating' and a meeting is linked) -> POST /api/voting-rounds (lib/Controller/VotingController.php:100 open); close -> POST /api/voting-rounds/{id}/close (VotingController.php:199), but the close button is gated on isChairOrSecretary() which returns settingsStore.isAdmin (VotingRoundPanel.vue:549); panel mounted only on MotionDetail via MotionVotingRoundTab, not on AmendmentDetail

Matrix note, verbatim:

> Opening and closing a round on a motion works. The close control only renders for Nextcloud admins, not for a non-admin chair, and amendments have no voting-round surface.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.notubiz.nl/onze-diensten/live-uitzenden states NotuVote voting moments are created in the beheer; https://www.kennisbank.notubiz.nl/handleidingen/digitaal-stemmen states a vote is started and the administrator releases the result
- ibabs: https://support.ibabs.com/docs/live-stemmen-door-deelnemers.md states the agenda manager initiates a live vote on an item and closes it
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._stemgedrag/ states the griffie creates a vote, starts a voting round during the meeting and closes it before publishing the result
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/approval/creating-a-custom-approval-bwa.htm creates an approval (renamed from Vote, https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/release_notes/release-notes-bwa.htm Feb 21, 2025) and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/approval/closing-a-custom-approval-bwa.htm closes it
- openslides: source read at 4.3.4, not driven: openslides-backend/meta/collections/poll.yml:41 state created, started, finished, published, driven by poll.create, poll.start and poll.stop called from the motion poll pages (openslides-client/client/src/app/site/pages/meetings/pages/motions/pages/motion-polls/motion-polls-routing.module.ts:9) and motion-manage-polls.

Lane decision: `build`. Reason, verbatim: "Partial and built, five competitors rated yes: the close control renders for Nextcloud admins only (decidiq#1379) and amendments have no voting round surface. Both halves are specified."

## Why

Five of five competitors let the person running the meeting open and close a vote. decidiq's server already agrees: `VotingController::close()` accepts the meeting's chair or secretary. The page disagrees. It shows the close control, the live split and the tie-break controls only to Nextcloud admins, so in a normal installation the chair has to ask IT to end a vote.

The amendment half is the same shape. The server opens amendment rounds and enforces the voting order, but no page ever sends `subjectType: amendment`, so that code has no caller.

## What changes

1. The server answers one question per meeting: may this person open, close, enter a tally for, or cast the chair's vote in a round here. The answer comes from the same guard the endpoints use, so the page and the API cannot drift apart again.
2. The voting round panel reads that answer instead of `settingsStore.isAdmin`, for every control it gates today.
3. The tally and publish endpoints resolve the meeting from the round before they check the role, as close already does. Without this the page would show a meeting secretary a tally form the server then refuses.
4. The panel takes the subject type as a prop and sends it when it opens a round.
5. The amendment page gets a voting round widget that mounts the panel for the amendment, with the meeting taken from the parent motion.

## Out of scope

- The voting deadline reminder half of decidiq#1379 (rows vot-17 and pla-06). It is a different row.
- The amendment voting order rule itself. It is built and enforced server side (see design).
- Ranked ballots, written resolutions and named paper votes. Each has its own change in this pass: `voting-ranked-preference-ballot`, `voting-written-resolution`, `voting-named-paper-vote-entry`.

## Builds on and supersedes

- Builds on `voting-round-management` (REQ-VRM-001 and REQ-VRM-004, status done) and on `motion-amendment` "Amendment voting order", which is built server side. This change adds the page half both assumed.
- decidiq#1379 reports the admin-only close control. If a fix for that issue lands first, REQ-VCR-001 and REQ-VCR-002 are met by it and this change shrinks to the amendment half. The lane should check before building.

## Risks

- A page that works out roles by itself drifts from the server. That is how this bug arose, so the page only asks and never decides.
- The global fallback (`chair_group` app setting, else Nextcloud admin) still applies when a round has no resolvable meeting. The answer endpoint returns the same fallback, so an admin keeps every control they have today.
- An amendment whose parent motion has no meeting cannot be voted on. The panel says so, as it does for a motion without a meeting.
