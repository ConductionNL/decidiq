# meeting-transcription Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [live-recording-jump-to-item](../../) (this delta)

## Purpose

The transcript is aligned to agenda items, but there is no player and no way to jump to the moment an item was discussed. Moves decidiq matrix rows liv-08 toward built.

## ADDED Requirements

### Requirement: REQ-LRJ-001 Jump to an item in the recording

The meeting Transcription widget SHALL play the recording and SHALL jump to the moment each agenda item started.

#### Scenario: A member replays a debate
- GIVEN the council meeting of 14 October was recorded and transcribed
- WHEN member Pieter presses Play from here on item Housing plan
- THEN the recording plays from the moment that item started
