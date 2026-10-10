---
kind: code
depends_on: []
---

# Proposal: live-recording-jump-to-item

## Summary

The transcript is aligned to agenda items, but there is no player and no way to jump to the moment an item was discussed. This change adds a player to the meeting Transcription widget and a jump link per agenda item.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### liv-08, jump from an agenda item to the moment it was discussed in the recording

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/TranscriptionService.php:344 align() maps transcript segments to agenda items; src/components/tabs/MeetingTranscriptionTab.vue:130-146 renders segment TEXT grouped per agenda item, no player, timestamps or seek link

Matrix note, verbatim:

> Agenda alignment of transcript text exists; there is no media player or jump-to-timestamp.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/raadplegen-digitaal-verslag states clicking an agenda item or speaker jumps the player to that moment
- ibabs: https://support.ibabs.com/docs/ibabs-stream-release-notes-januari-2026.md states viewers navigate between topics in the Player agenda panel to jump to that moment
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/webcasting/ offers agenda item markers (agendapuntmarkeringen) and speaker markers in the webcast; https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._av-koppeling/ states agenda markers are managed from one place

## Why

Rated yes by three competitors.

## What is built today

- TranscriptionService::align() maps transcript segments to agenda items; the widget shows text per item.

## What changes

1. The Transcription widget plays the meeting recording (the transcription source file) with an HTML audio or video element.
2. Each agenda item heading gets Play from here, seeking to the first aligned segment's start time.
3. AgendaItemDetail shows a link to the moment in the recording.

## Out of scope

- Public webcast player (live-public-livestream).
