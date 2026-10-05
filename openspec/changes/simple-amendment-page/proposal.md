---
kind: code
---

# Proposal: simple-amendment-page

## Summary

In the simple structure, the amendment page shows where an amendment stands and what the next step is: a pill, a five-step bar, one primary button for the two steps before the vote, a what-now card, one side card and three tabs. My actions opens on the reader's own action items.

The full structure keeps both pages as they are.

This follows `simple-meeting-page`.

## Motivation

**The amendment page** has four blocks and no way to move an amendment. The server has a guarded endpoint for it (`POST /api/amendments/{id}/transition`) and no control on any page calls it. A vote on an amendment can only open once the amendment is in deliberation, so an amendment written on the page could not reach its vote from the page.

**My actions** is the second entry of the simple menu. It opens the list of every action item of the organisation.

## The amendment page

| Part | In the simple structure |
| --- | --- |
| State | `lifecycle` is the stage. A pill above the title and a five-step bar show it. |
| Next step | Submit amendment on a draft, Start the debate on a proposed amendment. |
| The vote | Not a header button. It opens and closes in the Voting round tab. The step bar says so. |
| What now | A card for a draft and for a proposed amendment. |
| Side column | One card, Key facts: type, the motion it changes, proposer, submitted, voting order, result and decision date. |
| Tabs | Text changes, Amendment (the fields and the parent motion), Voting round. |

### Why the vote is not a button

The transition endpoint accepts `voting` and `decided` too. Taking those steps there skips two things the server does when a round opens and closes:

- `VotingRoundOpener` holds the voting order of the amendments. An amendment that is out of turn is refused.
- `VotingRoundCloser` writes an adopted amendment into the text of its motion.

A header button on the plain endpoint would open a vote with no round, out of order, and record a result that never reaches the motion. So the header stops at the debate, and the Voting round block takes over, unchanged.

### Who may do what does not change

The two buttons send `{ newState }` and nothing else. The server keeps both steps for the chair group, or for an administrator where no group is set (`MotionController::amendmentTransition`). No endpoint tells a page beforehand whether the reader is one of them, so the step bar says "Only the chair or the secretary can take the next step." to every reader on a draft and on a proposed amendment. Anybody else who presses gets the server's refusal as a message.

### The checklist is advice

The server asks for none of the fields before a step.

### What the page does not get

- **A quick action.** A link to the motion is in the Parent motion block, which reads both spellings of that link (`amends` and the retired `parentMotion`). A header link could read one.
- **A card after the debate.** No field of an amendment says what the vote still needs.
- **An admin-only group under More.** An amendment has no action for administrators only.

## My actions

The list opens on the view Mine, which filters on `assignee`. That field holds a Nextcloud user id, and the dashboard's My action items widget filters on it too. The view Everyone has no filter and is the list as it was. Both views carry a count.

Only action items are on this page, before and after:

- **A vote that waits for somebody** has no record with a person on it until it is cast. A cast vote names a participant, and a participant names a user. A list filter cannot take two steps. Pending votes stay on the dashboard.
- **A commitment** names the member who made it by a record id, not by a user id. Commitments stay under their own menu entry, unfiltered.

### One number that does not match its list

The dashboard tile Overdue actions counts everybody's overdue action items and opens this list. It did not match the list before (the list showed every action item, not the overdue ones). It now lands on Mine. The tile is one component for both structures and is not changed here.

## Not in this change

- A live check in a browser.
