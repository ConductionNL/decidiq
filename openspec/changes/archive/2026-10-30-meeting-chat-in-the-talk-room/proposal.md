---
kind: spec
---

# Proposal: meeting-chat-in-the-talk-room

## Summary

Participants of a digital or hybrid meeting chat during the meeting in the meeting's Talk room. decidiq links that room from the meeting page (Join video call, REQ-MVC-001), and Talk's chat comes with it. The matrix row liv-13 cited `specs/discussion-via-talk-leaf` without a requirement that names the chat. This change adds that requirement for behaviour that is already built.

## Evidence

`src/components/tabs/MeetingVideoCallTab.vue` on MeetingDetail (`src/manifest.json`): on a digital or hybrid meeting the chair or secretary creates a Talk room with the body's members invited, or links one, through OpenRegister's Talk link (`src/utils/videoCall.js`); members get Join video call, which opens the room with its chat. An in-person meeting shows no room, and the live meeting screen has no chat of its own.

## Matrix rows

- liv-13: Chat with other participants during the meeting.
