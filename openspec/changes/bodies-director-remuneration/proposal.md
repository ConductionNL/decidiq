---
kind: config
depends_on: []
---

# Proposal: bodies-director-remuneration

## Summary

A board, a supervisory board or an association's executive decides what its members are paid, and a public body has to disclose it (Wet normering topinkomens, WNT). decidiq records who holds which position on a body, but not what that position pays. This change adds a remuneration record per position holder and year: the fixed fee, the fee per meeting, the expense allowance, the decision that set it, and whether it is disclosed. The body page shows it to the secretariat, totals it per year, and publishes what is marked for disclosure.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### bod-17, manage what directors are paid

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "decidiq does not manage director pay. The only related field records whether an outside position is paid, deliberately without an amount."

No demand row (origin `competitor`).

No competitor rated yes.

The row is in decidiq's core area (bodies, the area of the first 30 rows), which is why it is built without a competitor rated yes or demand.

## Why

Remuneration is a governance decision: the general meeting sets the supervisory board's fees, the supervisory board sets the executive's, a council sets its own allowances by regulation. It belongs next to the positions it pays for, and the record of who decided it belongs to the decision. Today the only related field, `AncillaryPosition.remunerated`, records whether an outside position is paid and says on purpose that it carries no amount, because outside positions are published. A body's own mandates are a different record with a different audience.

## What changes

1. A new `MandateRemuneration` schema: the position hold it pays, the person and body, the year, the fixed annual fee, the fee per meeting, the expense allowance, the currency, the decision that set it, and whether it is disclosed with a publication date.
2. Only the secretariat and administrators read and write remuneration. The public reads a record once it is marked disclosed and its publication date has passed.
3. The governance body page gets a Remuneration widget with this year's records and a total per year.
4. The position hold page shows the remuneration of that hold.

## Out of scope

- Paying anyone. Payroll and payment are HR and finance work (humaniq and the organisation's own systems); decidiq records what was decided.
- Benchmarking pay against other organisations.
- Amounts on outside positions (`AncillaryPosition`), which stay deliberately without an amount.

## Risks

- Pay is sensitive. The read rule grants the secretariat and administrators only, and public read needs both `disclosed` and a past `publicationDate`, the same pattern outside positions use.
- A plain total mixes currencies if a body pays in two. The total tile filters on the body's currency (EUR by default), and a body that pays in two currencies reads the list.
