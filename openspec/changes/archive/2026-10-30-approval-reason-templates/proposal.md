# Proposal: ready-made reasons on the approval chain leaf

## Why

Dossiq offers content templates wherever a kind of text is written (its change
`starter-content-and-templates`, task 7.2, decision 156), and one kind is the approval reason:
"Akkoord, conform advies", "Terug: de motivering ontbreekt". The approval chain leaf runs from
decidiq's own bundle as a mount leaf, so a host app cannot slot a picker into it. Without a way
in, the offer exists in dossiq and is shown nowhere.

## What Changes

- `CnApprovalChainWidget` takes a `reasonTemplates` prop, `[{ id, name, body }]`, with
  `integrationContext.reasonTemplates` as fallback. When it is not empty, a "Start from a
  template" picker sits above the reason field, for acting yourself and for acting on behalf.
  Picking one fills the reason; the person can still edit it.
- decidiq does not read any app's template library: it shows what the host passes.

## Impact

- `src/integrations/CnApprovalChainWidget.vue`, `src/integrations/approvalChainLink.js`
- `tests/vitest/approvalChainReasonTemplates.spec.js`
- One new string, en and nl.
