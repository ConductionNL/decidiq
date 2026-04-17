# Tasks: Minutes and Decisions — Core T3

## 1. Schema Updates & Data Model

- [ ] 1.1 Update Minutes schema to include `lifecycle` state (draft → submitted → approved → published), `signedBy` array (chair + secretary digital signatures), and `version` integer
- [ ] 1.2 Update Decision schema to include `isPublished` boolean, `publishedAt` timestamp, and ORI mapping properties
- [ ] 1.3 Create ActionItem schema with `title`, `description`, `assignee`, `dueDate`, `taskStatus`, and `completedAt` fields
- [ ] 1.4 Add migration handler for existing Minutes/Decision objects to populate new fields with default values
- [ ] 1.5 Update OpenRegister register templates in `lib/Settings/decidesk_register.json` with new schema definitions
- [ ] 1.6 Validate schemas against ADR-000 data model specification

## 2. Real-Time Decision Capture UI

- [ ] 2.1 Create MeetingMinutesEditor component for real-time Minutes capture during active meetings with rich text editing
- [ ] 2.2 Create DecisionRecorder component for capturing decisions in real-time with decision title, text, rationale, and date
- [ ] 2.3 Add vote display and tallying UI component showing votesFor, votesAgainst, votesAbstain linked to VotingRound
- [ ] 2.4 Implement auto-save mechanism for Minutes and Decision entities with conflict resolution
- [ ] 2.5 Add WCAG 2.1 AA compliance to all decision capture UI components (color contrast, keyboard navigation, screen reader support, form labels)
- [ ] 2.6 Create decision quick-link feature to associate decisions with agenda items and motions

## 3. Minutes Approval Workflow

- [ ] 3.1 Implement Minutes lifecycle state machine (draft → submitted → approved → published) using backend workflow service
- [ ] 3.2 Create MinutesApprovalForm component with digital signature fields for chair and secretary
- [ ] 3.3 Implement signature verification mechanism using OpenRegister audit trail for legal compliance
- [ ] 3.4 Add Minutes version tracking and revision history UI showing changes between versions
- [ ] 3.5 Create workflow transition guards ensuring correct role-based approval (chair, secretary, governance body authority)
- [ ] 3.6 Implement approval notifications sent to signers and governance body with secure links for action

## 4. Action Item Automation

- [ ] 4.1 Create ActionItemExtractor service to parse Minutes content for action items using configurable regex/keyword patterns
- [ ] 4.2 Implement rule configuration UI allowing governance bodies to define custom action item extraction patterns per domain
- [ ] 4.3 Build automated ActionItem creation workflow triggered when Minutes transition to approved state
- [ ] 4.4 Add manual action item creation UI within Minutes for explicitly documented action items
- [ ] 4.5 Implement action item assignment logic linking to Participant/Person via Membership
- [ ] 4.6 Create action item completion tracking with dueDate validation and status updates (pending → in-progress → completed)

## 5. ORI API & Publication

- [ ] 5.1 Implement ORI serialization layer mapping Minutes entity to ORI Report schema
- [ ] 5.2 Implement ORI serialization layer mapping Decision entity to ORI schema with proper field mapping
- [ ] 5.3 Create `/api/ori/v1/reports` endpoint exposing Minutes as ORI Reports with pagination and filtering
- [ ] 5.4 Create Decision publication endpoint exposing published decisions via ORI API with legalBasis field
- [ ] 5.5 Implement publish workflow that transitions Decision to published state and makes it visible via ORI endpoint
- [ ] 5.6 Add ORI API documentation and response format examples to design artifact
- [ ] 5.7 Validate ORI API output against ORI specification and VNG standards

## 6. Decision Discovery & Search

- [ ] 6.1 Implement full-text search on Decision entities (title, text, legalBasis, rationale) via OpenRegister search API
- [ ] 6.2 Create DecisionSearchUI component with filters for date range, decision type, governance body, outcome, publication status
- [ ] 6.3 Add decision relationship discovery showing related motions, amendments, votes, agenda items
- [ ] 6.4 Implement decision export functionality (CSV, JSON, PDF) for analysis and reporting
- [ ] 6.5 Create decision dashboard showing recent decisions, high-impact decisions, publication metrics per governance body

## 7. Decision Notifications (Foundation)

- [ ] 7.1 Create NotificationService foundation for decision publication events (structure for future integration with mail, Slack, webhooks)
- [ ] 7.2 Implement event emission when Decision transitions to published state
- [ ] 7.3 Add notification preference configuration per user/governance body for future integrations
- [ ] 7.4 Document notification event schema and integration points for future enhancement

## 8. Seed Data & Testing

- [ ] 8.1 Create seed Minutes objects with draft, submitted, approved, and published lifecycle examples in register template
- [ ] 8.2 Create seed Decision objects with various outcomes (adopted, rejected, amended, deferred) and publication states
- [ ] 8.3 Create seed ActionItem objects with various statuses (pending, in-progress, completed, overdue)
- [ ] 8.4 Update decidesk_register.json components.objects[] with realistic seed data matching ADR-000 specifications
- [ ] 8.5 Verify seed data is idempotent and loads correctly on app install
- [ ] 8.6 Test OpenRegister schema validation with seed data to ensure schema compliance
- [ ] 8.7 Test Minutes lifecycle transitions across all governance domains to verify workflow state machine
- [ ] 8.8 Validate ORI API output format against ORI specification with real seed data
- [ ] 8.9 Test WCAG 2.1 AA compliance on all voting interfaces, Minutes capture UI, and decision publishing screens using axe-core
- [ ] 8.10 Perform integration testing of real-time decision capture with concurrent user scenarios and conflict resolution

## 9. Documentation & Compliance

- [ ] 9.1 Document Minutes approval workflow and digital signature requirements in app documentation
- [ ] 9.2 Document ORI API endpoint, request/response formats, and VNG compliance notes
- [ ] 9.3 Document action item extraction rules and customization guide for governance bodies
- [ ] 9.4 Update ADR-002-caldav-first-storage to include ActionItem CalDAV integration notes
- [ ] 9.5 Create WCAG 2.1 AA compliance report for all voting and decision-related interfaces
- [ ] 9.6 Document all new OpenRegister schema properties and their purpose in ADR-000 updates
