# Specs: Motion and Voting — Core T3

**Change:** p2-motion-and-voting-core-t3
**App:** Decidesk
**Entities:** Motion, VotingRound, Vote, ActionItem

---

## REQ-MET: Motion Execution Tracking

### REQ-MET-001 — Transition an adopted Motion to execution-pending
A clerk or executive can mark an adopted motion as pending execution.

**GIVEN** a Motion detail page is open and the Motion `lifecycle` is `adopted`
**WHEN** the user clicks "Markeer voor uitvoering" in the "Uitvoering" panel
**THEN** `MotionService::transitionLifecycle()` is called with target state `execution-pending`
**AND** the Motion `lifecycle` is updated to `execution-pending` via `ObjectService.saveObject()`
**AND** an ActionItem is automatically created with `title: "Uitvoering motie: {motionTitle}"`, `taskStatus: open`, and `dueDate` set to `now() + motion_execution_deadline_days` (default 90 days)
**AND** the ActionItem is linked to the Motion via an OpenRegister relation
**AND** the transition is logged to the audit trail via `ActivityService`
**AND** the "Uitvoering" panel shows the new status badge and the linked ActionItem

### REQ-MET-002 — Transition a motion from execution-pending to executing
A clerk or executive can mark a motion as actively being executed.

**GIVEN** a Motion detail page is open and `lifecycle` is `execution-pending`
**WHEN** the user clicks "Start uitvoering" in the "Uitvoering" panel
**THEN** `MotionService::transitionLifecycle()` is called with target state `executing`
**AND** the Motion `lifecycle` is updated to `executing`
**AND** the transition is logged to the audit trail
**AND** `CnTimelineStages` in the "Uitvoering" panel reflects the new stage

### REQ-MET-003 — Mark a motion as fully executed
A clerk or executive can close the execution lifecycle by marking a motion as executed.

**GIVEN** a Motion detail page is open and `lifecycle` is `executing`
**WHEN** the user clicks "Markeer als uitgevoerd" in the "Uitvoering" panel
**THEN** `MotionService::transitionLifecycle()` is called with target state `executed`
**AND** the Motion `lifecycle` is updated to `executed`
**AND** the linked execution ActionItem `taskStatus` is updated to `completed` via `ObjectService.saveObject()`
**AND** the `completedAt` field on the ActionItem is set to the current timestamp
**AND** the transition and ActionItem completion are logged to the audit trail
**AND** the "Uitvoering" panel displays a completion badge and the completion date

### REQ-MET-004 — Add an execution note to an adopted or executing Motion
A clerk or executive can record a narrative update on the execution progress.

**GIVEN** a Motion detail page is open and `lifecycle` is one of `execution-pending`, `executing`, or `executed`
**WHEN** the user enters text in the "Uitvoeringsnotitie" field and clicks "Opslaan"
**THEN** `MotionService::updateExecutionNote()` is called with the entered text
**AND** a note is created or updated on the Motion object with `title: "Uitvoering"` and the entered text as body
**AND** the note is saved via the built-in OpenRegister notes mechanism
**AND** the note is visible in the `CnObjectSidebar` Notes tab and in the "Uitvoering" panel

### REQ-MET-005 — View execution status on the Motion index page
A user can filter and identify motions by their execution state.

**GIVEN** the Motions index page is open
**WHEN** the user applies the filter "Uitvoeringsstatus" via `CnFilterBar`
**THEN** motions can be filtered to show only those with `lifecycle` in `execution-pending`, `executing`, or `executed`
**AND** each row displays the execution lifecycle badge alongside the primary lifecycle badge
**AND** the filter applies without a full page reload

### REQ-MET-006 — Block invalid execution lifecycle transitions
The system rejects lifecycle transitions that are not permitted.

**GIVEN** a Motion with `lifecycle: submitted`
**WHEN** an API caller sends `POST /api/motions/{id}/transition` with `newState: execution-pending`
**THEN** the response is `HTTP 400 Bad Request`
**AND** the response body contains `{ "message": "Transition not allowed" }`
**AND** the Motion lifecycle is unchanged

---

## REQ-VAN: Vote Anonymisation

### REQ-VAN-001 — Anonymise voter identity on a closed VotingRound
A chair or secretary can irreversibly anonymise voter identities in a closed VotingRound.

**GIVEN** a VotingRound detail page is open and the round `result` is `adopted` or `rejected` (i.e. closed)
**WHEN** the user clicks "Stemmen anonimiseren" and confirms in the confirmation dialog
**THEN** `VotingAnonymizationService::anonymize()` is called with the VotingRound ID and the actor's user ID
**AND** all Vote objects linked to the VotingRound have their person relation nullified via `ObjectService.saveObject()`
**AND** the VotingRound `tags` array is updated to include `votes-anonymized`
**AND** the operation is logged to the audit trail via `ActivityService` with: actor, timestamp, and count of anonymised votes
**AND** the VotingRound detail page shows a `CnStatusBadge` with label "Anoniem" and the anonymisation date

### REQ-VAN-002 — Preserve aggregate vote counts after anonymisation
Anonymisation must not alter the public vote record.

**GIVEN** a VotingRound that has been anonymised (tagged `votes-anonymized`)
**WHEN** the VotingRound is displayed on its detail page or via the ORI API
**THEN** `votesFor`, `votesAgainst`, `votesAbstain`, and `result` are unchanged
**AND** the individual Vote objects are still present and show `value` (for/against/abstain) and `castAt`
**AND** the Vote objects no longer have a linked Person relation
**AND** the ORI publication endpoint returns the aggregate counts without voter identity

### REQ-VAN-003 — Block anonymisation on an open VotingRound
A user cannot anonymise votes while a round is still in progress.

**GIVEN** a VotingRound with no `closedAt` timestamp (i.e. still open)
**WHEN** an API caller sends `POST /api/voting-rounds/{id}/anonymize`
**THEN** the response is `HTTP 400 Bad Request`
**AND** the response body contains `{ "message": "Cannot anonymise votes in an open voting round" }`
**AND** no votes are modified

### REQ-VAN-004 — Block double anonymisation
A VotingRound that is already anonymised cannot be anonymised again.

**GIVEN** a VotingRound already tagged `votes-anonymized`
**WHEN** an API caller sends `POST /api/voting-rounds/{id}/anonymize`
**THEN** the response is `HTTP 409 Conflict`
**AND** the response body contains `{ "message": "Voting round is already anonymised" }`
**AND** no Vote objects are modified

### REQ-VAN-005 — View anonymisation status and audit trail
A user can inspect when and by whom votes were anonymised.

**GIVEN** a VotingRound detail page is open for a round tagged `votes-anonymized`
**WHEN** the user opens the `CnObjectSidebar` → Audit Trail tab
**THEN** an audit entry is displayed showing: action "Vote anonymisation", actor display name, timestamp, and count of anonymised votes
**AND** the VotingRound header displays the `votes-anonymized` status badge

---

## REQ-QLC: Quorum Calculator

### REQ-QLC-001 — Preview quorum threshold for a GovernanceBody
A secretary or chair can see the quorum threshold for a given expected attendance.

**GIVEN** a GovernanceBody detail page is open
**WHEN** the user opens the "Quorum calculator" panel and sets `Verwacht aanwezig` to an integer N
**THEN** `GET /api/governance-bodies/{id}/quorum-preview?expectedAttendance=N` is called
**AND** the panel displays:
  - `Totaal leden`: the count of active members (non-null `endDate` or `endDate` in the future)
  - `Vereist quorum`: the minimum attendance required per `GovernanceBody.quorumRule`
  - `Vereiste meerderheid voor/tegen-stemmen`: the majority threshold given N attendees
  - `Quorum gehaald`: a green check or red cross based on whether N meets the quorum threshold
**AND** the calculation updates without page reload as the user changes N

### REQ-QLC-002 — Quorum calculator embedded in VotingRound creation form
A secretary can check quorum viability before opening a VotingRound.

**GIVEN** the VotingRound creation dialog is open for a Motion
**WHEN** the user views the "Quorum" section of the form
**THEN** the quorum calculator panel is embedded showing current registered attendance for the linked Meeting
**AND** the "Stemronde openen" button is disabled with tooltip "Quorum niet bereikt" when the calculator shows quorum is not met
**AND** the button is enabled once attendance meets the threshold

### REQ-QLC-003 — Handle missing or unparseable quorumRule gracefully
The calculator must degrade gracefully when configuration is incomplete.

**GIVEN** a GovernanceBody where `quorumRule` is empty or null
**WHEN** `GET /api/governance-bodies/{id}/quorum-preview` is called
**THEN** the response is `HTTP 200 OK`
**AND** the response body contains `{ "requiredVotes": null, "requiredMajority": null, "isQuorumMet": null, "memberCount": <N>, "warning": "Quorumregel niet ingesteld voor dit orgaan" }`

### REQ-QLC-004 — Quorum preview endpoint is accessible to members, not just admins
Active governance body members can access the calculator.

**GIVEN** a logged-in user who is an active member of the GovernanceBody
**WHEN** `GET /api/governance-bodies/{id}/quorum-preview?expectedAttendance=10` is called
**THEN** the response is `HTTP 200 OK` with the quorum preview data
**AND** the endpoint does NOT require admin rights

**GIVEN** a user who has no membership in the GovernanceBody
**WHEN** `GET /api/governance-bodies/{id}/quorum-preview` is called
**THEN** the response is `HTTP 403 Forbidden`

---

## REQ-WRA: Written/Circular Resolution Approval

### REQ-WRA-001 — Open a written/circular resolution VotingRound
A secretary can initiate an out-of-meeting resolution procedure.

**GIVEN** a Motion detail page is open with `motionType: written-resolution` and `lifecycle: voting`
**WHEN** the user opens the VotingRound creation dialog, selects `votingMethod: written-resolution`, sets a `closedAt` deadline, and clicks "Schriftelijke stemming openen"
**THEN** `VotingService::openVotingRound()` is called with `votingMethod: written-resolution`
**AND** a VotingRound is created with the provided deadline
**AND** all active members of the linked GovernanceBody receive a Nextcloud notification containing: the Motion title, the Motion text summary, a direct link to cast their vote, and the voting deadline
**AND** an ActionItem is created with `title: "Termijn schriftelijke stemming: {motionTitle}"` and `dueDate: closedAt`
**AND** the notification dispatch count is logged to the audit trail

### REQ-WRA-002 — Cast a vote in a written resolution round via the UI
An active governance body member can cast their vote in a written resolution round.

**GIVEN** a VotingRound with `votingMethod: written-resolution` is open (`closedAt` is in the future)
**AND** the logged-in user is an active member of the GovernanceBody
**WHEN** the user navigates to the VotingRound detail page and selects "Voor", "Tegen", or "Onthouding"
**THEN** `VotingService::castVote()` is called with the user's participation ID and chosen value
**AND** a Vote object is created with `value`, `castAt`, `isProxy: false`
**AND** a confirmation notification is shown to the user: "Uw stem is uitgebracht"
**AND** the Vote is linked to the VotingRound and to the voter's Person record

### REQ-WRA-003 — Close a written resolution round and tally results
After the deadline passes, the secretary can close the round and record the formal result.

**GIVEN** a VotingRound with `votingMethod: written-resolution` and `closedAt` in the past
**WHEN** the user clicks "Stemronde sluiten" on the VotingRound detail page
**THEN** `VotingService::closeVotingRound()` is called
**AND** `VotingService::tallyResults()` counts all cast votes and computes `votesFor`, `votesAgainst`, `votesAbstain`
**AND** the `result` is determined: `adopted` if `votesFor > votesAgainst`, `rejected` otherwise, `tied` if equal
**AND** the linked Motion `lifecycle` is updated to `adopted` or `rejected` accordingly
**AND** the result is available via `GET /api/voting-rounds/{id}` including all vote counts

### REQ-WRA-004 — Block voting after the written resolution deadline
A member cannot cast a vote after the round has closed.

**GIVEN** a VotingRound with `votingMethod: written-resolution` where `closedAt` is in the past
**WHEN** an API caller sends `POST /api/voting-rounds/{id}/cast` with a vote value
**THEN** the response is `HTTP 400 Bad Request`
**AND** the response body contains `{ "message": "Voting round is closed" }`
**AND** no Vote object is created

### REQ-WRA-005 — Verify that written resolution meets universal participation requirement
The system enforces that all active members have been notified.

**GIVEN** a GovernanceBody with N active members
**WHEN** a written resolution VotingRound is opened
**THEN** exactly N notification dispatches are logged (one per active member)
**AND** if any notification fails, the failed member's display name is listed in the server log and the round is still created (partial failure does not abort)
**AND** the VotingRound detail page shows "N van M leden genotificeerd" if any notification failed
