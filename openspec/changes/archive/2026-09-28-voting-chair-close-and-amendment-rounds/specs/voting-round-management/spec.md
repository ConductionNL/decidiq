# voting-round-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [voting-chair-close-and-amendment-rounds](../../changes/voting-chair-close-and-amendment-rounds/)

## Purpose

The person who runs a meeting opens and closes its votes. The server already enforces that per meeting, and the page does not follow it: it shows the controls to Nextcloud admins only, and it has no way to vote on an amendment. These requirements make the page follow the server, and put the amendment round on the amendment page.

Matrix row: vot-01 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-VCR-001 The meeting's chair and secretary see the voting controls

The voting round panel SHALL show the open, close, full tally, tie-break and publish controls to the chair and the secretary of the round's meeting, whether or not they are Nextcloud admins. It SHALL hide them from every other member. The panel MUST take this decision from the server's answer (REQ-VCR-002) and MUST NOT derive it from `settingsStore.isAdmin` or from any other rule of its own.

#### Scenario: A chair who is not an admin closes a vote

- GIVEN user `chair1` is a participant with role `chair` in meeting "Raad 12 maart" and is not a Nextcloud admin
- AND motion "Groen dak op het stadhuis" has an open voting round in that meeting
- WHEN `chair1` opens the motion page `/motions/{id}` and looks at the "Voting round" widget
- THEN the "Close voting round" button is shown
- AND after `chair1` confirms the close, the widget shows the result and the round has a `closedAt`

#### Scenario: A member sees neither the open nor the close control

- GIVEN user `member1` is a participant with role `member` in the same meeting
- WHEN `member1` opens a motion in lifecycle `deliberating` with no round
- THEN no "Open voting round" button is shown
- AND on a motion with an open round, `member1` sees the vote buttons and the count of votes cast, but no close button and no split per option

#### Scenario: An admin keeps the controls when the round has no meeting

- GIVEN a Nextcloud admin and a voting round whose meeting cannot be resolved
- WHEN the admin opens the widget
- THEN the close control is shown, because the server's global fallback accepts the admin

### Requirement: REQ-VCR-002 The server says which voting controls a user may use in a meeting

The system SHALL expose `GET /api/meetings/{meetingId}/voting-permissions` to any signed-in user. It SHALL return `canOpen`, `canClose`, `canEnterTally` and `canCastChairVote` for the caller. Each value MUST be computed by the same `VotingRoundGuard` check that the matching endpoint enforces, so that a control the page shows is never refused by the server for lack of a role. For a meeting that does not exist the response SHALL be HTTP 200 with all four values `false`.

#### Scenario: The answer matches the endpoints

- GIVEN `chair1` is the chair and `member1` a member of meeting M
- WHEN each calls `GET /api/meetings/M/voting-permissions`
- THEN `chair1` receives `canClose: true` and `canCastChairVote: true`
- AND `member1` receives four `false` values
- AND `POST /api/voting-rounds/{id}/close` on a round in M returns 200 for `chair1` and 403 for `member1`

#### Scenario: A secretary may close but may not cast the chair's vote

- GIVEN user `griffier1` is the secretary of meeting M
- WHEN `griffier1` calls the permissions endpoint
- THEN `canClose` and `canEnterTally` are `true` and `canCastChairVote` is `false`

### Requirement: REQ-VCR-003 Tally entry and publication check the role in the round's own meeting

`POST /api/voting-rounds/{id}/tally` and `POST /api/voting-rounds/{id}/publish` SHALL resolve the meeting from the round and SHALL check the caller's chair or secretary role in that meeting, as `POST /api/voting-rounds/{id}/close` does. When no meeting resolves they SHALL fall back to the existing global check.

#### Scenario: A meeting secretary enters a show-of-hands result

- GIVEN `griffier1` is the secretary of meeting M and not a Nextcloud admin
- AND a show-of-hands round is open on a motion in M
- WHEN `griffier1` saves 14 for, 9 against and 2 abstentions in the widget
- THEN the endpoint returns 200 and the round shows those totals

#### Scenario: The secretary of another meeting is refused

- GIVEN `griffier2` is the secretary of meeting N and has no role in meeting M
- WHEN `griffier2` posts a tally to a round in M
- THEN the endpoint returns 403 and the round's totals are unchanged

### Requirement: REQ-VCR-004 A chair opens and closes a vote on an amendment from the amendment page

The amendment detail page `/amendments/:id` SHALL show a "Voting round" widget that mounts the voting round panel for that amendment. Opening a round there SHALL send `subjectType: amendment`, and the round's meeting SHALL be the parent motion's meeting. The server's existing amendment order rule SHALL apply unchanged, and its refusal message SHALL be shown in the widget.

#### Scenario: The chair votes on an amendment

- GIVEN motion "Groen dak op het stadhuis" in meeting M with amendment A1 (`votingOrder` 1) in lifecycle `deliberating`
- WHEN `chair1` opens `/amendments/A1`, clicks "Open voting round" and confirms
- THEN a voting round is created that relates to A1 under schema `amendment`
- AND A1's lifecycle becomes `voting`
- AND the widget shows the open round with the close control for `chair1`

#### Scenario: An amendment out of order is refused on the page

- GIVEN the same motion with amendment A1 (`votingOrder` 1) and A2 (`votingOrder` 2), both undecided
- WHEN `chair1` tries to open a round on A2 from `/amendments/A2`
- THEN no round is created
- AND the widget shows the server's message that A1 must be voted first

#### Scenario: An amendment whose motion has no meeting

- GIVEN an amendment whose parent motion has no linked meeting
- WHEN the chair opens its page
- THEN the "Open voting round" button is disabled and its title says that no meeting is linked

#### Scenario: The motion vote follows its amendments

- GIVEN both amendments of a motion have closed rounds
- WHEN `chair1` opens the motion page
- THEN the "Open voting round" button on the motion is enabled and opening a round succeeds
