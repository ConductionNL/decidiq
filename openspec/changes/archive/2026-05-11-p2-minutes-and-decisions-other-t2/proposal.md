<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: p2-minutes-and-decisions (Minutes and Decisions)
     This spec extends the existing `p2-minutes-and-decisions` capability. Do NOT define new entities or build new CRUD — reuse what `p2-minutes-and-decisions` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## Why

Once a decision is published, three problems consistently surface across governance bodies and management teams. First, decisions are static snapshots — when policy evolves and a council supersedes or amends an earlier decision, there is no way to follow the chain: which ruling replaced which, and why. Clerks manually add footnotes to Word documents while the system shows no link between objects. Second, publishing a decision in Decidesk does not reach the people who need to act on it: department heads, committee members, and external advisors learn of outcomes through ad-hoc email chains, hallway conversations, or by spotting the minutes on a shared drive days later. Third, translating a decision into departmental action is left to informal follow-up: managers create their own task lists outside the system, leaving no audit trail of who was expected to implement what.

This change closes all three gaps by building on the Decision and ActionItem foundations delivered in p2-minutes-and-decisions: decisions can be linked to one another to expose the evolution chain, stakeholders can be notified in one explicit action, and decisions can be cascaded to departments as trackable ActionItems.

## What Changes

- **New**: Related decisions panel on the Decision detail page — displays decisions linked via OpenRegister built-in relations (amends, supersedes, replaces); relation type label is shown per linked decision
- **New**: "Koppel besluit" (Link decision) action on the Decision detail page — opens a picker to select a related decision and choose a relation type (amends / supersedes / replaces / is-superseded-by)
- **New**: Decision evolution chain view — a `CnTimelineStages`-style list of decisions linked in sequence, navigable from any decision in the chain
- **New**: "Betrokkenen informeren" (Notify stakeholders) action on published decisions — opens a participant picker, sends Nextcloud in-app notifications to selected participants with decision title, summary, and deep link
- **New**: Backend `DecisionNotificationService` — dispatches Nextcloud notifications via `NotificationService`; called by a thin controller; returns notification count
- **New**: "Cascaderen naar afdelingen" (Cascade to departments) action on published decisions — opens a department/governance-body picker; for each selection creates a linked ActionItem via `ObjectService::saveObject()` with the decision title as its title and `taskStatus: open`
- **New**: Dashboard KPI card — "Besluit-actiepunten open" (count of ActionItems created via cascade with `taskStatus: open` or `in-progress`)
- **Modified**: Decision detail view extended with three new `CnDetailCard` sections: Related Decisions, Notifications Sent, and Cascaded Action Items

## Capabilities

### New Capabilities

- `decision-evolution`: Link Decision objects to one another via OpenRegister built-in relations; display the relation type (amends, supersedes, replaces, is-superseded-by) in the Decision detail view; allow navigation along the evolution chain
- `decision-stakeholder-notification`: Explicit "Betrokkenen informeren" action on a published Decision that dispatches Nextcloud in-app notifications to selected Participants; notification body includes decision title, outcome, and a deep link back to the Decision detail page
- `decision-cascade`: "Cascaderen naar afdelingen" action on a published Decision that creates one ActionItem per selected governance body / department, each linked back to the source Decision; ActionItems are immediately visible in the Decision detail and in the ActionItems index

### Modified Capabilities

- `decision-recording` (from p2-minutes-and-decisions): Decision detail view extended with Related Decisions panel (fetchUses + fetchUsed OpenRegister relation queries), Notifications Sent indicator, and Cascaded Action Items table
- `action-item-tracking` (from p2-minutes-and-decisions): ActionItems created via cascade are pre-populated with the Decision title, a link to the source Decision, and `taskStatus: open`; they appear in the existing ActionItems index with no schema changes
- `p2-minutes-and-decisions`: extended by `p2-minutes-and-decisions-other-t2` — adds configuration, workflow, or seed data


## Impact

- No schema changes — Decision and ActionItem are already defined in ADR-000 and registered in `decidesk_register.json`; OpenRegister built-in relations handle decision-to-decision links
- Adds one PHP service class (`DecisionNotificationService`) and one PHP controller (`DecisionActionsController`) with three endpoints: link-decision, notify-stakeholders, cascade-to-departments
- Adds three Vue components / view extensions: relation picker modal, notification dispatcher modal, cascade picker modal — all reuse `CnFormDialog` or `NcDialog`
- Extends `src/views/DecisionDetail.vue` with three new `CnDetailCard` sections
- Extends `src/views/Dashboard.vue` with one new `CnStatsBlock` KPI card
- Downstream: `p3-ori-publication` benefits from the evolution chain when constructing the publication dossier
