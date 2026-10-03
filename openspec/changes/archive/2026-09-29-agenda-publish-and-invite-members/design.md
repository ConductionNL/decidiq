# Design: agenda-publish-and-invite-members

Read at decidiq development `759d044c`.

## What exists

| Piece | Where |
|---|---|
| Publish | `lib/Service/AgendaService.php:138` publishAgenda(), route `appinfo/routes.php:153` |
| Eligibility | `lib/Service/PublicationEligibilityService.php:348` reads convocationSentAt |
| Notices | `lib/Service/NotificationPreferenceService.php` dispatch(), `lib/Notification/Notifier.php` |
| Widget | `src/components/tabs/MeetingAgendaTab.vue` (chair tools from #1441) |

## Approach

1. Declare convocationSentAt on Meeting in a new register fragment; publishAgenda() writes it and drops the lifecycle side effect.
2. Dispatch agendaPublished through NotificationPreferenceService to each participant's nextcloudUserId with an email body listing items and an ICS.
3. MeetingAgendaTab gets the button, shown when my-roles answers chair or secretary.

## Declarative or imperative

Schema changes go in a new `lib/Settings/register.d/` fragment (ADR-037) and are validated against the real register fragment in a test. Imperative code only where the design names a service or listener that already carries the behaviour.

## Tests

- PHPUnit: AgendaServiceTest publish stamps convocationSentAt and dispatches to nextcloudUserId (red before); the Meeting fragment validates.
- vitest: the button calls the publish endpoint.

## As built (2026-09-29, read at development `6e1e78b7`)

- The lifecycle side effect was already gone (#1396, `agendaPublishedAt`, `agendaVersion`, `agendaVersions` in fragment 91), and `publishAgenda()` already told every active participant through NotificationPreferenceService (`agendaChanged`, subject `agenda_published` or `agenda_revised`). What was missing: the convocation date, the content of the invitation, and a button.
- Register fragment 100 declares `Meeting.convocationSentAt` (Meeting 1.7.0). OpenRegister stores only declared properties, so PublicationEligibilityService and the Publication widget could never see it. `publishAgenda()` sets it on the first publication and keeps it on a republish.
- `AgendaInvitation` (pure) builds the invitation text (When, Where, the numbered items in agenda order) and an RFC 5545 calendar file (UTC times, escaped text, lines folded at 75 octets). A published or revised agenda sends that text as the notice body. By email it also attaches `meeting.ics`: `NotificationPreferenceService::dispatch()` and `sendEmail()` take `attachments`, sent through `IMailer::createAttachment()`. The bell keeps its short title and link.
- The Publish agenda button sits on the meeting's Agenda widget for chair, secretary or admin (my-roles, the same guard the endpoint uses); the widget shows "Agenda published on ..." from `agendaPublishedAt`.
- Task 3 needed no code in PublicationEligibilityService: it already read `convocationSentAt`; the field now exists. The test runs the service on the meeting as `publishAgenda()` saves it.
