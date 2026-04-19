## Why

Governance bodies depend on accurate, timely records of their meetings. Minutes must be drafted promptly after each session, reviewed by the chair and secretary, digitally signed, and published — while every formal decision must be linked to its source motion, timestamped, and made available via the ORI API for open-data and Woo compliance. Today, clerks manage these workflows across disconnected tools (email, Word, SharePoint), creating version-control risks, missed publication deadlines, and accountability gaps for action items that are never tracked to completion.

This change delivers the complete post-meeting workflow: the minutes lifecycle (draft → review → approved → signed → published), decision recording with ORI publication flagging, action item tracking with overdue detection, and decision search and archive — the core post-meeting operations for every governance body using Decidesk.

## What Changes

- **New**: Minutes index and detail pages with full lifecycle workflow (draft → review → approved → signed → published), visualised via `CnTimelineStages`
- **New**: Minutes approval workflow — chair and secretary can approve and digitally sign minutes; signers are stored in the `signedBy` array with `approvedAt` timestamp
- **New**: Minutes versioning — full revision history via OpenRegister built-in audit trail (`CnAuditTrailTab`)
- **New**: Automated minutes generation — a "Generate Draft" action on the Minutes detail page calls a backend service that compiles agenda items, motions, vote results, and decisions into the `content` field
- **New**: Decision index and detail pages with outcome recording (adopted/rejected), reference to source motion, and legal basis field
- **New**: Decision publication action — sets `isPublished: true` and `publishedAt` on adopted decisions; lays the groundwork for the p3 ORI API webhook
- **New**: Decision search and archive — full-text search across historical decisions by topic, date, governance body, and outcome via `CnFilterBar` + `CnFacetSidebar`
- **New**: Action item index and detail pages with assignment, due dates, and status lifecycle (open → in-progress → completed / overdue)
- **New**: Background job (`IJob`) that detects overdue action items daily and sets `taskStatus: overdue`
- **New**: Dashboard additions — minutes awaiting approval, published decisions count, and open action items KPI cards

## Capabilities

### New Capabilities

- `minutes-lifecycle`: Full CRUD for Minutes with draft → review → approved → signed → published workflow, digital signing (signer names), versioning via audit trail, and lifecycle visualisation
- `decision-recording`: Decision creation linked to motions and vote outcomes, outcome tracking (adopted/rejected), legal basis field, and detail view with related action items
- `decision-publication`: Explicit "Publish" action on adopted decisions setting `isPublished` and `publishedAt`; audit trail confirms publication; ORI webhook deferred to p3
- `decision-archive`: Full-text search and faceted filtering of decisions by topic, date, governance body, and outcome
- `action-item-tracking`: Action item CRUD with assignee, due dates, status lifecycle (open → in-progress → completed), and automated overdue detection via daily background job
- `minutes-generation`: Template-based generation of initial minutes draft from linked Meeting agenda items, motions, vote results, and decisions

### Modified Capabilities

- `app-foundation` (from p1-crud-operations): extend `initializeStores()` to register Minutes, Decision, and ActionItem object stores; extend `MainMenu` with Minutes, Decisions, and Action Items navigation entries; extend Dashboard with three post-meeting KPI cards

## Impact

- Adds Pinia object stores and Vue Router routes for Minutes, Decision, and ActionItem
- Extends Dashboard with 3 new `CnStatsBlock` KPI cards
- No schema changes — all 3 entities are already defined in ADR-000 and registered in `decidesk_register.json` (p1-schemas-and-data-model)
- Adds one backend `IJob` class for overdue detection and one PHP service for minutes generation
- Downstream: `p3-governance-bodies` can link governance bodies to their decisions via existing relations
- Downstream: `p3-ori-publication` extends the ORI API webhook on top of the `isPublished` flag introduced here
