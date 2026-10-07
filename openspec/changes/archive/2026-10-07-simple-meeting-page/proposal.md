---
kind: code
---

# Proposal: simple-meeting-page

## Summary

In the simple structure, the meeting page shows where a meeting stands and what the next step is. The state is a pill and a four-step bar. The step that moves the meeting on is the one primary button in the header. A what-now card lists what the state still needs. The twenty-five blocks sit behind five tabs and More, the agenda first.

The full structure keeps today's page, unchanged.

This follows `simple-decision-page`, which did the same for a decision and left the meeting page out.

## Motivation

A meeting page is one long scroll of twenty-five blocks today. It has no tabs and no header actions. The buttons that move a meeting (Convene, Open, Pause, Resume, Adjourn, Close) are inside the Stage block, halfway down. A secretary who opens the page on the evening of the meeting has to find them.

## What changes

| Part | In the simple structure |
| --- | --- |
| State | `lifecycle` is the stage. A pill above the title and a four-step bar show it. |
| Next step | One primary button per state: Convene meeting, Open meeting, Close meeting, Resume meeting. A closed meeting has none. |
| What now | A card with a checklist for a draft, a convened and an open meeting, read from fields of the meeting. |
| Quick action | Discussion and integrations. |
| More | Every action in one group, Meeting. Close meeting sits there in the states where it is not the primary button. |
| Side column | One card, Key facts: date, end, location, room, mode, body, chair, quorum, opened, closed and cost. |
| Tabs | Agenda, Participants, Documents, Decisions, Minutes. Under More: Planning, Broadcast, Case system, Audit statements, Stage. |

## Who may do what does not change

The header buttons post to the endpoint the Stage block posts to (`POST /api/meetings/{id}/lifecycle`), with the action and nothing else. `MeetingController::lifecycle` lets the chair or the secretary of the meeting through, and an administrator. `MeetingService` then checks the transition table, the rules of the governance domain, the chair-only steps and the quorum. None of that moves.

A header button cannot know who is looking. So a member sees Open meeting too, and the server refuses it with its own message. To say so before anybody presses, the step bar asks the server which steps it offers this person (`GET /api/meetings/{id}/transitions`, the answer the Stage block draws its buttons from). When the next step is not among them it reads: "Only the chair or the secretary of this meeting can take the next step."

This is a difference with the Stage block, where a member sees no buttons at all (REQ-MSB-001). The Stage block is unchanged and still on the page. The library cannot hide a header action on an answer the server gives per record: a `visibleWhen` endpoint is a fixed address and does not take the record's id.

## Four of the six steps are buttons

Pause and adjourn are switched off per governance domain on the server (`WorkflowService::isTransitionAllowed`). Most domains allow neither. A manifest cannot know the domain of a meeting, so a Pause button in the header would be refused on most meetings. Those two steps stay in the Stage block, which draws exactly the steps the server offers. The card of an open meeting points there.

Convene, open, resume and close are never switched off by a domain. The spec reads the server's rules and fails when that stops being true.

## What the page does not get

- **A link to the live screen in the header.** The Open live meeting button is for the chair, the secretary and an administrator (REQ-AMP-004). It stays in the Agenda block, which asks the server who is looking. The Agenda block is the first tab.
- **A card for a paused, an adjourned or a closed meeting.** No field of a meeting says what a break still needs. A paused or adjourned meeting has its button and a sentence in the step bar. A closed meeting has no next step: its work is in the Minutes and Decisions tabs.
- **Counts on tabs.** A meeting carries no count of its documents, decisions or participants.
- **An admin-only group under More.** A meeting has no action that is for administrators only.

## The checklist is advice, with one exception

An unticked item does not stop the button. The server does not ask for a published agenda or a sent convocation before a meeting opens.

The quorum is the exception. Where the rules of the body ask for a quorum, the server refuses to open the meeting without one. The checklist item reads the same field the server reads (`quorumWith`) and its hint says so.

## Not in this change

- The amendment page and My actions. They are the next change.
- A live check in a browser.
