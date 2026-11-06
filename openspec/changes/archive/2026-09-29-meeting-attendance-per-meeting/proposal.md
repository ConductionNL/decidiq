---
kind: code
depends_on: []
---

# Proposal: meeting-attendance-per-meeting

## Summary

Attendance is one value per person that each meeting overwrites, so there is no record of who attended which meeting. This change records attendance per meeting on the meeting's Participants widget: present, absent or sent apologies, with arrival and departure.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pla-09, record who attended, who was absent and who sent apologies

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/decidesk_register.json:1224 Participant.attendanceStatus present/absent/proxy/excused ('for the current or most recent meeting'); editable on ParticipantDetail (src/manifest.json:751, no menu entry); aggregated in MeetingsReport widgets meet-absent/meet-by-attendance; src/components/tabs/MeetingParticipantsTab.vue:224,240 links participants through a 'meetings' array that the Participant schema does not declare and records no attendance

Matrix note, verbatim:

> Attendance can be set as present, absent or excused on the (deprecated) Participant, and the report counts it. It is one value per person that each meeting overwrites, so there is no per-meeting attendance record; the meeting's own Participants widget does not record attendance.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/inrichting-2.md states attendance registration per agendatype, marking present and absent per agenda and per agenda item
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-add-ons/minutes/record-meeting-attendance.htm records Present, Attending and Apologies with full, partial, in-person or remote status

## Why

Core area (planning), rated yes by two competitors. Minutes, quorum and cost all need to know who was there.

## What is built today

- Participant.attendanceStatus present/absent/proxy/excused on the deprecated Participant, counted by the meetings report.

## What changes

1. An Attendance schema (meeting, person, status present, absent, excused, proxy, arrivedAt, leftAt).
2. The meeting Participants widget gets a status control per row and an Everyone present action.
3. The meetings report and the quorum check read Attendance for that meeting.

## Out of scope

- Presence from a conference system (live-room-conference-system-link).
