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
