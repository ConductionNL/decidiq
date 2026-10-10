---
kind: code
depends_on: []
---

# Proposal: agenda-publish-and-invite-members

## Summary

Publishing an agenda has no working step: the public publish button waits on a convocation field nothing sets, and the member notification is API only. This change adds one Publish agenda action that sends members an invitation with the agenda and, for a public meeting, makes the agenda publicly publishable.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### age-05, publish the agenda so members and the public can see it

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> Meeting 'Publication' widget src/components/tabs/AgendaPublicationTab.vue -> PublicationActionsTab.vue:235-241 offers Publish for an agenda only when meeting.isPublic and convocationSentAt/convocationSent are set; lib/Service/PublicationEligibilityService.php:331-351 enforces the same; convocationSentAt/convocationSent are declared on no schema and written by no code (grep in lib, src, register: only these two readers); lib/Service/AgendaService.php:108 publishAgenda() (member notification) is API only, PUT/POST /api/agendas/{meetingId}/publish, not called from src/

Matrix note, verbatim:

> Members with access can read the agenda inside the app, but there is no working publish step. Public publication through OpenCatalogi is gated on a 'convocation sent' field nothing sets or declares, so the button never appears; the member-notifying publish is API only.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.notubiz.nl/onze-diensten/vergadermanagement states the right information is published and distributed; https://amsterdam.raadsinformatie.nl/vergadering/1528626 is a public agenda page
- ibabs: https://support.ibabs.com/docs/werken-met-agendas.md states changes are visible to participants after Publiceer; https://support.ibabs.com/docs/introductie-5.md states public agendas appear on the Publieksportaal
- openslides: source read at 4.3.4, not driven: openslides-backend/meta/collections/agenda_item.yml:17 type common, internal or hidden controls who sees each item, and meeting.yml:82 enable_anonymous (with organization.yml:67) opens the meeting to the public without login; the agenda list is routed at openslides-client/client/src/app/site/pages/meetings/pages/agenda/agenda-routing.module.ts:10.

### pla-05, send members an invitation with the agenda when a meeting is convened

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/AgendaService.php:108 publishAgenda() sends an 'agenda_published' Nextcloud notification per participant (:127-146, :186-194) but only via PUT/POST /api/agendas/{meetingId}/publish (appinfo/routes.php:153), which no frontend calls (grep 'api/agendas' under src/: none); the meeting 'publication' widget (src/components/tabs/AgendaPublicationTab.vue) publishes to OpenCatalogi instead; Meeting x-openregister-notifications meetingScheduled (on create, no agenda) is a declaration only

Matrix note, verbatim:

> No invitation with the agenda reaches members from the UI. The API path notifies users (taken from participant 'owner', not nextcloudUserId) and also flips the meeting to 'opened' as a side effect; it sends no agenda content or email.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/kennisclips/notificaties-in-politiek-portaal states an administrator sends a notification from the event to everyone who is member of the meeting, with a custom text such as all papers are ready
- ibabs: https://support.ibabs.com/docs/werken-met-agendas.md states a notification can be sent to invitees on publication; https://support.ibabs.com/docs/integratie-met-outlook-en-google-agenda.md adds the iCal invitation
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/publishing-and-hiding-books-bwa.htm and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/release_notes/release-notes-bwa.htm (Jan 23, 2025) state recipients are notified when the book with its agenda is published

## Why

age-05 and pla-05 are each rated yes by three competitors, and pla-05 is in the core area. Members today learn of a meeting by word of mouth.

## What is built today

- AgendaService::publishAgenda() notifies participants behind POST /api/agendas/{meetingId}/publish (no screen calls it).
- The Publication widget publishes to OpenCatalogi, gated on convocationSentAt which no schema declares.
- Agenda change notices reach members after publication (age-18, merged #1451).

## What changes

1. A Publish agenda button on the meeting Agenda widget for chair and secretary calls the publish endpoint.
2. publishAgenda() stamps Meeting.convocationSentAt (declared in a register fragment) and no longer flips the stage to opened.
3. Each member gets a notification and, by their delivery choice, an email invitation with the meeting date, place and the agenda item titles, plus an iCal attachment.
4. Once convocationSentAt is set, the Publication widget offers public publication for a public meeting.

## Out of scope

- Publishing the papers themselves (publication-papers-and-search).
