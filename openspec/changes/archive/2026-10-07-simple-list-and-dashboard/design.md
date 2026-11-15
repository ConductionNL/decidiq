# Design: simple-list-and-dashboard

All three pages get their simple shape from an overlay in `src/menu-layout.simple.json`. The manifest is not edited.

## Dashboard layout

New cards go on top and every old card moves down by a fixed number of rows. Width, height and column stay.

| Rows | Cards |
| --- | --- |
| 0 to 1 | greeting |
| 2 to 3 | attention card (hidden when nothing is open for voting) |
| 4 to 5 | the four counters (old row 0) |
| 6 to 7 | proposals per step |
| 8 to 9 | commitments over deadline, minutes awaiting approval (old row 2) |
| 10 to 13 | upcoming meetings, pending votes (old row 4) |
| 14 to 17 | commitments with a deadline |
| 18 onwards | running processes, my action items, recent decisions, governance health (old rows 8 and up) |

The shift is +4 for old row 0, +6 for old rows 2 to 7 and +10 from old row 8.

## Lists

`config.quickFilters` on the Motions page (it had none), `configPatch`, `configAppend` and `configOrder` on the Decisions page. `quickFilterMaxVisible: 5`. A view's filter merges into the page's own filter, so a Proposals view never leaves the proposals.
