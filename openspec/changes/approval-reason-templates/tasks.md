# Tasks: ready-made reasons on the approval chain leaf

- [x] 1.1 `approvalChainLink.js`: `reasonTemplateOptions(raw)` maps the host's list into options,
  dropping entries without an id or a body; anything that is not a list is an empty offer.
  `tests/vitest/approvalChainReasonTemplates.spec.js`.
- [x] 1.2 `CnApprovalChainWidget.vue`: the `reasonTemplates` prop (fallback
  `integrationContext.reasonTemplates`), the picker above both reason fields, and `useTemplate()`
  filling the reason.
- [x] 1.3 "Start from a template" in `l10n/en.json` and `l10n/nl.json`, `.js` rebuilt.
