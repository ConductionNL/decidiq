## ADDED Requirements

### Requirement: REQ-DISC-TALK-003 Participants chat in the meeting's Talk room

A digital or hybrid meeting SHALL give its participants a chat for the duration of the meeting: the Talk room linked to the meeting, with the body's members invited, opened from the meeting page with Join video call. decidiq SHALL NOT keep a chat of its own; the messages live in Talk. An in-person meeting SHALL show no room.

#### Scenario: A member chats during a hybrid meeting

- GIVEN a hybrid committee meeting with a linked Talk room
- WHEN member Sem opens the meeting page and presses Join video call
- THEN the Talk room opens with its chat
- AND the other members invited to the room read his message
