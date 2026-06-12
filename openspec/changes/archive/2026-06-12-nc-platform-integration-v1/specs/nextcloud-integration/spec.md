# Spec delta: Nextcloud Integration — Activity, Search, Meeting folders, Deadline reminder

This file contains delta specifications for the `nc-platform-integration-v1` change against the existing `nextcloud-integration` capability.

---

## MODIFIED Requirements

### Requirement: Activity Integration

The system MUST publish Decidesk events to the Nextcloud Activity feed via `OCP\Activity\IManager` under a registered activity type `decidesk_governance` with an `OCP\Activity\IProvider`, an `ActivitySettings` entry, and an `OCP\Activity\IFilter` declared in `appinfo/info.xml`. Events MUST include: decision recorded, decision published (status change), meeting lifecycle transitions (both operational meetings and board meetings), vote initiation (voting rounds and board resolution votes), and resolution adoption. Activity publication MUST be fail-soft: a failure to publish MUST never abort the underlying governance transition.

**Feature tier**: V1

#### Scenario: Decision status change appears in Activity feed

@e2e exclude NC Activity stream is platform chrome (no decidesk SPA surface); provider parse + publish call sites covered by PHPUnit (DecideskProviderTest, ActivityPublisherServiceTest)
- GIVEN a decision "Approve Budget 2026" is published (status change to published)
- WHEN the transition is completed
- THEN an Activity entry MUST be created with subject `decision_published` and the decision title
- AND the entry MUST be addressed to the members of the governing body resolvable for the event plus the acting user
- AND the entry's link MUST navigate to the decision in Decidesk (`/apps/decidesk/#/decisions/{uuid}`)

#### Scenario: Meeting lifecycle transition appears in Activity feed

@e2e exclude NC Activity stream is platform chrome; covered by PHPUnit on MeetingService/BoardMeetingService call sites
- GIVEN a meeting transitions lifecycle (e.g. scheduled → opened)
- WHEN the transition succeeds
- THEN an Activity entry with subject `meeting_transition` MUST be published carrying the meeting title and the new lifecycle state
- AND a failing Activity backend MUST NOT make the transition fail

#### Scenario: Vote initiation and resolution adoption appear in Activity feed

@e2e exclude NC Activity stream is platform chrome; covered by PHPUnit on VotingService/ResolutionService call sites
- GIVEN a voting round is opened on a motion, or a board resolution vote is opened, or a resolution concludes as adopted
- WHEN the operation succeeds
- THEN an Activity entry MUST be published with subject `vote_initiated` (round/vote opening) or `resolution_adopted` (adoption) and a deep link to the object

### Requirement: Search Integration

The system MUST register a search provider via `OCP\Search\IProvider` (`IRegistrationContext::registerSearchProvider`) so that decisions, meetings, and resolutions are findable from Nextcloud's universal search. The provider MUST query OpenRegister's `ObjectService` full-text search with RBAC enabled under the searching user's session so that ONLY objects the user is permitted to read are returned (per-user visibility is a security requirement). Results MUST deep-link into the corresponding Decidesk detail route.

**Feature tier**: V1

#### Scenario: Find a decision via Nextcloud search

@e2e exclude NC universal-search UI is platform chrome (no decidesk SPA surface); provider behaviour covered by PHPUnit (DecideskSearchProviderTest)
- GIVEN decisions exist including "Budget 2026 Approval"
- WHEN the user searches for "budget" in Nextcloud's universal search
- THEN the Decidesk search provider MUST return "Budget 2026 Approval" as a result
- AND the result MUST show the decision title, a status subline, and the Decidesk app icon as thumbnail
- AND clicking the result MUST navigate to the decision detail view in Decidesk

#### Scenario: Search results respect per-user visibility

@e2e exclude RBAC delegation is asserted at the service boundary by PHPUnit; OR RBAC itself is owned and e2e-tested by openregister
- GIVEN a decision the searching user has no OpenRegister read access to
- WHEN the user searches for that decision's title
- THEN the provider MUST NOT return it (the provider queries ObjectService with RBAC enabled and never passes `_rbac: false`)

### Requirement: Files Integration

The system MUST store and manage meeting documents using Nextcloud Files. Each meeting MUST have a dedicated folder structure created on meeting creation: `Decidesk/<body name>/<date> <title>/` with `Agenda Documents` and `Minutes` subfolders, created idempotently via OpenRegister's `FileService` under the OpenRegister-managed root (the same mechanism as adopted-motion dossier folders). Folder path components MUST be sanitised against path traversal. Folder access follows OpenRegister register sharing; per-member read-vs-write ACL differentiation (body members read, secretary/chair write) REQUIRES the groupfolders app and is out of scope for plain `OCP\Files` app code — this residue is tracked in the capability status-note.

**Feature tier**: V1

#### Scenario: Create meeting folder structure on meeting creation

@e2e exclude server-side OR-event hook with no decidesk SPA surface (folders appear in NC Files chrome); covered by PHPUnit (MeetingFolderServiceTest, MeetingFolderListenerTest)
- GIVEN a meeting "Board Meeting Q2 2026" for body "Board of Directors" scheduled 2026-07-15
- WHEN the meeting object is created (app UI or OR API)
- THEN a folder MUST be created at `Decidesk/Board of Directors/2026-07-15 Board Meeting Q2 2026/`
- AND subfolders MUST be created for "Agenda Documents" and "Minutes"
- AND re-processing the same meeting MUST NOT fail or duplicate folders (idempotent get-or-create)

#### Scenario: Attach document to agenda item via file picker

@e2e exclude pre-existing scenario delivered by the OR file-attachment surface; unchanged by this change
- GIVEN a user editing an agenda item
- WHEN they click "Attach Document" and select a file from Nextcloud Files
- THEN the file MUST be linked to the agenda item
- AND the file MUST be accessible to all meeting participants
- AND the file MUST appear in the meeting document package

### Requirement: Notification Integration

The system MUST send Nextcloud Notifications for time-sensitive governance events: upcoming meeting reminders, pending votes, voting deadlines approaching, and action item due dates. The voting-deadline reminder MUST be produced by a scheduled background job (`TimedJob`, hourly, registered via `appinfo/info.xml` `<background-jobs>`) that notifies each eligible participant who has not yet voted in an open voting round whose deadline falls within the next 24 hours, exactly once per round (a `deadlineReminderSentAt` marker prevents duplicates).

**Feature tier**: V1

#### Scenario: Send pending vote notification

@e2e exclude NC notification bell is platform chrome; pre-existing sender behaviour unchanged by this change
- GIVEN a new vote has been initiated for decision "Policy Update"
- WHEN the vote opens
- THEN all eligible voters MUST receive a Nextcloud notification
- AND the notification MUST include the decision title, body, and voting deadline
- AND tapping the notification MUST open the voting interface

#### Scenario: Send voting deadline reminder

@e2e exclude background-job + NC notification chrome (no decidesk SPA surface); window calculation, selection, skip-already-voted and dedup covered by PHPUnit (VotingDeadlineReminderServiceTest, VotingDeadlineReminderJobTest)
- GIVEN an open voting round with `closedAt` 20 hours from now and a participant who has not yet voted
- WHEN the hourly reminder job runs
- THEN that participant MUST receive a notification "Reminder: your vote on '{motion}' is due soon" with a deep link to the voting round
- AND participants who already voted MUST NOT be notified
- AND a second job run MUST NOT send the reminder again (round is stamped `deadlineReminderSentAt`)
