---
kind: code
depends_on: []
---

# Proposal: platform-microsoft-365-calendar-and-teams

## Summary

Many members read their agenda in Outlook and meet in Teams. This change puts
decidiq's meetings in Outlook by declaring the meeting dates for
OpenRegister's calendar feed, so a member subscribes once and every meeting,
moved or cancelled, follows. It lets the clerk create a Teams meeting for a
digital or hybrid meeting through integriq, and shows "Join in Microsoft Teams"
where members look. It also removes a call into OpenRegister that has never
run.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### plt-12, work from Microsoft 365, such as Outlook and Teams

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Demand origin
`competitor`, no originUrl.

Competitor cells rated yes, verbatim:

- ibabs = yes | evidence: https://support.ibabs.com/docs/ibabs-microsoft-entra-id-voorheen-azure-active-directory-office365.md states Entra ID login, user sync and OneDrive and SharePoint documents; https://support.ibabs.com/docs/integratie-met-outlook-en-google-agenda.md iCal
- diligent-boards = yes | evidence: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-director/board-resources/using-the-boards-app-in-microsoft-teams-bwd.htm describes the Boards app in Teams and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/adding-a-document-word-excel-powerpoint-bwa.htm an Office add-in

### pla-04, decidiq's half

The openregister lane specified the platform half of matrix row pla-04 as
openregister `openspec/changes/object-dates-as-a-calendar-feed`: a
subscribable `text/calendar` feed per schema and per saved view, resolved per
principal, with date kinds declared on the schema under
`configuration.calendarProvider.dates`. That change is built on openregister
`development` (`lib/Controller/CalendarFeedController.php`,
`GET /api/public/calendar-feeds/{token}.ics`).

decidiq's half: `lib/Service/AgendaService.php:135` calls
`CalendarEventService::updateMeetingEvent()` behind `method_exists()`.
OpenRegister's `CalendarEventService` has no such method
(`lib/Service/CalendarEventService.php` on openregister `development` has
`getEventsForObject`, `getAllUserEvents`, `createEvent`, `linkEvent`,
`unlinkEvent`, `deleteEvent` and `unlinkEventsForObject`), so the call never
runs. This change replaces it with declaring the meeting dates for the feed.

## Why

Outlook reads internet calendars. A feed generated from the meeting on every
refresh cannot go stale, where an event written into each member's calendar
does when a meeting moves. That is the reason openregister gives for a feed
over written events, and it is why the stub should go rather than be filled in.

Teams is where many organisations hold their digital and hybrid meetings. A
clerk who pastes a Teams link by hand into the location field loses it the
moment the location also needs the room's address.

## What changes

1. `Meeting` declares `configuration.calendarProvider` with `scheduledDate` as
   an `appointment` ending at `endDate`, titled with the meeting title and
   placed at `location`.
2. The dead `updateMeetingEvent()` call and the `CalendarEventService`
   dependency leave `AgendaService`.
3. "Subscribe in Outlook or on your phone" on the personal settings page mints
   an OpenRegister feed token for the member's meetings, shows the address to
   paste into Outlook, and revokes it.
4. `Meeting.teamsMeeting` (join address, external id, who created it, when),
   written by "Create a Teams meeting" on the meeting page through a
   `microsoft-365` integriq connection, or by pasting a join address.
5. The meeting page and the live meeting page show "Join in Microsoft Teams"
   when the meeting has one.

## Out of scope

- Signing in with a Microsoft account (Entra ID) and user sync. That is
  Nextcloud's own (`user_oidc`), works for every app, and needs nothing from
  decidiq. The feature docs say so.
- OneDrive and SharePoint documents. Writing the meeting file to a document
  store is `platform-case-system-document-exchange`.
- A decidiq app inside Teams and an Office add-in.
- Writing events into members' calendars. openregister's feed is read-only by
  design.

## Risks

- **A feed address that leaks.** The feed answers only what its principal may
  read and the token is revocable; the settings section shows the token's
  creation date and a revoke button.
- **Teams without integriq.** Without a linked `microsoft-365` source the
  create button is not shown; pasting a join address still works.
