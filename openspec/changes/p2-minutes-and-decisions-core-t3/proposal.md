<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: p2-minutes-and-decisions (Minutes and Decisions)
     This spec extends the existing `p2-minutes-and-decisions` capability. Do NOT define new entities or build new CRUD — reuse what `p2-minutes-and-decisions` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## Why

Governance bodies — from municipal councils and water boards to corporate boards and associations — depend on efficient day-to-day meeting workflows beyond the document-generation and compliance features delivered in T1. Market research across the p2-minutes-and-decisions spec reveals a second tier of high-demand operational capabilities: multi-meeting action item analytics (demand 94), live decision recording during active meetings (demand 73), ALV (Algemene Ledenvergadering / General Assembly) minutes draft and distribution (demand 64), formal minutes approval submission with notifications (demand 60), automatic action item extraction from minutes text (demand 55), structured decision rationale documentation (demand 48), and decision notification dispatch to stakeholders (demand 9).

The Board Secretary / Company Secretary is the primary persona: they need an analytics overview of open and overdue action items across multiple meetings, a live-entry panel to record decisions as they happen, an ALV-specific minutes template that satisfies association law requirements, a one-click approval request that notifies the chair and relevant members, and an automated step that converts meeting decisions into trackable action items. The CEO / Director needs decision notifications so that adopted resolutions reach accountable parties without manual email follow-up. The minutes clerk needs structured rationale fields so that the "why" behind each decision is captured at the source and auditable later.

This change delivers these seven operational extensions on top of the existing p2-minutes-and-decisions and T1 foundation: multi-meeting analytics dashboard, live meeting decision entry panel, ALV minutes template and member distribution, minutes approval request notifications, auto-extract action items from minutes content, decision rationale capture panel, and decision notification dispatch.

## What Changes

- **New**: Multi-meeting action item analytics panel on the Dashboard — `CnStatsBlock` KPIs (total open, overdue, completed this month, average days-to-close) computed via `ActionItemAnalyticsService`; a bar chart (`CnChartWidget`) shows per-meeting completion rates for the last 6 meetings; a "My Action Items" list groups overdue, due-this-week, and future items for the current user
- **New**: Live decision recording panel on the Meeting detail page — a "Decisions" tab that opens during an active meeting (`lifecycle: opened`) allowing the secretary to record decisions in real time with a quick-entry form; each saved entry creates a Decision object linked to the Meeting and auto-creates a draft Minutes entry if none exists
- **New**: ALV minutes template and member distribution — a "Genereer ALV-notulen" action on the Minutes detail page for Minutes linked to a meeting with `meetingType` containing `alv`; renders an ALV-specific Dutch template (date, quorum confirmation, agenda items, resolutions, AOB); a "Distribueren" action sends the approved minutes as a Nextcloud notification and optional email to all Participants of the linked GovernanceBody
- **New**: Minutes approval request with notifications — a "Ter goedkeuring indienen" button on a Minutes object in `draft` state transitions the lifecycle to `review` and sends Nextcloud notifications to all users with role `chair` or `secretary` in the linked GovernanceBody; the notification contains the minutes title, a deep link to the detail page, and an "Goedkeuren" shortcut
- **New**: Auto-extract action items from minutes text — a "Actiepunten extraheren" button on the Minutes detail page (available in `draft`, `review`, `approved` states) calls `ActionItemExtractionService::extractFromContent()` which parses the `content` field for action-item markers (lines starting with "Actie:", "AI:", "Taak:", or containing "wordt verzocht" / "zal") and presents a preview list of suggested ActionItem objects for the secretary to accept, edit, or reject before saving
- **New**: Decision rationale capture — a "Overwegingen" section on the Decision detail and edit form backed by a `rationale` rich-text field stored in the Decision `notes` array via OpenRegister built-in notes; the section is labelled "Overwegingen en motivering" and supports plain-text input; displayed on the Decision detail page and included in the minutes generation template
- **New**: Decision notification dispatch — a configurable `DecisionNotificationService` that sends Nextcloud notifications to configured recipients (role-based: chair, secretary, member) when a Decision's `isPublished` transitions from `false` to `true`; notification includes decision title, outcome, and a deep link; dispatched automatically as part of the existing publication action in `DecisionService`

## Capabilities

### New Capabilities

- `action-item-analytics`: Multi-meeting analytics for action items — KPI cards, per-meeting completion rate chart, and personal "My Action Items" grouping; powered by `ActionItemAnalyticsService` querying via `ObjectService.findAll()`; rendered with `CnStatsBlock`, `CnChartWidget`, and `CnObjectDataWidget` on the Dashboard
- `live-decision-recording`: Real-time decision entry during active meetings — a "Besluiten" tab on the Meeting detail page; quick-entry form creates linked Decision objects and auto-initialises draft Minutes; enabled only when `lifecycle: opened`
- `alv-minutes-template`: ALV-specific minutes template generation via `ALVMinutesService::generateALVDraft()`; Dutch quorum and resolution template; member distribution via `NotificationService` and optional Nextcloud email
- `minutes-approval-notifications`: Formal approval request workflow — `lifecycle` transition `draft → review` triggers Nextcloud notifications to chair and secretary roles via `NotificationService`; approval and rejection notifications on subsequent transitions
- `auto-action-item-extraction`: NLP-free extraction of action item candidates from Minutes `content` via `ActionItemExtractionService`; regex-based marker detection; preview-and-confirm modal before creating ActionItem objects; linked to parent Minutes via OpenRegister relation
- `decision-rationale`: Structured rationale capture using the OpenRegister built-in `notes` array with label `overwegingen`; displayed in the "Overwegingen" section on Decision detail; included in minutes generation template output
- `decision-notification`: Automatic notification dispatch on decision publication via `DecisionNotificationService`; configurable recipients by role; integrates with existing `decision-publication` capability from p2-minutes-and-decisions

### Modified Capabilities

- `decision-publication` *(from p2-minutes-and-decisions)*: Extended to trigger `DecisionNotificationService::notifyOnPublish()` after `isPublished` is set to `true`; notification dispatch is non-blocking (background activity)
- `minutes-generation` *(from p2-minutes-and-decisions)*: Extended to include `overwegingen` content from Decision notes in the generated minutes draft template; ALV-specific template branch added
- `app-dashboard` *(from p1-dashboard-and-navigation)*: Extended with action item analytics panel — `ActionItemAnalyticsWidget` replaces the simple open-count KPI card with full analytics breakdown and chart
- `p2-minutes-and-decisions`: extended by `p2-minutes-and-decisions-core-t3` — adds configuration, workflow, or seed data


## Impact

- Adds `ActionItemAnalyticsService.php` (cross-meeting analytics queries), `ALVMinutesService.php` (ALV template rendering and distribution), `ActionItemExtractionService.php` (content parsing for action items), and `DecisionNotificationService.php` (notification dispatch on publication)
- No schema changes — Decision, Minutes, ActionItem, and Meeting are defined in ADR-000; `rationale` uses the built-in `notes` array; no new entity is introduced
- Frontend: adds `ActionItemAnalyticsWidget.vue` (dashboard), `LiveDecisionPanel.vue` (meeting detail tab), `ALVMinutesActions.vue` (minutes detail), and `ActionItemExtractionModal.vue` (minutes detail)
- Extends existing `DecisionDetail.vue` with the "Overwegingen" section; extends `MinutesDetail.vue` with ALV actions and "Ter goedkeuring indienen" button
- Extends `DecisionService.php` from T1 with `notifyOnPublish()` hook
- Downstream: p3-ori-publication can read the rationale from Decision notes when publishing to PLOOI
- Downstream: p3-governance-bodies can filter action item analytics by GovernanceBody domain
