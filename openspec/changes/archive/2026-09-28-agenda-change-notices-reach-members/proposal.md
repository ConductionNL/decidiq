---
kind: code
depends_on: []
---

# Proposal: agenda-change-notices-reach-members

## Summary

When a published agenda changes, decidiq already records a new agenda version and creates a notice for every active participant. The notice never reaches anyone. decidiq registers no notifier, so Nextcloud cannot show a notice sent as app `decidiq`, and the agenda notice ignores the member's choice of email. This change makes the notice arrive: in the bell, by email, or both, as each member chose.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### age-18, notify members automatically when the agenda of a published meeting changes

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`. Matrix note: "Once an agenda is published, every change to its items records a new agenda version and notifies the meeting's active participants, and so do revising and republishing. The first publication is still API only, and the notices may not render in the bell until decidiq registers a notifier (#1381)."

Demand: origin `changelog`, https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/release_notes/release-notes-bwa.htm

Competitor cells rated yes:

- notubiz: "https://www.kennisbank.notubiz.nl/handleidingen/attenderingen-instellen states alert profiles can cover meetings and agendas and fire on edited items, new items or all changes, by email; https://www.kennisbank.notubiz.nl/kennisclips/attenderingen-in-politiek-portaal shows selecting vergaderingen en agenda's"
- ibabs: "https://support.ibabs.com/docs/overview-of-the-settings-for-an-agenda.md states participants can get a notification on publication or when changes are made to the agenda; https://support.ibabs.com/docs/integratie-met-outlook-en-google-agenda.md states a republished agenda mail carries the updated iCal"
- diligent-boards: "https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/release_notes/release-notes-bwa.htm (Dec 12, 2025) states admins can manage email notifications to users for auto-agenda updates with a new Send notification option"

## Why

The first half shipped today in decidiq#1406: every change to a published agenda records a version and calls `AgendaService::notifyParticipants()`. The second half is the one members notice. Issue decidiq#1381 traced it: `AgendaService::sendAgendaNotification()` (`lib/Service/AgendaService.php:175-194`) sends `setApp('decidiq')`, and nothing in `lib/` or `appinfo/` implements `OCP\Notification\INotifier`, so Nextcloud's `Manager::prepare()` finds no notifier and the notice cannot be rendered. NotuBiz, iBabs and Diligent all send agenda change alerts, by email where the member wants it.

This change closes the agenda half of decidiq#1381. Because the notifier it adds is decidiq's only one, motion and proxy notices (the other two senders #1381 names) start rendering too; their wording is specified here so they do not render as raw subject keys.

## What changes

1. decidiq registers a notifier that prepares every subject decidiq sends: the four agenda subjects, the motion subjects of `MotionNotifier`, and `proxy_granted`, each with a translated sentence and a link to the object.
2. Agenda notices go through `NotificationPreferenceService::dispatch()` with a new event type `agendaChanged`, so each member's delivery method (in the bell, email or both) and on or off switch apply.
3. `dispatch()`'s in-app channel creates a decidiq notification through `OCP\Notification\IManager` instead of asking the container for `OpenRegisterNotificationService`, a service that exists nowhere (decidiq#449, #1381).
4. Recipients are taken from the participant's `nextcloudUserId`, falling back to `owner` for records made before that field, as `ParticipantResolver` already does for roles.
5. The user settings page gets a toggle for agenda changes.

## Out of scope

- Publishing an agenda from a screen (matrix row age-05, decidiq#1384).
- The meeting created and meeting reminder toggles that no sender reads (decidiq#1381 point 3, matrix row pla-06).
- Residents and press subscribing to agendas: that is `publication-subscriptions-and-daily-digest` (row pub-16).

## Risks

- A burst of edits to a published agenda could send many notices. `notifyAgendaChanged()` already sends one notice per reorder; the change keeps that and adds a five minute coalescing window per meeting and member, so ten quick edits send one notice.
- A notifier that throws breaks the whole bell. The notifier throws `UnknownNotificationException` for any app other than decidiq and for unknown subjects, as Nextcloud requires, and never anything else.
