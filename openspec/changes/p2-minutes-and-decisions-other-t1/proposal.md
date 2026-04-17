<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: p2-minutes-and-decisions (Minutes and Decisions)
     This spec extends the existing `p2-minutes-and-decisions` capability. Do NOT define new entities or build new CRUD — reuse what `p2-minutes-and-decisions` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## Why

Governance bodies — from municipal councils and water boards to corporate supervisory boards and associations — struggle with decision accountability, approval transparency, and follow-through. Market research across hundreds of tender documents and user stories reveals a concentrated cluster of unmet demand in the "other" feature category of the minutes-and-decisions domain.

The single highest-demand capability here is **a structured digital approval workflow for major decisions** (demand 293, 97 tender mentions). Boards currently rely on email chains and informal sign-off processes that leave no audit trail, miss quorum requirements, and create legal exposure when decisions later come under scrutiny. Following that: **decision analytics and funder reporting** (demand 145) — board secretaries and compliance officers cannot quickly answer "how many decisions were adopted this quarter, what were the outcomes, and which are still awaiting implementation?"; **review and approve strategic decisions** (demand 60) — multi-stakeholder sign-off before a major decision is formally adopted; **review outcomes of previous decisions** (demand 57) — tracking whether adopted decisions were actually implemented; **record and track steering committee decisions** (demand 45) and **track and follow up on action items from meetings** (demand 44); **automatic record creation from formal decisions** (demand 39, 13 tender mentions) — clerks waste time manually copying motion text into decision records after adoption; and **weekly email digest of upcoming decisions and deadlines** (demand 33, 11 tender mentions).

The Board Secretary / Company Secretary and Legal Counsel / Compliance Officer are the primary stakeholders: they need a complete, auditable decision lifecycle from initial draft through structured approval to publication, analytics visibility, automatic record generation, and periodic deadline reminders. Without these capabilities, boards govern through disconnected tools, miss deadlines, and cannot demonstrate compliance with the Awb, Woo, and Dutch Corporate Governance Code.

This change delivers the eight highest-demand T1 "other" capabilities on top of the p2-minutes-and-decisions and p2-minutes-and-decisions-core-t1 foundations.

## What Changes

- **New**: Multi-stage digital approval workflow for Decisions — lifecycle transitions through `legal-review`, `committee-review`, `board-approved`, and `board-rejected` states with role-gated transitions and automatic reviewer notifications
- **New**: Decision analytics dashboard — KPI cards (total decisions, adoption rate, pending approvals, overdue action items) plus trend charts (decisions by month, outcome distribution) powered by `DecisionAnalyticsController`
- **New**: Strategic decision review workflow — assign named reviewers to a Decision; each reviewer gets a Nextcloud notification and can mark their review approved or rejected with a note; all reviewer responses visible in the Decision detail
- **New**: Decision outcome tracking — implementation status tracked via built-in `tags` (`geimplementeerd`, `implementatie-lopend`, `implementatie-uitgesteld`) with `DecisionService::setOutcomeTag()` and `CnFacetSidebar` filter
- **New**: Auto-creation of Decision records from adopted Motions — `DecisionAutoRecordService::createFromAdoptedMotion()` triggered when a Motion lifecycle transitions to `adopted`; checks for existing linked Decision to prevent duplicates
- **New**: Enhanced action item follow-up dashboard widget — "Mijn actiepunten" widget on the analytics dashboard showing open and overdue action items assigned to the current user, grouped by meeting
- **New**: Weekly email digest — `DecisionDigestJob` background job runs every Monday at 09:00; sends one email per governance body to chair and secretary listing upcoming decision deadlines, overdue action items, and pending approvals; configurable opt-out per governance body

## Capabilities

### New Capabilities

- `decision-approval-workflow`: Multi-stage approval lifecycle for Decision objects (`draft → legal-review → committee-review → board-approved / board-rejected`); `DecisionApprovalService` validates role-gated transitions, sends `NotificationService` notifications to the next reviewer group when a state advances, and records every transition in `AuditTrailService`; approval action visible as `CnTimelineStages` in Decision detail
- `decision-analytics-dashboard`: `GET /api/decisions/analytics` endpoint provides aggregated stats; `DecisionAnalyticsDashboard.vue` page uses `CnDashboardPage` + `CnKpiGrid` (4 KPI cards) + `CnChartWidget` (bar chart: decisions by month last 12 months; donut: outcome distribution); filterable by GovernanceBody via query param
- `strategic-decision-review`: Reviewer assignment list stored via OpenRegister relations from Decision to Person objects; `POST /api/decisions/{id}/reviews` submits a reviewer sign-off with `approved` or `rejected` value and an optional note; `DecisionApprovalService::allReviewsComplete()` checks whether all required reviewers have signed off before allowing lifecycle advancement; reviewer panel in Decision detail
- `decision-outcome-tracking`: `DecisionService::setOutcomeTag(decisionId, tag, actorId)` adds or replaces an outcome tag on the Decision `tags` array; outcome tags: `geimplementeerd`, `implementatie-lopend`, `implementatie-uitgesteld`; `CnFacetSidebar` "Implementatiestatus" facet filter on Decision index; audit trail entry on every tag change
- `auto-decision-record-creation`: `DecisionAutoRecordService::createFromAdoptedMotion(string $motionId)` creates a Decision linked to the Motion with `title`, `text` (from `decisionText`), `decisionDate`, `outcome: adopted`, `legalBasis` populated from the Motion; idempotent — checks for existing linked Decision before creating; triggered from `MotionService` lifecycle hook
- `decision-weekly-digest`: `DecisionDigestJob` implements `TimedJob`, runs every Monday at 09:00; assembles a per-governance-body digest of: Decisions with `dueDate`-linked ActionItems in the next 14 days, overdue ActionItems linked to Decisions, Decisions in `legal-review` or `committee-review`; sends via `IMailer` to chair + secretary; opt-out configurable per governance body in admin settings

### Modified Capabilities

- `action-item-tracking` *(from p2-minutes-and-decisions)*: Extended with an "Mijn actiepunten" dashboard widget on the analytics dashboard and with escalation notifications when ActionItems transition to `overdue` — `NotificationService` alert sent to the assignee and to the secretary of the linked governance body
- `decision-publication` *(from p2-minutes-and-decisions)*: Extended with the approval workflow lifecycle — a Decision can only be published (`isPublished: true`) after reaching `board-approved` state; `DecisionApprovalService` enforces this gate
- `p2-minutes-and-decisions`: extended by `p2-minutes-and-decisions-other-t1` — adds configuration, workflow, or seed data


## Impact

- Adds `DecisionApprovalService.php` (transition validation, reviewer notifications, sign-off tracking), `DecisionAutoRecordService.php` (Motion adoption hook, duplicate prevention), `DecisionDigestJob.php` (weekly TimedJob, email assembly), `DecisionAnalyticsController.php` (aggregate stats endpoint) as the primary new PHP classes
- Extends `MotionService.php` from p2-motion-and-voting with a lifecycle hook calling `DecisionAutoRecordService::createFromAdoptedMotion()` when lifecycle transitions to `adopted`
- Extends `DecisionService.php` from p2-minutes-and-decisions-core-t1 with `setOutcomeTag()` and `assignReviewer()` methods
- No schema changes — Decision, Motion, ActionItem entities from ADR-000 are used as-is; approval states extend the existing `lifecycle` string field with new values; reviewer tracking uses OpenRegister relations (Decision → Person); outcome tags use the built-in `tags` array; no new entities; no ADR-000 update required
- Frontend: adds `DecisionAnalyticsDashboard.vue` (new route `/decisions/analytics`); adds `DecisionApprovalPanel.vue` embedded in `DecisionDetail.vue`; adds `DecisionReviewerPanel.vue` embedded in `DecisionDetail.vue`; extends `Decisions.vue` with outcome tag facets and approval state filter
- Downstream: p3-ori-publication benefits from auto-created Decision records with correctly populated `legalBasis`, `decisionDate`, and `outcome` fields; p3-governance-bodies can filter pending approvals per governance body
