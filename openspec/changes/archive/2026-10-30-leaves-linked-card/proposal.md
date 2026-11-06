# leaves-linked-card

## Why

DqZaak lists what other apps hold for a case in one "Gekoppeld" side card. nextcloud-vue's `linked` card
(linked-integrations-card) mounts each leaf with `onSummary` and draws the rows. The decisions and approval-chain leaves
already draw their own library card (CnDetailCard), so they need no `chrome: "card"`; they need the summary.

## What changes

- Given `onSummary`, the decisions leaf draws nothing and reports its count, the noun "decisions", a row per decision
  linking to it, and "Open in decidiq".
- Given `onSummary`, the approval-chain leaf draws nothing and reports whether a route exists, the noun "sign-off route"
  and one row saying where the route stands.
- Nothing is reported while loading or after a failed read.
