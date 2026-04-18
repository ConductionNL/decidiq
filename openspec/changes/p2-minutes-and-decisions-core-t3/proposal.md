# Proposal: Minutes and Decisions — Core T3

## Why

The p2-minutes-and-decisions capability established the foundational data model and CRUD operations for meeting minutes and decisions. However, real-world governance workflows require capabilities that go beyond basic record-keeping: decision-makers need to record decisions and action items **in real-time during meetings**, automatically generate action items from minutes, publish decisions for public access via the ORI standard, and ensure decisions are discoverable and linked across governance processes.

Core T3 addresses the operational workflows that turn minutes and decisions into actionable outcomes for governance bodies across all five governance domains (legislatures, associations, corporate boards, operational teams, citizen participation).

## What Changes

- **Real-time decision recording**: Decisions and action items are captured and persisted during active meetings, with immediate availability for publication or export
- **Action item automation**: ActionItems are automatically generated from Minutes content using configurable extraction rules
- **Decision publication**: Decisions are published via the ORI-compatible API endpoint for public access and standards compliance
- **Minutes lifecycle management**: Formal approval workflows with digital signatures (chair + secretary), version tracking, and lifecycle state transitions
- **ORI API serialization**: Minutes, Decisions, and related entities are exposed via `/api/ori/v1/reports` and related endpoints with proper field mapping
- **Decision notifications**: Stakeholders are notified when decisions affecting them are published (future integration point)
- **WCAG 2.1 AA compliance** for all voting interfaces, decision recording UI, and minutes review screens
- **Seed data** for Minutes, Decision, ActionItem objects to support testing and QA workflows

## Capabilities

### New Capabilities

- `minutes-real-time-capture`: Capture minutes and decisions during active meetings with real-time persistence and publication readiness
- `decision-publication-ori`: Publish decisions via ORI-compatible API endpoint for public access and inter-system integration
- `action-item-automation`: Automatically extract and create ActionItems from Minutes content using configurable rules
- `minutes-approval-workflow`: Formal Minutes approval process with digital signatures (chair + secretary), version tracking, and lifecycle states (draft → submitted → approved → published)
- `decision-discovery`: Full-text search, filtering, and discovery of decisions across governance bodies and time periods
- `decision-notifications`: Notify stakeholders when decisions affecting their interests are published (foundation for external integrations)

### Modified Capabilities

- `minutes-and-decisions`: Requirements for Minutes entity expanded to include real-time capture, approval workflow, version tracking, and formal signature support. Decision entity now includes publication state and ORI mapping.

## Impact

- **Entities**: Minutes and Decision (ADR-000) — properties expanded to support lifecycle, signatures, version tracking, and ORI publication
- **Data Layer**: OpenRegister schemas updated to support new properties; migration required for existing Minutes/Decision objects
- **API**: New `/api/ori/v1/reports` endpoint for Minutes; expanded `/api/ori/v1/*` endpoints for Decision publication
- **Frontend**: New components for real-time decision capture during meetings, Minutes approval UI, decision search and filtering
- **Backend**: Decision extraction engine, workflow state machine for Minutes approval, ORI serialization layer
- **Testing**: WCAG 2.1 AA compliance testing on voting interfaces, Minutes UI, Decision publishing workflows; OpenRegister schema validation; ORI API format verification
- **Governance Domains**: Impacts all five domains — legislatures publish decisions for public records, associations track ALV decisions with formal approval, corporate boards maintain certified minutes, operational teams track action items, citizen participation platforms publish referenda/initiative decisions
