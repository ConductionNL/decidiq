---
kind: code
depends_on: [live-public-livestream]
---

# Proposal: planning-parallel-sessions

## Summary

Many councils run one evening as several sessions at once: three committee rooms, each with its own agenda, chair and camera. decidiq can create three meetings on the same evening, but nothing ties them together or shows them side by side. This change lets a clerk create an evening with parallel sessions, gives each session its own agenda, recording and subtitles because each session is a meeting, and shows the sessions side by side on the evening's page, in the calendar and to residents.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### pla-17, split one meeting evening into parallel sessions, each with its own agenda, recording and subtitles, shown side by side in one overview

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "A meeting has one agenda and no parent or child sessions. Two meetings on the same evening can be created, but nothing groups them or shows them side by side, and there is no recording or subtitle output."

Demand: origin `tender`, https://www.tenderned.nl/aankondigingen/overzicht/408309

Competitor cells rated yes:

- notubiz: "https://www.notubiz.nl/onze-diensten/gekoppelde-modules states parallel sessions are shown side by side on web and app"
- ibabs: "https://support.ibabs.com/docs/parallelle-sessies.md states parallel meetings under one parent meeting, each with its own location and streaming profile"

## Why

The tender behind this row (TenderNed 408309) asks for it, NotuBiz shows parallel sessions side by side, and iBabs keeps parallel meetings under one parent meeting, each with its own location and streaming profile. The iBabs model fits decidiq exactly: a session is a meeting, so it already has its own agenda items (`AgendaItem.meeting`), minutes, decisions, transcript and, once `live-public-livestream` lands, its own broadcast and subtitles. What is missing is the parent and the view across them.

## What changes

1. A meeting gets `parentMeeting`. A meeting with sessions is the evening; its sessions are meetings whose `parentMeeting` points at it.
2. On the evening's page a clerk adds sessions (title, body, room, chair, times), and a Sessions widget lists them side by side with their agendas, rooms, live status and links.
3. Each session keeps everything a meeting has: its own agenda, minutes, decisions, voting, transcript and broadcast with subtitles.
4. The meetings calendar shows an evening once, with its sessions grouped under it, and the Meetings list can filter on the evening.
5. When sessions are broadcast, each session's broadcast carries the evening's title, so the portal's list of live and recent meetings (from `live-public-livestream`) shows an evening's sessions together.

## Out of scope

- Moving agenda items between sessions in bulk. An item moves by changing its meeting, as today.
- Joint votes across sessions. A vote belongs to one session's meeting.
- Nested sessions. A session cannot have sessions of its own.

## Risks

- An evening with its own agenda items and sessions confuses readers. The evening may keep an opening and closing item; the Sessions widget shows the evening's own items above the sessions so the order of the night reads top to bottom.
- Quorum and attendance are per session. Nothing sums them across the evening, on purpose.
