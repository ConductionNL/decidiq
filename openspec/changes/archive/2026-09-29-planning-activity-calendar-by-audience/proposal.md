---
kind: code
depends_on: [platform-microsoft-365-calendar-and-teams]
---

# Proposal: planning-activity-calendar-by-audience

## Summary

The meeting calendar already shows every meeting of every type, so a
werkbezoek or an information evening appears once it is entered as a meeting.
What it cannot do is answer "what is on for the executive" or "what is on for
residents". This change lets an administrator say which audiences each kind of
meeting is for, adds an audience filter and a body filter to the calendar, and
gives residents a public calendar of the activities staff publish.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### pla-16, one calendar of all council activities, filterable by audience

Name in the matrix: "Show all council activities, not only formal meetings, in
one calendar that can be filtered by audience such as council, executive, joint
arrangements or residents." Own rating `partial`, built.state `built`, owner
`ConductionNL/decidiq`.

Demand row: origin `tender`, originUrl
https://www.tenderned.nl/aankondigingen/overzicht/384605 (TenderNed 384605).

No competitor is rated yes. Every competitor cell is `partial`: notubiz, ibabs,
go-raadsinformatie, diligent-boards and openslides all show meetings in a
calendar, and none describes filtering by an audience such as residents or
joint arrangements.

The matrix note, verbatim: "One staff calendar shows every meeting of any
meeting type, so informal sessions can appear if they are entered as meetings.
It cannot be filtered by audience or body, and there is no calendar for
residents."

The missing half, as the lane decided: filtering by audience, and a calendar
residents can read.

## Why

A tender asked for it by name, and it is the half no competitor has. A griffie
plans raadsvergaderingen, commissies, werkbezoeken, the college's sessions and
the gemeenschappelijke regelingen it sits in. Staff want one calendar and a
filter, not five calendars. Residents want to know when they can come and
listen, without an account.

## What changes

1. `MeetingType.audiences`: an administrator marks each kind of meeting with
   one or more audiences: `council`, `executive`, `joint-arrangement`,
   `residents`, `staff`. It is data, so no supplier is needed to add a kind.
2. The meeting calendar gets an audience filter and a body filter, and fetches
   the visible month from the server with those filters, instead of the first
   500 meetings of all time.
3. A public activity entry: staff publish a public meeting to the residents'
   calendar through the existing publication flow, as a `Vergadering` payload
   with date, title, body, location and audiences. It needs no formal agenda,
   so an information evening can be published too.
4. An anonymous `publicCalendar` collection in decidiq's portaliq contribution
   over the published `Vergadering` payloads.
5. Staff can subscribe to a filtered calendar in Outlook or on a phone once
   `platform-microsoft-365-calendar-and-teams` declares the meeting dates for
   OpenRegister's calendar feed: a saved view with the audience filter is one
   feed.

## Out of scope

- An anonymous calendar feed (`.ics`) for residents. OpenRegister's feed is
  per principal today; its proposal names portaliq as the consumer of a
  narrower principal later.
- Retiring the custom calendar page. The page note already says it goes when
  the shared calendar view mode lands in nextcloud-vue.
- Activities that are not meetings, such as a Deck card or a Talk call. An
  activity is a meeting of a configured type.

## Risks

- **Every audience filter over one type list.** A meeting without a `type`
  (the deprecated `meetingType` enum) has no audiences. The calendar shows it
  under "No audience set" rather than hiding it.
- **Publishing by accident.** A public entry is only made when staff press
  "Publish to the public calendar", only for a meeting flagged `isPublic`, and
  it can be withdrawn like any publication.
