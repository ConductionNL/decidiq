# Tasks: document-approval-chain-leaf

## Implementation tasks

### Task 1: Ad-hoc route from named users
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-008-a-route-can-be-held-from-named-users`
- **files**: `lib/Service/ApprovalRouteService.php`, `lib/Event/HoldApprovalRouteRequestedEvent.php`
- **acceptance_criteria**:
  - GIVEN three user ids and a subject WHEN `holdFor()` runs THEN a route with `origin: adhoc` and three stages in that order exists
  - GIVEN the event carries `actors[]` WHEN handled THEN the same route results
- [x] Implement
- [x] Test

### Task 2: Deadline split over the stages
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-009-one-deadline-splits-over-the-steps`
- **files**: `lib/Settings/register.d/*.json` (DecisionStage `dueAt`), `lib/Service/ApprovalRouteService.php`
- **acceptance_criteria**:
  - GIVEN a deadline 9 working days out and three stages WHEN held THEN `dueAt` falls on days 3, 6 and 9
  - GIVEN a stage past `dueAt` with no action WHEN the timeline renders THEN it is marked overdue
- [x] Implement
- [x] Test

### Task 3: The leaf
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-010-the-route-is-a-render-surface-leaf`
- **files**: `lib/Integration/ApprovalChainLeafListener.php`, `src/integrations/approvalChainLeaf.js`, `src/integrations/ApprovalChainTab.vue`, `src/integrations/ApprovalChainWidget.vue`
- **acceptance_criteria**:
  - GIVEN a host object with a route WHEN the widget renders THEN it shows the current step and, for the current actor, approve and reject
  - GIVEN the parity check WHEN run THEN both halves agree on id and render pair
- [x] Implement
- [x] Test (`tests/e2e/approval-chain-leaf.spec.ts`, plus the cross-layer parity
      test `tests/Unit/Listener/ApprovalChainLeafParityTest.php`)

### Task 4: A tab on decidesk-decisions
- **spec_ref**: `openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md#requirement-req-ar-011-the-decisions-leaf-names-its-tab`
- **files**: `src/integrations/registerDecisionsLeaf.js`, its PHP descriptor
- [x] Implement: ALREADY SATISFIED, measured rather than assumed, and nothing
      was changed.

      The requirement asks for a `tab` carrying a label and an icon. There is no
      such sub-object in the contract: `LeafDescriptor` has no `tab` parameter,
      and the host reads no such key. `CnObjectSidebar` renders one
      `NcAppSidebarTab` per registered provider and takes `:name` from
      `provider.label` and its icon from `provider.icon`, read straight off the
      descriptor (nextcloud-vue, `CnObjectSidebar.vue`, the registry-mode
      branch).

      Both halves of `decidesk-decisions` already declare those two fields:
      `label: t('decidiq', 'Besluitvorming')` with `icon: 'Gavel'` in
      `src/integrations/registerDecisionsLeaf.js`, and `LABEL_SOURCE` / `ICON`
      in `lib/Listener/RegisterDecisionsLeafListener.php`. So the outcome the
      scenario names, a sidebar showing decidiq's label rather than a fallback
      naming decidesk, holds today.

      Adding a `tab: { label, icon }` object would have been a key nothing
      reads, which is worse than no change: it looks like the requirement was
      met.
- [x] Test (`tests/e2e/decisions-leaf-tab.spec.ts`), which asserts the fact in
      the BROWSER rather than on the descriptor, because two halves agreeing
      with each other proves nothing about what a sidebar receives when the
      bundle did not build.

### Task 5: i18n and docs
- Dutch and English strings; `docs/features/approval-chain.md` with screenshots.
- [x] Implement: 23 source strings in `l10n/en.json`, all 23 translated in
      `l10n/nl.json`, and `docs/Features/approval-chain.md` written.
- [ ] Screenshots. They are captured by a journeydoc run against a live
      instance rather than written by hand, and this branch has no instance with
      a route on a document to capture. Recorded here rather than dropped.
