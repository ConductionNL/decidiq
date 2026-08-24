# Retrofit — relation-tab-ui

Describes the observed behavior of the nine relation-scoped sidebar tab
components (~83 methods) under a new `relation-tab-ui` capability as 5 new
REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- src/components/tabs/AgendaMotionsTab.vue
- src/components/tabs/MeetingAgendaTab.vue
- src/components/tabs/DecisionActionItemsTab.vue
- src/components/tabs/MotionAmendmentsTab.vue
- src/components/tabs/GovernanceBodyMembersTab.vue
- src/components/tabs/MeetingParticipantsTab.vue
- src/components/tabs/MotionVotesTab.vue
- src/components/tabs/AmendmentParentMotionTab.vue
- src/components/tabs/MinutesSignersTab.vue

## Approach

- For each tab: describe observed inputs (parent `objectId`), outputs (fetched
  relation collection, create/edit/delete/link/sign side effects), and the
  shared `useRelationStore` schema-slug resolution.
- Group the nine tabs by posture into 5 REQs (CRUD list, colour semantics,
  participant linking, read-only viewers, minutes signing) — one REQ per
  distinct observable behavior, not one per file.
- Draft REQs that match behavior, not aspiration. The lifecycle-color maps and
  the sign-now eligibility derivation are domain logic and are specified as
  capabilities, not presentation glue.

Source: openspec coverage report generated 2026-05-25 (gate-16 spec-coverage).
See the retrofit playbook.
