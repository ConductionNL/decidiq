---
kind: code
depends_on: []
---

# Proposal: meeting-reminders-before-deadlines

## Summary

The notification settings offer meeting created and meeting reminder switches that no sender reads, and no reminder goes out before a submission deadline. This change sends those notices and a reminder before the submission deadline, each honouring the member's choice.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pla-06, remind people before a deadline, such as the last day to submit agenda items

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/BackgroundJob/VotingDeadlineReminderJob.php registered in appinfo/info.xml:126 and lib/Service/VotingDeadlineReminderService.php:145 reminds on VotingRound.votingDeadline, but nothing writes votingDeadline (grep in lib/Service/Voting*.php, VotingController, src: only readers); lib/Listener/SubmissionDeadlineListener.php (registered lib/AppInfo/Registrar/ObjectListenerRegistrar.php:136) rejects late motions but does not remind; Meeting meetingReminder in x-openregister-notifications is a declaration only

Matrix note, verbatim:

> No reminder before a submission deadline. A voting-deadline reminder job runs hourly but has nothing to act on because votingDeadline is never filled; the meeting reminder exists only as a register declaration.

No competitor cell is rated `yes`.

### plt-09, get notified about meetings, votes and deadlines, and choose which notifications I get

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/components/userSettings/NotificationPreferencesSection.vue:168-185 per-event toggles + deliveryMethod, saved via src/components/userSettings/userPreferences.js:189 /api/notification-preference; lib/Service/NotificationPreferenceService.php:372 dispatch() honours shouldNotify(); callers: lib/Service/VotingOpenedNotifier.php:144 (voting opened), lib/Service/ApprovalStageLapseService.php:476 (approval lapse, escalation and substitute notices, which pass the filter for everyone since lib/Service/NotificationPreferenceService.php ALWAYS_ON_EVENTS, #1395); lib/Service/VotingDeadlineReminderService.php:241 vote deadline reminder (job registered in appinfo/info.xml); grep meetingCreated/meetingReminder in lib finds only the validator and defaults

Matrix note, verbatim:

> Vote opened and vote deadline notifications are sent, and the vote-opened one honours the user's choice. The meeting created and meeting reminder toggles have no sender in decidiq. Direct Nextcloud notifications (MotionNotifier, AgendaService) are sent with app 'decidiq', but decidiq registers no INotifier, so they may never render.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/attenderingen-instellen states users choose modules, keywords, frequency and change types for alerts; https://www.notubiz.nl/onze-diensten/gekoppelde-modules push notifications
- ibabs: https://support.ibabs.com/docs/geavanceerde-mail-notificaties.md states each user chooses per agendatype and overview between direct mail or a daily bulk mail
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-ios/security-and-settings/managing-notifications.htm lets users toggle meeting reminders and pending approval reminders; https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-add-ons/minutes/disable-automated-notifications-for-actions.htm covers action notifications

## Why

pla-06 is in the core area; plt-09 is rated yes by three competitors. A switch that does nothing breaks trust in the others.

## What is built today

- Per-event toggles and delivery method saved per user; vote opened and vote deadline notices sent; agenda change notices (#1451); Notifier registered.

## What changes

1. A meetingCreated notice to the body's members when a meeting is scheduled.
2. A background job sends meetingReminder a set time (default 24 hours) before a meeting starts, once per meeting and member.
3. The same job reminds members of the submission deadline (Meeting.submissionDeadline) 48 hours before it, once.
4. The Notifier renders the three new subjects.

## Out of scope

- Reminding members who have not voted (vot-17, decided no).
- Daily digest (publication-subscriptions-and-daily-digest).
