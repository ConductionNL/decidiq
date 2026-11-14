---
kind: code
---

# Proposal: simple-list-and-dashboard

## Summary

In the simple structure, the dashboard opens with the day: a greeting, an attention card, four counters, the proposals per step, what waits for you, the coming meetings and the open commitments by deadline. The proposals list and the decisions list get views with counts. The full structure keeps today's pages.

This is the third of three changes that bring the Zuiddrecht design to decidiq. It builds on `simple-structure-profile` and `simple-decision-page`.

## What the design shows and what decidiq has

| Design (`DcDashboard.dc.html`) | Built from |
| --- | --- |
| Vandaag eerst: a proposal waits for your paraaf | An attention card when decisions are open for voting. It opens Decisions on the Voting view. |
| Voorstellen onderweg | The existing Decisions counter. |
| Wacht op mijn paraaf | The existing Pending votes counter. |
| Vergaderingen deze week | The existing Upcoming meetings counter. |
| Open toezeggingen | The existing Open action items counter, with Commitments over deadline one row down. |
| Voorstellen per stap (Concept, Advies en paraaf, College, Raad) | One bar of the proposals grouped by their state: Draft, Proposed, Deliberating, Voting, Decided, Enacted. |
| Wacht op u, with the action | The existing Pending votes list and My action items list. |
| Komende vergaderingen | The existing Upcoming meetings list. |
| Toezeggingen met termijn | A new list of open commitments, earliest deadline first, late ones in red. |

## What was mapped, and what is left out

- **Paraaf (initials) is not a concept in decidiq.** What waits for a person is a vote or an action item. The card and the lists read those.
- **The route steps College and Raad are not states.** A proposal has a lifecycle state, and the bar groups by it. Which body decides is on the decision, not in its state.
- **"1 sluit vandaag", "2 van mijn team", "1 over de termijn"**, the small notes under the counters, are left out. Each is a count over a date range or a person, and OpenRegister's aggregation endpoint is only known to answer plain equality (see the note on the Commitments over deadline counter). Lateness is shown as a colour on each commitment's deadline instead.
- **The action per row in "Wacht op u"** (Paraferen, Advies geven) is not added. The existing lists open the decision, where the next-step button is.
- **The four counters are not replaced.** They are the app's own widgets, which count in the browser, and they already answer the design's four questions.

## Numbers agree with the lists they open

Every new number is a plain-equality filter on stored fields, and its link carries the same filter:

- the attention card counts decisions with `lifecycle = voting` and opens Decisions on `lifecycle=voting`, where the Voting view counts the same;
- the bar groups proposals (`decisionType = motion`) by `lifecycle`, and the views on the Proposals list count per `lifecycle` inside the same page filter;
- the commitments list shows `lifecycle = open`, and its "view all" opens Commitments on `lifecycle=open`, the Open view.

`tests/vitest/simpleListAndDashboard.spec.js` compares each pair.

## Lists

- **Voorstellen**: All proposals, Draft, Proposed, Deliberating, Voting in front, each with a count. Decided and Urgent behind the overflow chip.
- **Besluiten**: Decisions, Voting, Decided, Enacted, Urgent in front. Motions, Adopted and Rejected behind the chip. No view is dropped.
- Urgent decisions are now a view on both lists, as the design asks. The Urgent decisions page stays, with its ratification views.

## Not in this change

- No new field and no schema change, so no register import and no backfill.
- Mijn acties still opens the full action items list. A view on the signed-in person needs a filter on a person field with the `@me` token, which this change does not introduce.

## Affected projects

- [x] Project: `decidiq`

## Risks

- **Nothing was checked in a browser.** The layout is placed by arithmetic. The spec checks that no two cards share a cell and that the four counters stay four tiles on one row.
- The `banner`, `header` and `stacked-bar` widgets are new to decidiq. They come with nextcloud-vue 2.60.0.
