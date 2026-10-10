# Design: voting-written-resolution

Kind: code. A register fragment with five round properties and two enum values, a written branch in open, cast, tally and close, one timed job, and one widget on the decision page.

Read at development `4d7430ff`.

## What is there today

- Opening needs a meeting. `VotingOpenRequestParser::parse()` refuses a request without `meetingId` (`lib/Service/VotingOpenRequestParser.php:74`), `VotingRoundOpener::openVotingRound()` checks the meeting's quorum (`lib/Service/VotingRoundOpener.php:197`, `checkQuorum()` at `lib/Service/VotingService.php:120`) and the panel disables its open button without a meeting (`src/components/VotingRoundPanel.vue:22` to `:35`).
- The subject of a round is a motion or an amendment. `VotingRoundPreflight::buildRoundPayload()` relates the round to it under that name (`lib/Service/VotingRoundPreflight.php:244`), `VotingRoundCloser::subjectOf()` only reads those two names (`lib/Service/VotingRoundCloser.php:400`) and `transitionSubject()` (`:200`) moves the subject to `decided` with the outcome.
- A round can also resolve a decision stage: `VotingRound.decisionStage` (`lib/Settings/decidesk_register.json`, `VotingRound`) is read by `VotingRoundCloser::resolveDecisionStage()` (`:317`), which decides an `active` stage with the round's outcome. Nothing in the UI opens a round for a stage.
- Casting is tied to meeting participants. `VotingService::resolveParticipantUuid()` (`VotingService.php:107`) maps the Nextcloud user to a `participant`, and `VoteCastGuard::assertMeetingMembership()` (`lib/Service/VoteCastGuard.php:132`) checks the participant against the round's meeting. When no meeting resolves it returns without a check (`:134` to `:136`), so a round without a meeting would accept any participant. A written round must not rely on it.
- The result is computed against the votes cast (`VotingResultCalculator::compute()`, base `for + against`, or the total under `abstentionHandling: count`).
- Who presides over a body is projected into Nextcloud groups: `GovernanceRoleScopeProjector` (`lib/Service/GovernanceRoleScopeProjector.php:106`) keeps `decidesk:body:{bodyId}:signatory` for chair, vice-chair and secretary, and `GovernanceScopeGuard::isInBodyScope()` (`lib/Service/GovernanceScopeGuard.php:119`) checks it.
- Members of a body are `membership` objects with `role`, `startDate`, `endDate`, `votingWeight` and a `person` (`decidesk_register.json:334`), and a person carries `nextcloudUserId` (`lib/Settings/register.d/67-model-debt-cleanup.json:35`).
- The decision page (`src/manifest.json:1161`) shows a read-only "Voting results" widget, `DecisionVotingTab` (`:1187`, slot `:1171`), that walks decision, motion, round and votes. It never opens anything.
- The hourly `VotingDeadlineReminderJob` (`appinfo/info.xml:126`) reminds non-voters when `votingDeadline` is within 24 hours and `closedAt` is empty (`lib/Service/VotingDeadlineReminderService.php:140`). decidiq#1379 reports that no round sets `votingDeadline` today. A written round does.

## Decisions

### D1. A written round is a voting round without a meeting

New `VotingRound` properties:

- `procedure`: enum `in-meeting`, `written`, default `in-meeting`. Every stored round keeps meaning what it meant.
- `governanceBody`: uuid, `$ref` GovernanceBody. Required when `procedure` is `written`.
- `electorate`: array of objects `membership` (uuid), `person` (uuid), `userId` (string), `votingWeight` (number). The snapshot of who must answer.
- `electorateUserIds`: array of strings, the same user ids flat, for the notification recipient.
- `writtenRule`: enum `unanimous`, `threshold`, default `unanimous`. Under `threshold` the round's `voteThreshold` applies to the whole electorate.

The subject of a written round is any decision: `buildRoundPayload()` relates it under the name `decision`, a third value next to `motion` and `amendment`, and `subjectOf()` learns that name. When the decision has an `active` stage with `method: vote` assigned to the same body, the round's `decisionStage` is set at open, so the existing stage resolution decides it at close.

### D2. Who opens it, and what the electorate is

`POST /api/voting-rounds` accepts `procedure: written` with `decisionId`, `governanceBodyId`, `votingDeadline` and optionally `writtenRule` and `voteThreshold`, and no `meetingId`. The parser refuses a written request without a body or a deadline, with a deadline in the past, or with a `meetingId`.

The guard for a written open is `GovernanceScopeGuard::isInBodyScope($uid, $bodyId, 'signatory')`, never the meeting check. The decision must be in lifecycle `proposed` or `deliberating` and have no meeting.

The opener builds the snapshot from the body's memberships that are active on the day (started, not ended), whose role is not `observer` or `guest`, and whose person has a `nextcloudUserId`. Members without a user id are listed in the open response as `unreachable`, and the open is refused if any exist, because a unanimous rule cannot be met by someone who cannot answer. The quorum check is skipped; the electorate rule replaces it.

### D3. Answering

On a written round the cast path resolves the caller against `electorateUserIds` instead of meeting participants, and refuses anyone not in it. It never falls through to the open `assertMeetingMembership()` branch. The vote relates to the member's `membership`, not to a participant.

`Vote.value` gains `objection`, accepted on written rounds only, meaning "discuss this in a meeting". A member may change their answer until the round closes; the vote slug makes a second answer replace the first.

### D4. Counting and closing

`VotingResultCalculator` gets the electorate size as the base for a written round:

- `unanimous`: `adopted` when every entitled member answered `for`; otherwise `rejected`.
- `threshold`: `adopted` when the `for` weight meets `voteThreshold` of the whole electorate's weight and nobody objected; otherwise `rejected`.
- Any `objection` makes the result `rejected` with `closedReason: objection`.

The round closes, through the existing `closeVotingRound()`, at the first of: every member answered; the outcome can no longer change (under `unanimous` the first answer that is not `for`); an objection; the deadline. The first three are checked right after a cast is saved. The deadline close is a new `WrittenResolutionDeadlineJob` in `lib/BackgroundJob/`, registered in `appinfo/info.xml` (ADR-069). It closes open written rounds whose `votingDeadline` has passed and records `closedReason: deadline`. It is idempotent: a closed round is skipped.

`closedReason` is a new round property: enum `all-answered`, `outcome-settled`, `objection`, `deadline`.

On `adopted` the closer moves the decision to `decided` with outcome `adopted` and sets `decisionDate` to the time of the last answer. On `rejected` it moves it to `decided` with outcome `rejected`.

### D5. The widget

A new `WrittenResolutionTab.vue` in `src/components/tabs/`, registered in `src/registry.js` and wired on `DecisionDetail` as widget `decision-written-resolution` with a slot and a layout cell.

- For a signatory of a body, on a decision without a meeting in `proposed` or `deliberating`: a "Decide in writing" button opening `src/modals/WrittenResolutionModal.vue` (body, deadline, rule).
- For an entitled member on an open written round: the four answers, the deadline, and their current answer.
- For everyone who can read the decision: progress ("3 of 5 answered"), and after closing the full record with each member, their answer and its time, and the close reason.
- When the deadline has passed and the round is still open: "Deadline passed, waiting to close".

### D6. Telling the members

The notification to the electorate on open is declared as `x-openregister-notifications` on `voting-round`: trigger `created` with a condition `procedure` equals `written`, channel `nc-notification`, recipients `kind: field` on `electorateUserIds`, and an action that opens the decision. The 24-hour reminder comes from the existing reminder job, because a written round sets `votingDeadline` and leaves `closedAt` empty until it closes.

The build task first checks that OpenRegister's field recipient accepts a list of user ids. If it does not, the open path calls the existing imperative `VotingOpenedNotifier::announce()` (`lib/Service/VotingOpenedNotifier.php:74`) with the electorate instead, under the ADR-031 exception for a missing extension, and an issue is filed on openregister.

## Declarative or imperative

- Declared in a new fragment `lib/Settings/register.d/NN-written-resolution.json` (next free number at build time): the round properties of D1 and D4, `Vote.value: objection`, a `membership` relation on `vote`, and the notification of D6.
- Imperative, under the ADR-031 exceptions: the electorate snapshot and the counting rule sit on the existing voting service seam, because the result rules are shared with every other round; the deadline close is a background job that moves a decision's lifecycle, which a calculation cannot do (ADR-031, "Background jobs that walk an object queue and apply a transition", with no n8n dependency for a statutory rule).

## Seed data

In `lib/Settings/profiles/corporate.json` (`x-openregister.seedData.objects`):

- a `decision` `besluit-schriftelijk-accountant-2026`, `decisionType: resolution`, title "Appoint the external auditor for 2026", no meeting, lifecycle `decided`, outcome `adopted`;
- a `voting-round` `schriftelijke-ronde-accountant-2026`: `procedure: written`, `governanceBody` `rvc-waterschap-amstel`, `writtenRule: unanimous`, an electorate of its three memberships (`m-janneke-rvc`, `m-jan-amstel`, `m-mark-rvb`), `votingDeadline` five days after opening, `result: adopted`, `closedReason: all-answered`;
- three `vote` objects on it, each `for`, each related to its `membership`;
- `nextcloudUserId` on the three seeded persons (`janneke-de-bruin`, `jan-de-vries`, `mark-van-den-berg`), which have none today, set to the test users the e2e fixture creates. Without it no seeded member could answer.

## Risks

- `assertMeetingMembership()` accepts anyone when a round's meeting cannot be resolved. A written round never reaches it, and the Newman collection asserts a non-member is refused. The general gap is reported to the lane separately.
- Members without a Nextcloud account block the open. The refusal names them, and the secretary fixes the member record or holds a meeting.
