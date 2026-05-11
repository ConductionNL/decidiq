# Tasks: Citizen Participation

## 1. Data Model & Schemas

- [ ] 1.1 Create CitizenVote schema in OpenRegister register definition (properties: voteValue, voterId, motionId, citizenPanelId, weight, isProxy, castAt, notes)
- [ ] 1.2 Create CitizenPanel schema (properties: name, description, scope, memberCount, termStart, termEnd, statusLifecycle, createdBy)
- [ ] 1.3 Create ParticipatoryBudget schema (properties: name, description, totalAmount, currency, submissionDeadline, votingDeadline, status, resultsPublished)
- [ ] 1.4 Create BudgetProposal schema (properties: title, description, requestedAmount, submitter, category, status, votesFor, votesAgainst)
- [ ] 1.5 Create PublicConsultation schema (properties: title, description, relatedDecision, submissionDeadline, feedbackRequired, status, submissionCount)
- [ ] 1.6 Create Deliberation schema (properties: title, description, relatedMotion, discussionStatus, moderator, createdAt, postsCount)
- [ ] 1.7 Create Notification schema (properties: recipientId, type, subject, content, channel, status, sentAt, readAt)
- [ ] 1.8 Add isPublic boolean field to Meeting schema (default: false)
- [ ] 1.9 Add citizenVotingAllowed and citizenVotingMethod fields to Motion schema (default: false)
- [ ] 1.10 Add isPublished enum field to Decision schema (values: internal, public, confidential; default: internal)
- [ ] 1.11 Generate seed data for all new schemas (3-5 realistic citizen participation objects per schema)
- [ ] 1.12 Test OpenRegister schema validation for all new schemas via ObjectService

## 2. Backend API — Citizen Voting

- [ ] 2.1 Create CitizenVotingController with public endpoint `GET /api/citizens/motions/{motionId}/votes/active` (list active votes, no auth required)
- [ ] 2.2 Add endpoint `POST /api/citizens/motions/{motionId}/votes` (cast citizen vote; requires auth; validates citizenVotingAllowed flag)
- [ ] 2.3 Add endpoint `GET /api/citizens/motions/{motionId}/votes/results` (view voting results with separate staff/citizen/combined tabs; no auth required)
- [ ] 2.4 Create CitizenVoteService with methods: submitVote(), calculateResults(), validateVotingMethod()
- [ ] 2.5 Implement weighted voting support (votingWeight from Membership entity)
- [ ] 2.6 Implement ranked-choice voting (store ranking array in CitizenVote, calculate Condorcet winner)
- [ ] 2.7 Add per-object authorization check to voting endpoints (verify citizen belongs to correct governance body)
- [ ] 2.8 Test citizen voting with OpenRegister multi-tenancy (citizens from different bodies isolated)
- [ ] 2.9 Create Postman collection for citizen voting endpoints (test cases: cast vote, view results, voting method validation)

## 3. Backend API — Citizen Panels

- [ ] 3.1 Create CitizenPanelController with endpoints for public panel browsing (no auth required)
- [ ] 3.2 Add endpoint `GET /api/citizens/panels` (list all citizen panels with descriptions; public)
- [ ] 3.3 Add endpoint `GET /api/citizens/panels/{panelId}/members` (public roster of panel members; sanitize to exclude sensitive fields)
- [ ] 3.4 Add endpoint `POST /api/citizens/panels/{panelId}/join` (citizen applies to join panel; requires auth)
- [ ] 3.5 Add endpoint `GET /api/citizens/panels/{panelId}/feedback` (list published feedback/responses from panel; public)
- [ ] 3.6 Create CitizenPanelService with methods: listPanels(), addMember(), removeMember(), collectFeedback()
- [ ] 3.7 Add per-object authorization to panel endpoints (verify governance body access)
- [ ] 3.8 Test citizen panel endpoints with OpenRegister schema validation

## 4. Backend API — Participatory Budgeting

- [ ] 4.1 Create ParticipantoryBudgetController with endpoints for budget browsing
- [ ] 4.2 Add endpoint `GET /api/citizens/budgets` (list active/past budgets; no auth required)
- [ ] 4.3 Add endpoint `GET /api/citizens/budgets/{budgetId}/proposals` (list proposals for a budget; public)
- [ ] 4.4 Add endpoint `POST /api/citizens/budgets/{budgetId}/proposals` (citizen submits budget proposal; requires auth)
- [ ] 4.5 Add endpoint `POST /api/citizens/budgets/{budgetId}/proposals/{proposalId}/vote` (vote on proposal; requires auth)
- [ ] 4.6 Add endpoint `GET /api/citizens/budgets/{budgetId}/results` (view budget allocation results; public after voting closes)
- [ ] 4.7 Create ParticipantoryBudgetService with methods: submitProposal(), castVote(), calculateAllocation()
- [ ] 4.8 Test budget voting with proper deadline enforcement (reject votes after deadline)
- [ ] 4.9 Test participatory budget results calculation (multiple voting rounds, allocation algorithm)

## 5. Backend API — Public Consultations & Deliberation

- [ ] 5.1 Create PublicConsultationController for viewing and submitting feedback
- [ ] 5.2 Add endpoint `GET /api/citizens/consultations` (list active consultations; no auth required)
- [ ] 5.3 Add endpoint `POST /api/citizens/consultations/{consultationId}/feedback` (submit feedback; optionally authenticated)
- [ ] 5.4 Add endpoint `GET /api/citizens/consultations/{consultationId}/feedback` (view published feedback; public after deadline)
- [ ] 5.5 Create DeliberationController for threaded discussion
- [ ] 5.6 Add endpoint `GET /api/citizens/deliberations/{deliberationId}/posts` (list discussion posts; public)
- [ ] 5.7 Add endpoint `POST /api/citizens/deliberations/{deliberationId}/posts` (submit discussion post; optionally authenticated)
- [ ] 5.8 Add endpoint `PUT /api/citizens/deliberations/{deliberationId}/posts/{postId}` (edit own post; verify ownership)
- [ ] 5.9 Create moderation endpoints for staff: `PUT .../posts/{postId}/moderate` (flag/hide post)
- [ ] 5.10 Test consultation feedback with deadline enforcement
- [ ] 5.11 Test deliberation post moderation and ownership validation

## 6. Backend API — Transparency & Public Data

- [ ] 6.1 Create TransparencyController for public decision/meeting access
- [ ] 6.2 Add endpoint `GET /api/citizens/decisions` (search published decisions; filter by status, date range, area; public)
- [ ] 6.3 Add endpoint `GET /api/citizens/decisions/{decisionId}` (view decision detail; public if isPublished=true)
- [ ] 6.4 Add endpoint `GET /api/citizens/meetings/calendar` (public meeting calendar; list only isPublic meetings)
- [ ] 6.5 Add endpoint `GET /api/citizens/meetings/{meetingId}/agenda` (published agenda for public meetings; public)
- [ ] 6.6 Add endpoint `GET /api/citizens/meetings/{meetingId}/minutes` (published minutes if available; public)
- [ ] 6.7 Add endpoint `GET /api/citizens/meetings/{meetingId}/recording` (meeting recording URL if published; public)
- [ ] 6.8 Create ORI API endpoints per ADR-003 (`/api/ori/v1/decisions`, `/api/ori/v1/meetings`, etc.) with public read access
- [ ] 6.9 Test transparency endpoints with data filtering (ensure staff-only data never leaks to public)
- [ ] 6.10 Test ORI API output format against ORI specification

## 7. Backend API — Notifications

- [ ] 7.1 Create NotificationService with methods: sendEmail(), sendSms(), sendInApp()
- [ ] 7.2 Create NotificationController for citizen preference management
- [ ] 7.3 Add endpoint `GET /api/citizens/notifications/preferences` (retrieve notification preferences; requires auth)
- [ ] 7.4 Add endpoint `PUT /api/citizens/notifications/preferences` (update preferences; requires auth)
- [ ] 7.5 Add endpoint `GET /api/citizens/notifications` (list in-app notifications; requires auth)
- [ ] 7.6 Implement notification triggers: vote opened, vote closing soon, panel invitation, budget submission open, consultation deadline
- [ ] 7.7 Add background job for batch notification delivery (e.g., hourly digest of new opportunities)
- [ ] 7.8 Test GDPR compliance: notification recipients are explicitly opted-in, can unsubscribe, no PII in logs
- [ ] 7.9 Test notification delivery for multi-language support (Dutch + English)

## 8. Backend API — Offline Participation

- [ ] 8.1 Create OfflineFormGenerator service for PDF generation
- [ ] 8.2 Add endpoint `GET /api/citizens/forms/vote/{motionId}.pdf` (generate voting form with QR code)
- [ ] 8.3 Add endpoint `GET /api/citizens/forms/budget/{budgetId}.pdf` (generate budget proposal/voting form)
- [ ] 8.4 Create QrCodeService to generate scannable codes linking back to digital system
- [ ] 8.5 Create OfflineSubmissionImporter to handle paper form scanning/OCR and data import
- [ ] 8.6 Add endpoint `POST /api/citizens/submissions/import` (upload scanned form, parse, create digital vote/proposal)
- [ ] 8.7 Test PDF generation with accessibility features (alt text, embedded fonts, tagged PDF)
- [ ] 8.8 Test QR code scanner integration (link scanned code → web form prefilled with form data)

## 9. Frontend — Public Routes & Authorization

- [ ] 9.1 Add public routes to router: `/citizens` (landing), `/citizens/votes`, `/citizens/panels`, `/citizens/budgets`, `/citizens/decisions`, `/citizens/consultations`, `/citizens/meet-the-council`
- [ ] 9.2 Create PublicLayout.vue (no app navigation, simplified header, footer with language toggle)
- [ ] 9.3 Create role-based route guards (public = no auth, authenticated = requires Nextcloud login, admin = requires admin role)
- [ ] 9.4 Test deep linking to citizen portals (shareable URLs for specific votes, budgets, consultations)

## 10. Frontend — Citizen Dashboard

- [ ] 10.1 Create CitizenDashboard.vue component
- [ ] 10.2 Add stats blocks: active votes count, open budgets, upcoming consultations, panel invitations
- [ ] 10.3 Add "Recommended Next Steps" widget: personalized opportunities based on user profile
- [ ] 10.4 Add "My Participation History" widget: past votes, completed surveys, adopted budget proposals
- [ ] 10.5 Add notification center: in-app notifications with dismiss/archive actions
- [ ] 10.6 Test dashboard load performance with parallel API calls (votes, budgets, panels, notifications)
- [ ] 10.7 Test WCAG 2.1 AA compliance: keyboard navigation, color contrast, screen reader support

## 11. Frontend — Citizen Voting UI

- [ ] 11.1 Create CitizenVotingIndex.vue (list active votes with status indicators)
- [ ] 11.2 Create CitizenVoteDetail.vue (single vote interface with instructions, voting methods, results)
- [ ] 11.3 Implement simple majority UI: radio buttons (For/Against/Abstain)
- [ ] 11.4 Implement weighted voting UI: sliders or percentage input fields
- [ ] 11.5 Implement ranked-choice UI: draggable list of options
- [ ] 11.6 Add results visualization: bar charts (ApexCharts), vote counts, participation rate
- [ ] 11.7 Add confirmation dialog before submitting vote
- [ ] 11.8 Test voting UI with different screen sizes (mobile, tablet, desktop)
- [ ] 11.9 Test voting UI WCAG 2.1 AA compliance

## 12. Frontend — Citizen Panel UI

- [ ] 12.1 Create CitizenPanelIndex.vue (list all panels with descriptions, member count, status)
- [ ] 12.2 Create CitizenPanelDetail.vue (panel description, roster, feedback/statements from panel)
- [ ] 12.3 Add "Join Panel" button and modal (citizen applies, confirmation message)
- [ ] 12.4 Add panel roster display (names, roles, optional photos if provided)
- [ ] 12.5 Create panel feedback view (statements/feedback published by panel, linked to decisions)
- [ ] 12.6 Test panel browsing with no auth required
- [ ] 12.7 Test panel join workflow with authentication

## 13. Frontend — Participatory Budgeting UI

- [ ] 13.1 Create ParticipantoryBudgetIndex.vue (list budgets by status: open, voting, closed)
- [ ] 13.2 Create ParticipatoryBudgetDetail.vue (budget overview, submission/voting phases, timeline)
- [ ] 13.3 Create BudgetProposalList.vue (list proposals with vote counts, budget requested, category)
- [ ] 13.4 Create BudgetProposalDetail.vue (proposal description, submitter, voting controls, results)
- [ ] 13.5 Create "Submit Proposal" form (title, description, amount, category; validate total against budget limit)
- [ ] 13.6 Create budget voting UI (vote for/against multiple proposals, see running allocation)
- [ ] 13.7 Add results visualization: pie chart of allocated budget, top-ranked proposals
- [ ] 13.8 Test budget workflow: proposal phase → voting phase → results phase
- [ ] 13.9 Test WCAG 2.1 AA compliance for budget UI

## 14. Frontend — Public Consultations & Deliberation UI

- [ ] 14.1 Create PublicConsultationIndex.vue (list consultations with submission status, deadline)
- [ ] 14.2 Create PublicConsultationDetail.vue (consultation description, submission form, deadline)
- [ ] 14.3 Create ConsultationFeedbackForm.vue (form fields per consultation configuration, optional auth)
- [ ] 14.4 Create DeliberationThread.vue (threaded discussion with nested replies, timestamps)
- [ ] 14.5 Create DeliberationPostForm.vue (text input, optional name/email, submit)
- [ ] 14.6 Add moderation indicator (staff can see flagged/hidden posts)
- [ ] 14.7 Test consultation submission with deadline enforcement
- [ ] 14.8 Test deliberation post creation and moderation UI

## 15. Frontend — Transparency Portal UI

- [ ] 15.1 Create DecisionSearchPage.vue (searchable list of published decisions)
- [ ] 15.2 Create DecisionDetail.vue (decision text, related motion, adoption date, implementation status)
- [ ] 15.3 Create MeetingCalendar.vue (calendar view of public meetings, grid/list toggle)
- [ ] 15.4 Create MeetingDetail.vue (agenda, minutes, recordings if published)
- [ ] 15.5 Create "Meet the Council" section with governance body info, members, contact
- [ ] 15.6 Create open data export options (CSV, JSON download of decisions/meetings/voting results)
- [ ] 15.7 Test decision search with filters (date range, area, decision type)
- [ ] 15.8 Test recording playback integration (link to external video host or embedded player)

## 16. Frontend — Offline Participation UI

- [ ] 16.1 Create OfflineFormsPage.vue (list downloadable PDF forms)
- [ ] 16.2 Add "Download Voting Form" button (generates PDF with QR code)
- [ ] 16.3 Add "Download Budget Proposal Form" button (generates fillable PDF)
- [ ] 16.4 Create QR code scanner page (scan form QR, auto-prefill form fields)
- [ ] 16.5 Create paper form submission workflow (upload scanned image, preview parsed data, confirm submission)
- [ ] 16.6 Test PDF generation with accessibility features (tested by screen reader)
- [ ] 16.7 Test QR code scanner with mobile browsers

## 17. Frontend — Notification Preferences

- [ ] 17.1 Create NotificationPreferencesModal.vue (opened from citizen dashboard)
- [ ] 17.2 Add toggles for notification channels: email, SMS (if enabled), in-app
- [ ] 17.3 Add filters: notification types (vote alerts, panel invitations, budget updates, etc.)
- [ ] 17.4 Add frequency settings: immediate, daily digest, weekly digest
- [ ] 17.5 Test preference persistence via API
- [ ] 17.6 Test WCAG 2.1 AA compliance for preference UI

## 18. Frontend — Mobile Optimization

- [ ] 18.1 Test all citizen UI at 320px width (mobile-first breakpoint)
- [ ] 18.2 Test voting interface with touch interactions (no hover states as sole interaction)
- [ ] 18.3 Test form submission on mobile (keyboard behavior, submit button accessibility)
- [ ] 18.4 Test camera/QR scanner integration on mobile devices
- [ ] 18.5 Test offline capability (service worker caching for critical citizen pages)

## 19. Internationalization (i18n)

- [ ] 19.1 Create `l10n/en.json` with all citizen-facing strings (voting, panels, budgeting, consultations, transparency)
- [ ] 19.2 Create `l10n/nl.json` with Dutch translations (exact same keys as en.json)
- [ ] 19.3 Add translation keys to shared components (CnDataTable labels, form placeholders)
- [ ] 19.4 Test i18n with language switcher: en ↔ nl
- [ ] 19.5 Test date/number formatting respects user locale (via Nextcloud core)

## 20. Accessibility (WCAG 2.1 AA)

- [ ] 20.1 Run axe accessibility scanner on all citizen pages (zero violations)
- [ ] 20.2 Test keyboard navigation: Tab through all forms, vote buttons, links
- [ ] 20.3 Test screen reader (NVDA/JAWS): labels on forms, alt text on images, landmark navigation
- [ ] 20.4 Test color contrast: all text meets WCAG AA ratio (4.5:1 for body, 3:1 for large text)
- [ ] 20.5 Test form validation messages: linked to form controls, announced to screen readers
- [ ] 20.6 Test voting UI with reduced motion enabled (no animation-only conveyed info)
- [ ] 20.7 Create accessibility documentation for citizen-facing features

## 21. NL Design System Integration

- [ ] 21.1 Apply NL Design System CSS custom properties to all citizen UI components
- [ ] 21.2 Implement theming: support Rijkshuisstijl, Utrecht, and municipality-specific token sets
- [ ] 21.3 Test theme switching via nldesign app (if integrated)
- [ ] 21.4 Test scoped styles: no global CSS leakage
- [ ] 21.5 Validate all color usage against token names (no hardcoded hex values)

## 22. Testing — Unit Tests

- [ ] 22.1 Write PHPUnit tests for CitizenVoteService (submit vote, calculate results, validate voting method)
- [ ] 22.2 Write tests for CitizenPanelService (add member, remove member, collect feedback)
- [ ] 22.3 Write tests for ParticipantoryBudgetService (submit proposal, cast vote, calculate allocation)
- [ ] 22.4 Write tests for NotificationService (send email, SMS, in-app; validate user preferences)
- [ ] 22.5 Write tests for OfflineSubmissionImporter (parse PDF data, create digital objects)
- [ ] 22.6 Verify all tests pass: `composer check:strict`

## 23. Testing — API Integration Tests

- [ ] 23.1 Create Postman collection for citizen voting endpoints (test happy path + error cases)
- [ ] 23.2 Create collection for citizen panels (test browsing + joining)
- [ ] 23.3 Create collection for participatory budgeting (test submission + voting)
- [ ] 23.4 Create collection for consultations (test feedback submission)
- [ ] 23.5 Test authorization: public endpoints reject no auth, authenticated endpoints require token, admin endpoints require admin role
- [ ] 23.6 Test multi-tenancy: citizen A cannot access governance body B's data
- [ ] 23.7 Run all integration tests in `composer check:strict`

## 24. Testing — Browser/E2E Tests

- [ ] 24.1 Create Playwright test for citizen voting workflow: browse votes → cast vote → view results
- [ ] 24.2 Create test for citizen panel workflow: browse panels → join panel → view roster
- [ ] 24.3 Create test for participatory budgeting: submit proposal → vote on proposals → view results
- [ ] 24.4 Create test for consultation: view consultation → submit feedback
- [ ] 24.5 Create test for offline form: download PDF → fill form → upload → vote recorded
- [ ] 24.6 Create test for transparency portal: search decisions → view decision → download data
- [ ] 24.7 Test all workflows with different user roles: anonymous, citizen, staff, admin
- [ ] 24.8 Test workflows across different governance body types (municipality, water board, corporate)

## 25. Documentation

- [ ] 25.1 Create user documentation in `docs/citizen-voting.md` with screenshots
- [ ] 25.2 Create `docs/citizen-panels.md` documentation
- [ ] 25.3 Create `docs/participatory-budgeting.md` documentation
- [ ] 25.4 Create `docs/public-consultations.md` documentation
- [ ] 25.5 Create `docs/transparency-portal.md` documentation
- [ ] 25.6 Create `docs/offline-participation.md` documentation
- [ ] 25.7 Create admin documentation: configuring public access, publishing decisions, managing citizen notifications
- [ ] 25.8 Create Dutch translations of all documentation (each doc.md → docs/nl/{same-name}.md)
- [ ] 25.9 Create API documentation for citizen endpoints (OpenAPI/Swagger)
- [ ] 25.10 Document ORI compatibility and API usage

## 26. Spec Generation

- [ ] 26.1 Write `openspec/specs/citizen-voting/spec.md` with requirements, data model, API endpoints, UI flows
- [ ] 26.2 Write `openspec/specs/citizen-panels/spec.md`
- [ ] 26.3 Write `openspec/specs/participatory-budgeting/spec.md`
- [ ] 26.4 Write `openspec/specs/public-consultations/spec.md`
- [ ] 26.5 Write `openspec/specs/citizen-dashboard/spec.md`
- [ ] 26.6 Write `openspec/specs/transparency-portal/spec.md`
- [ ] 26.7 Write `openspec/specs/offline-participation/spec.md`
- [ ] 26.8 Write `openspec/specs/citizen-notifications/spec.md`
- [ ] 26.9 Write delta spec for `openspec/specs/meeting-management/` (isPublic field changes)
- [ ] 26.10 Write delta spec for `openspec/specs/motion-and-voting/` (citizenVotingAllowed, citizenVotingMethod)
- [ ] 26.11 Write delta spec for `openspec/specs/decisions/` (isPublished field changes)

## 27. Quality & Pre-Commit Verification

- [ ] 27.1 Verify SPDX headers on all new files (EUPL-1.2)
- [ ] 27.2 Run PHPCS on new PHP controllers/services: `composer check:strict`
- [ ] 27.3 Run ESLint on new Vue components: `npm run lint`
- [ ] 27.4 Run Stylelint on new component styles: `npm run lint:css`
- [ ] 27.5 Verify all ObjectService calls have 3 positional args: ($register, $schema, $idOrParams)
- [ ] 27.6 Verify no hardcoded strings in templates (all use `this.t()` for Vue, `$l10n->t()` for PHP)
- [ ] 27.7 Verify every API mutation endpoint has per-object authorization check
- [ ] 27.8 Verify no raw `fetch()` calls (use `@nextcloud/axios` instead for CSRF)
- [ ] 27.9 Verify no error responses return `getMessage()` (use static strings, log real error server-side)
- [ ] 27.10 Run all tests: `composer check:strict && npm run lint && npm run test`

## 28. Deduplication Check

- [ ] 28.1 Search OpenRegister services for existing citizen voting logic (CitizenVoteService, VotingService)
- [ ] 28.2 Search `openspec/specs/` for overlapping citizen participation specs
- [ ] 28.3 Search `@conduction/nextcloud-vue` for voting, panel, budgeting components that could be reused
- [ ] 28.4 Document findings: identify reusable components, extend existing services if needed
- [ ] 28.5 If duplication found, refactor to use shared implementation (do NOT rebuild existing logic)

## 29. Final Review & Smoke Testing

- [ ] 29.1 Call `GET /api/citizens/motions/123/votes/active` with curl, verify response shape (200 with array of active votes)
- [ ] 29.2 Test error path: POST vote without auth (verify 401 or redirect to login)
- [ ] 29.3 Test error path: POST vote with invalid motionId (verify 404)
- [ ] 29.4 Test error path: POST vote when voting is closed (verify 400 with deadline message)
- [ ] 29.5 Test `GET /api/citizens/decisions` (verify only isPublished=true decisions returned)
- [ ] 29.6 Test `GET /api/citizens/decisions` from different governance body (verify isolation)
- [ ] 29.7 Verify no tasks.md entries are `[x]` without full implementation (no stubs or TODOs)
- [ ] 29.8 Test complete offline workflow: generate PDF → fill form → upload image → vote recorded
- [ ] 29.9 Manual smoke test: open Decidesk, navigate to citizen portal, vote on active motion, check results
- [ ] 29.10 Verify OpenRegister is accessible and all objects created successfully
