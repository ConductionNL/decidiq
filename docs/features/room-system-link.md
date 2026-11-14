# The room system

Many council chambers have a conference and voting system: a desk unit per
seat with a microphone button and vote buttons. This page shows how to link
that system to decidiq, how to give each member their seat, and how to read
and apply again what the system sent.

## Link the room system

decidiq reaches the room system through integriq, never directly. integriq
speaks the delegate system's own protocol and stores what the room sends as
room system messages in decidiq's register.

1. Open integriq and go to **Connections**.
2. Find the decidiq connection **Room system** and link a source to it. The
   template `room-system` is offered first.
3. Add integriq's synchronisation user to the group
   `integriq-synchronisation`. Only that group may write room system
   messages; a member or a clerk cannot.

Until a source is linked, decidiq applies nothing the room sends. The meeting
page's **Room system** widget says "No room system is connected", and a
message that arrives anyway is kept as rejected with that reason.

## Give each member their seat

The room system names a desk unit, not a person. decidiq finds the member by
the seat recorded on them.

1. Open the member under the governance body, for example J. de Vries.
2. Fill in **Seat in the room system** with the number of their desk unit, for
   example 12, and save.

A seat belongs to one member of the meeting's body. When no member, or more
than one, has the seat, the message is rejected with "Seat 27 is not linked to
a member" or "Seat 30 is linked to more than one member".

## What the room sends

- **A speaker marking** becomes a speech on the member's engagement record for
  the meeting, with the microphone's start and end time as the room sent them.
- **A desk vote** is cast through the normal voting rules, as an in-person
  vote marked as cast through the room system. A closed round, a member who is
  not in the meeting, or a vote the rules refuse is rejected with the rule's
  own words. A secret ballot is never read from the desk units: "A secret
  ballot cannot be read from the desk units".
- The same message sent twice in one meeting is applied once.

## Read rejected messages and apply them again

1. Open the meeting and go to the **Room system** widget (in the simple
   layout, under **More**).
2. Rejected messages are listed first, each with its kind, seat, status and
   the reason.
3. Fix the cause, for example give seat 27 to P. Jansen, or open the voting
   round, and choose **Apply again**.

Only the meeting's chair or secretary sees the messages and may apply one
again. Anyone else gets 403 from `POST /api/room-system-messages/{id}/apply`.
