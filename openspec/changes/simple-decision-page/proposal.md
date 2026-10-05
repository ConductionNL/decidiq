---
kind: code
---

# Proposal: simple-decision-page

## Summary

In the simple structure, the decision page shows where a decision stands and what the next step is. The state is a pill and a step bar. The transition that leaves the state is the one primary button in the header. A what-now card lists what the state still needs. The fifteen blocks sit behind five tabs and More. The motion page gets the same header.

The full structure keeps today's pages, unchanged.

This is the second of three changes that bring the Zuiddrecht design to decidiq. It builds on `simple-structure-profile`.

## Motivation

A decision page is one long scroll of fifteen blocks today, with no tabs and no header actions. The buttons that move a decision (Propose, Start deliberation, Open voting, Record decision, Enact, Archive) are inside the Lifecycle block, third on the page. The design (`Acties.dc.html`, `Gaps.dc.html`) asks for one next-step button, a checklist that explains it, and tabs.

## What changes

| Part | In the simple structure |
| --- | --- |
| State | `lifecycle` is the stage. A pill above the title and a seven-step bar show it. |
| Next step | One primary button per state: Propose, Start deliberation, Open voting, Record decision, Enact, and Publish on an enacted decision that is not public. |
| What now | A card with a checklist per state, read from fields of the decision. |
| Quick actions | Archive (on a decided or enacted decision) and Discussion and integrations. |
| Side column | Where it is decided (body, meeting, agenda item, proposer) and Dates. |
| Tabs | Content, Route and voting, Documents, Consultation, Publication. Under More: Action items, Commitments, Related decisions, Lifecycle. |

## Who may do what does not change

The header buttons post to the endpoint the Lifecycle block posts to (`POST /api/decisions/{id}/transition`). `DecisionLifecycleService` checks the transition map, the chair-only transitions and the quorum there, as it does today. The page sends the action and nothing else. A step somebody may not take is refused by the server, and the refusal is shown.

The Lifecycle block stays on the page, as the last entry under More. It still lists the transitions the server offers this person, marks the chair-only ones, and shows the publish prompt of a body that asks for one.

## What the design asks for and decidiq does not have

- **Add document and add action item as quick actions.** Both exist inside their own blocks (Documents, Action items). The library has no header action that opens a block's own add dialog, and no endpoint is invented. The quick actions are Archive and the discussion and integrations page.
- **A publish prompt after Enact from the header.** The Lifecycle block asks "publish now?" after Enact when the body is set to prompt. A header button cannot open that prompt. Instead, an enacted decision that is not public gets Publish as its next step, for every body.
- **Record decision from deliberation.** A body that may decide without a vote can do so from the Lifecycle block. The header offers Open voting in that state.
- **Counts on tabs.** A decision carries no count of its documents, consultations or action items.
- **Paraaf (initials) as a step.** decidiq has approval routes, shown in the Route block. They are not a state of the decision, so they are not a step in the bar.

## The motion page

A motion is a decision. Its page gets the pill, the next-step button and the what-now card. The buttons post to the endpoint the Stage block uses (`POST /api/motions/{id}/transition`) with the words that block sends. Voting has two ways out: Record as adopted is the button, Record as rejected sits beside it. The motion's blocks are not regrouped into tabs.

## A card never stands without a button

Publish hides once a decision is public. Archive is pinned beside the primary button on a decided or enacted decision, so a public enacted decision still has its next step in the header. The spec checks every state and every publication state.

## Not in this change

- The meeting page. It is not done.
- The amendment page.

## Affected projects

- [x] Project: `decidiq`

## Risks

- **Nothing here was checked in a browser.** The overlay is checked name by name against the schema, the server's transition map, the routes, the registry and the library's own resolvers and validator. How it renders needs the live check.
- **The six custom blocks are mounted by a tab instead of by the page's slot.** They receive the same `objectId`. They also receive the props a tab passes to every widget, which they do not declare.
