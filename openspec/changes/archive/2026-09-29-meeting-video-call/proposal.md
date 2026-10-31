---
kind: code
depends_on: []
---

# Proposal: meeting-video-call

## Summary

A meeting can be marked digital or hybrid, but its video call can only be attached on an integrations page nothing links to. This change puts a Video call widget on the meeting page that creates or links a Talk room and shows a Join button to members.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pla-08, hold a meeting online or hybrid with a video call attached to it

Own rating `partial`, built.state `building`, owner `nextcloud/spreed`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/decidesk_register.json:845 Meeting.meetingMode enum in-person/digital/hybrid, shown in the Planning widget (src/manifest.json:570) and as a badge column on the Meetings index; :936 virtualLocation is deprecated and hidden; the Talk widget exists only on MeetingIntegrations (src/manifest.json:628, mi-talk) and nothing links to /meetings/:id/integrations (grep 'MeetingIntegrations' under src/: only the page definition)

Matrix note, verbatim:

> A meeting can be marked digital or hybrid. Attaching a video call is only possible through a Talk integration widget on an integrations page that no button or menu entry links to, and the meeting's own online-location field is deprecated and hidden.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/ibabs-connect.md states iBabs Connect is opened from the agenda for a video session with camera, screen sharing and chat
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/blogs/webcasting_van_virtuele_commissie-_en_raadsvergaderingen/ states Mijn Vergaderingen includes video meetings and council and committee meetings can be held by video conference with public webcast
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/adding-conferencing-information-to-a-book-bwa.htm adds conference links to a book, https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-ios/additional-features/joining-virtual-meetings-from-boards-bios.htm launches Zoom meetings inside Boards, and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/linking-a-book-to-a-microsoft-teams-meeting-bwa.htm links a book to a Teams meeting
- openslides: source read at 4.3.4, not driven: openslides-backend/meta/collections/meeting.yml:67 jitsi_domain, jitsi_room_name and conference_show (line 90) attach a Jitsi call to the meeting, conference_stream_url (line 102) adds a livestream; the client joins it in openslides-client/client/src/app/site/pages/meetings/pages/interaction/modules/interaction-container/components/call/call.component.ts and interaction.service.ts:83. Hybrid meeting with video call.

## Why

Rated yes by four competitors and in the core area. The Talk widget already exists; it is simply out of reach.

## What is built today

- Meeting.meetingMode in-person, digital, hybrid in the Planning widget.
- A Talk integration widget on /meetings/:id/integrations (no link).

## What changes

1. MeetingDetail shows the Talk widget when meetingMode is digital or hybrid.
2. The widget creates a Talk room for the meeting with its participants, or links an existing one.
3. A Join video call button on the meeting page for participants.

## Out of scope

- Teams meetings (platform-microsoft-365-calendar-and-teams).
- Public livestream (live-public-livestream).
