# Design: platform-microsoft-365-calendar-and-teams

Kind: code. One schema configuration block, one removal, one settings
section, one Meeting field with a create action. Read against decidiq
`development` at 4d7430ff and openregister `development` at 555af72.

## What exists today

- **The stub.** `lib/Service/AgendaService.php:33` imports
  `OCA\OpenRegister\Service\CalendarEventService`, `:93` injects it, and
  `:135` to `:137` call `updateMeetingEvent()` behind `method_exists()`. The
  method does not exist in openregister, and the test stub
  (`tests/Stubs/OpenRegisterServices.php:32`) says so explicitly. The call has
  never run.
- **The feed in openregister.** `ObjectDateDeclaration`
  (openregister `lib/Service/Calendar/ObjectDateDeclaration.php`) reads
  `configuration.calendarProvider.dates`, keyed by property, each with `kind`
  (`deadline`, `appointment`, `period`), `summaryTemplate`, `endProperty`,
  `durationMinutes`, `alarmOffsetDays`. `Schema::getCalendarProviderConfig()`
  (openregister `lib/Db/Schema.php:2261`) returns nothing unless
  `calendarProvider.enabled` is true. `CalendarFeedController`
  (openregister `appinfo/routes.php:1024` to `:1027`) serves
  `GET /api/public/calendar-feeds/{token}.ics` and mints and revokes tokens at
  `/api/calendar-feeds` with `scopeType` `schema` or `view`.
- **Schema configuration in decidiq.** A register fragment may carry a
  schema-level `configuration` block
  (`lib/Settings/register.d/43-process-config-v1.json:13`), and openregister
  passes `calendarProvider` through (`lib/Db/Schema.php:2697`).
- **The meeting.** `lib/Settings/decidesk_register.json:757`: `title`,
  `scheduledDate` (`:824`), `endDate` (`:831`), `location` (`:838`),
  `meetingMode` (`:845`: `in-person`, `digital`, `hybrid`).
- **Personal settings.** `src/views/settings/UserSettingsPage.vue` renders four
  sections from `src/components/userSettings/`; the page is declared in
  `src/manifest.d/user-settings.json`. Spec: `openspec/specs/user-settings/spec.md`.
- **Online meetings today.** The Talk leaf on `MeetingIntegrations`
  (`src/manifest.json:638`). No Teams concept.

## D1. The meeting declares its dates

A new fragment `lib/Settings/register.d/92-meeting-calendar-feed.json` patches
`Meeting`:

```
"configuration": {
  "calendarProvider": {
    "enabled": true,
    "dtstart": "scheduledDate",
    "dtend": "endDate",
    "titleTemplate": "{title}",
    "locationField": "location",
    "dates": {
      "scheduledDate": {
        "kind": "appointment",
        "endProperty": "endDate",
        "durationMinutes": 120,
        "summaryTemplate": "{title}"
      }
    }
  }
}
```

`durationMinutes` covers a meeting without an `endDate`. `submissionDeadline`
is not declared: a `deadline` kind resolves through the term engine's working
calendar, and a motion deadline set by the clerk must publish on the date the
clerk set.

Once declared, the same meetings also appear in the member's Nextcloud
Calendar app through openregister's virtual calendar, at no extra cost.

## D2. The stub goes

`AgendaService` drops the import, the constructor parameter and the guarded
call. `lib/AppInfo` needs no change: the service is autowired. The feed
recomputes on read, so publishing an agenda needs no calendar write at all.

## D3. Subscribing from the personal settings

`CalendarSubscriptionSection.vue` in `src/components/userSettings/`, added to
`UserSettingsPage.vue`:

- lists the member's feed tokens for `meeting` from openregister
  `GET /api/calendar-feeds`;
- "Subscribe in Outlook or on your phone" posts `POST /api/calendar-feeds`
  with `scopeType: schema` and the `meeting` schema id, and shows the returned
  address with a copy button and one line on where to paste it in Outlook;
- "Stop this subscription" calls `DELETE /api/calendar-feeds/{id}`.

The calls go to openregister's API from the frontend (ADR-022). decidiq adds no
route.

## D4. A Teams meeting on the meeting

`Meeting.teamsMeeting` in the same fragment: an object with `joinUrl` (uri),
`externalId`, `createdBy`, `createdAt`. Separate from `location`, so a hybrid
meeting keeps the room's address there.

Two ways to fill it:

- **Create.** A `microsoft-365` connection in `lib/Settings/connections.json`.
  `TeamsMeetingService::create()` asks integriq's call service, against the
  linked source, for an online meeting with the meeting's title, start and end,
  and writes the answer. integriq holds the Microsoft Graph adapter; that is
  integriq's half, to be specified in integriq. Route
  `POST /api/meetings/{meetingId}/teams-meeting`, guarded by
  `TranscriptionStaffGuard::forMeeting()`
  (`lib/Service/TranscriptionStaffGuard.php:100`).
- **Paste.** The clerk pastes a join address in the Planning widget; the field
  accepts only `https://teams.microsoft.com/` addresses.

The Planning widget on `MeetingDetail` (`src/manifest.json:569`) includes
`teamsMeeting`. `LiveMeeting.vue` shows a "Join in Microsoft Teams" button when
`teamsMeeting.joinUrl` is set.

## Declarative or imperative

- The calendar dates: declared on the schema (openregister feed).
- The subscription section: frontend over openregister's API.
- The Teams field and its URL pattern: declared.
- Creating the Teams meeting: imperative, under the ADR-031 exception for
  external systems, through integriq.

## Seed data

In `lib/Settings/profiles/municipality.json`:

1. The digital committee meeting of 16 April with a `teamsMeeting` whose
   `joinUrl` is `https://teams.microsoft.com/l/meetup-join/00000000-0000-0000-0000-000000000000`.
2. The council meeting of 23 April, `meetingMode: hybrid`, `location`
   "Raadzaal, Stadhuis", no Teams meeting.

## Risks

- Outlook refreshes internet calendars on its own schedule, often every few
  hours. The settings text says so, so a member does not expect a moved
  meeting to show within minutes.
