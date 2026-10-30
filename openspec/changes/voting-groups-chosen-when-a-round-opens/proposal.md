---
kind: code
---

# Proposal: voting-groups-chosen-when-a-round-opens

## Summary

The secretariat already keeps voting groups (the whole council, the presidium, a committee) on the admin page Stemgroepen, as `VotingGroup` objects per body. The server already accepts a preset list of participants when a round opens and leaves out anyone without an active membership. What is missing is the step in between: the chair cannot pick a voting group when opening a round, as DcStemronde draws it ("Wie mag stemmen ... of kies een vaste stemgroep"). This change adds that choice and amends the spec to the register-backed groups that were built.

## Motivation

`specs/voting-group-presets` still describes presets stored in `IAppConfig`. The build (#1776, register fragment 127) chose a `voting-group` schema instead, which is the right home (ADR-022), so the spec follows the build. `VotingRoundOpener::open()` takes `presetParticipantIds` and splits them into eligible and excluded (REQ-VGP-003), but a voting group holds person ids, and nothing turns a group into the meeting's participants. `src/components/VotingRoundPanel.vue` offers no group at all.

## Scope

### In scope

1. REQ-VGP-001 and REQ-VGP-002 amended to the `voting-group` schema and the open-round panel.
2. The open request accepts a voting group; the server resolves its persons to the meeting's participants.
3. The panel offers "all members of the body" (the default) or one of the body's active voting groups, and shows who was left out.

### Out of scope

- Editing groups from the round panel; that stays on the admin page.

## Matrix rows

- vot-25: Restrict a vote to a fixed group of members, chosen from a preset when the vote is opened.
