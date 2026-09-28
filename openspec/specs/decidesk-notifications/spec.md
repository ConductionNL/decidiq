---
status: in-progress
openspec-changes:
  - urgent-decision-procedure
---

# decidesk-notifications Specification

## Purpose

@e2e exclude configuration-only spec: it declares `x-openregister-notifications` rules on schemas in `lib/Settings/decidesk_register.json` and has no page of its own. RegisterJsonTest::testNotificationTriggersUseCanonicalVocabulary pins every trigger in that file to OpenRegister's vocabulary, and gate-18 (notification-dialect) rejects the legacy dialect. Whether a rule actually fires is up to OpenRegister's notification engine, which no test in this repo drives.

## Requirements

### Requirement: Governance schemas MUST declare notifications in the verified engine dialect

`Meeting`, `ActionItem`, `Motion`, `Decision`, `PublicConsultation`, and `BudgetProposal` MUST declare `x-openregister-notifications` using only verified keys: `trigger.type`, `channels[]`, `recipients[]`, and inline `subject{nl,en}`.

#### Scenario: Meeting scheduled and reminder rules

- **GIVEN** the `Meeting` schema
- **WHEN** notifications are declared
- **THEN** a `created`-trigger rule notifies on a newly scheduled meeting
- **AND** a `scheduled`-trigger rule (intervalSec >= 60, filter on lifecycle) sends a reminder

### Requirement: Recipients for non-uid person fields MUST use object-acl/groups, not field

Because `ActionItem.assignee`, `Motion.proposer`, and `Participant` hold participant names / email strings rather than Nextcloud user IDs, rules for these MUST NOT use `kind:field` on those properties; they MUST route to `kind:object-acl` and `kind:groups`.

#### Scenario: Action item assigned routes to object-acl and a group

- **GIVEN** `ActionItem.assignee` is a participant name, not a uid
- **WHEN** the actionAssigned rule is declared
- **THEN** its `recipients` use `kind:object-acl` (permission manage) and `kind:groups`
- **AND** no `kind:field` recipient references `assignee`

### Requirement: Status-change rules MUST be approximated and deferrals documented

With no named lifecycle transition actions on the schemas, status-change rules MUST be expressed via `created` (object first appears in target state) or `scheduled` (filtered on lifecycle/status), and the precise lifecycle-entered form MUST be documented as deferred to `notification-updated-field-change-condition`.

#### Scenario: Motion submitted approximated by created

- **GIVEN** motions enter at lifecycle `submitted` and no `submit` transition action is defined
- **WHEN** the motionSubmitted rule is declared
- **THEN** it uses `trigger.type: "created"`
- **AND** the proposal's Caveats note the lifecycle-entered form is deferred

### Requirement: REQ-ACN-001 Every notice decidiq sends can be shown

The app SHALL register a Nextcloud notifier that prepares every notification sent with app `decidiq`: the agenda subjects `agenda_published`, `agenda_revised`, `agenda_revision_started` and `agenda_changed`, the motion subjects, `proxy_granted` and the generic `decidiq_message`. Each SHALL have a translated sentence and a link to the object it is about. The notifier SHALL refuse any other app and any unknown subject with `UnknownNotificationException`.

#### Scenario: A member sees that the agenda changed
- GIVEN the agenda of the council meeting of 14 October was published and council member Pieter takes part
- WHEN the griffier adds the item Motie vreemd aan de orde
- THEN Pieter's notification bell shows "The agenda of Raadsvergadering 14 oktober changed" and clicking it opens that meeting

#### Scenario: Another app's notice is not touched
- GIVEN a notification sent by OpenRegister
- WHEN Nextcloud asks decidiq's notifier to prepare it
- THEN the notifier declines and OpenRegister's own notifier handles it
@e2e exclude no screen: the declining happens between Nextcloud and the notifier; covered by tests/Unit/Notification/NotifierTest.php testAnotherAppIsDeclined

### Requirement: REQ-ACN-002 Preference-aware in-app notices are sent as decidiq

`NotificationPreferenceService::dispatch()` SHALL deliver its in-app channel as a decidiq notification through `OCP\Notification\IManager`, so that every caller of `dispatch()` reaches the bell without depending on a service outside decidiq.

#### Scenario: A vote opening reaches the bell
- GIVEN council member Aisha chose delivery in the app
- WHEN a voting round opens on a motion she can vote on
- THEN her bell shows the vote-opened notice
@e2e exclude the in-app channel every dispatch() caller shares is proven by tests/Unit/Service/NotificationPreferenceServiceTest.php testInAppIsSentAsADecidiqNotification; the bell rendering by tests/e2e/agenda-change-notices.spec.ts

### Requirement: REQ-ACN-003 Agenda notices follow the member's delivery choice

Agenda publication, revision and change notices SHALL be sent through the member's notification preferences under the event type `agendaChanged`: in the bell, by email, or both, and not at all when the member switched agenda changes off. The recipient SHALL be the participant's linked Nextcloud user (`nextcloudUserId`), falling back to `owner`.

#### Scenario: A member who reads email gets the change by email
- GIVEN council member Jan chose delivery by email
- WHEN the griffier moves an item on the published agenda
- THEN Jan receives an email that the agenda changed, with a link to the meeting
@e2e exclude a browser cannot read the member's mailbox; covered by tests/Unit/Service/NotificationPreferenceServiceTest.php testAnEmailReaderGetsTheAgendaChangeWithALink

#### Scenario: A member who switched agenda changes off
- GIVEN council member Els switched agenda changes off in her settings
- WHEN the agenda changes
- THEN Els receives no notice
@e2e exclude proving an absence in another user's bell is the unit test's job; covered by tests/Unit/Service/NotificationPreferenceServiceTest.php testAMemberWhoSwitchedAgendaChangesOffGetsNothing

### Requirement: REQ-ACN-004 A burst of agenda edits sends one notice

The app SHALL send a member at most one agenda change notice per meeting within five minutes, while still recording an agenda version for every change.

#### Scenario: Three quick edits
- GIVEN a published agenda
- WHEN the griffier edits three items within two minutes
- THEN three agenda versions are recorded and each member receives one notice
@e2e exclude the five minute window cannot be waited out in a browser run; covered by tests/Unit/Service/AgendaServiceTest.php testABurstOfEditsSendsOneNotice
