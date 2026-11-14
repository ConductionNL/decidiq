# Design: planning-activity-calendar-by-audience

Kind: code. One schema field, one calendar view change, one new publication
source type, one portal collection. Read against decidiq `development` at
4d7430ff.

## What exists today

- **The calendar page.** `src/manifest.json:546` `MeetingsCalendar`
  (`/meetings/calendar`, `type: custom`, component `MeetingCalendarView`).
  `src/views/meetings/MeetingCalendarView.vue:274` `load()` calls
  `getMeetings({ _limit: 500 })` (`src/services/dashboardData.js:24`) once and
  places meetings client-side by `scheduledDate`. Its only filter (`:204`
  `undated()`) lists meetings without a date. There is no audience or body
  control.
- **Meeting types.** `lib/Settings/register.d/70-configurable-types.json:12`
  `MeetingType` (slug `meeting-type`): name, body, agenda item types, quorum
  and voting defaults, `isPublic`, validity and `active`. No audience.
- **The meeting.** `lib/Settings/decidesk_register.json:757`, with
  `governanceBody` (`:813`), `scheduledDate`, `endDate`, `location` and
  `isPublic` (`:953`); `type` references `meeting-type`
  (`70-configurable-types.json`, Meeting patch).
- **Bodies.** `GovernanceBody.bodyType` holds `legislative`, `executive-board`,
  `shared-body` and more (`lib/Settings/decidesk_register.json:528`).
- **Publication.** `PublicationEligibilityService::assertEligible()`
  (`lib/Service/PublicationEligibilityService.php:284`) knows `decision`,
  `agenda` and `minutes`. `PublicationPayloadService::build()`
  (`lib/Service/PublicationPayloadService.php:71`) builds a payload per type.
  `PublicationRecord.sourceType` enumerates the same three.
  `PublicationPayload` (`oriType` `Vergadering`, `meetingDate`, `meetingType`,
  `bodyName`) is anonymously readable once `publicationDate <= $now`.
  Publish, withdraw and rectify are `appinfo/routes.php:49` to `:51`.
- **Portal.** `lib/Portal/PortalContributionProvider.php:219`
  `citizenCollections()`, none anonymous today.

## D1. Audience belongs to the kind of meeting

`MeetingType.audiences`: an array of enum `council`, `executive`,
`joint-arrangement`, `residents`, `staff`, `facetable`. A patch in a new
fragment `lib/Settings/register.d/109-activity-calendar.json`.

Why on the type and not on each meeting: the audience of a commissievergadering
does not change per sitting, and a single source is what a filter can trust.
Why not derived from `GovernanceBody.bodyType`: an information evening for
residents is organised by the council, so the body says who organises, not who
it is for.

## D2. The calendar filters on the server

`MeetingCalendarView` gains two `NcSelect` controls with `inputLabel`:
"Audience" and "Body". `load()` asks for the visible month only
(`scheduledDate` between the first and last cell of the grid) and, when an
audience is chosen, `type` in the set of meeting types whose `audiences` hold
it (the type list is small and fetched once). The body filter passes
`governanceBody`. The chosen filters live in the route query, so a filtered
calendar can be bookmarked and shared.

Meetings without a `type` stay visible under "No audience set" when no
audience filter is on, and under that explicit option when it is chosen.

This also removes the 500 cap: a month is bounded, all of time is not
(ADR-058).

## D3. A public activity entry reuses the publication flow

A new source type `activity`:

- `PublicationEligibilityService::assertEligible()` gets
  `assertActivityEligible()`: the meeting is `isPublic`. No convocation is
  needed, which is the difference from `agenda`.
- `PublicationPayloadService::build()` gets `buildActivityPayload()`:
  `oriType: Vergadering`, `title`, `bodyName`, `meetingDate`, `meetingType`
  (the type's name), and two new allow-listed payload fields, `location` and
  `audiences`. No participant, no agenda item, no UID.
- `PublicationRecord.sourceType` gains `activity`.
- The Publication widget on `MeetingDetail` (`AgendaPublicationTab`) offers
  "Publish to the public calendar" next to agenda publication.

Publishing the agenda of the same meeting later creates the agenda payload as
today. The two payloads describe the same meeting; the portal collection sorts
by `meetingDate` and shows both, the agenda one with its items.

## D4. Residents read it through portaliq

`citizenCollections()` gains:

```
{ id: 'publicCalendar', register: 'decidiq', schema: 'publication-payload',
  anonymous: true, listable: true, label: 'Council calendar',
  fields: ['title', 'bodyName', 'meetingDate', 'meetingType', 'location',
           'audiences', 'oriType'],
  defaultFilters: { oriType: 'Vergadering' },
  defaultSort: { field: 'meetingDate', direction: 'asc' } }
```

Every row of `publication-payload` is already public once published, so a
resident who clears the default filter sees published decisions and minutes
too, which are public anyway. The whitelist holds only calendar fields.

## D5. Staff feeds come from OpenRegister

`platform-microsoft-365-calendar-and-teams` declares `scheduledDate` and
`endDate` of `meeting` for OpenRegister's calendar feed. A saved view over
`meeting` with the audience filter is then a feed a staff member subscribes to
from Outlook or a phone (openregister `object-dates-as-a-calendar-feed`,
`POST /api/calendar-feeds` with `scopeType` view). This change adds nothing
for it beyond the filterable field.

## Declarative or imperative

- The audience field and its facet: declared.
- The calendar filter: frontend over the OpenRegister object API (ADR-022).
- The activity payload: imperative, inside the existing
  `PublicationPayloadService`, because the allow-list is PHP today and a public
  payload is built allow-list style on purpose.
- The portal collection: declared contribution (ADR-046).

## Seed data

In `lib/Settings/profiles/municipality.json`:

1. Meeting type "Raadsvergadering", `audiences: [council, residents]`.
2. Meeting type "Collegevergadering", `audiences: [executive]`.
3. Meeting type "Informatieavond", `audiences: [residents, council]`, with one
   public meeting "Informatieavond windpark Noord" on 8 April at "Dorpshuis
   De Linde", published as an activity.

## Risks

- A long list of meeting types makes the `type` filter long. The type list is
  bounded by what an administrator creates; if it grows past a page, the
  filter moves to a materialised field on the meeting in a later change.
