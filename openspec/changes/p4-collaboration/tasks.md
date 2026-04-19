# Tasks: Collaboration

## 1. Data Model Setup

- [ ] 1.1 Create Task schema (OpenRegister) — title, description, assignee (Person ref), delegator (Person ref), dueDate, taskStatus (enum: pending, in-progress, completed, reclaimed), completedAt, delegatedAt
- [ ] 1.2 Create Delegation schema — taskUid, delegator (Person ref), delegate (Person ref), substitute (Person ref optional), delegatedAt, expiresAt (for absence duration), status (enum: active, expired, revoked)
- [ ] 1.3 Create Comment schema — text, author (Person ref), target (references agenda item, motion, amendment, or decision via relation), createdAt, updatedAt, mentions (array of Person refs), parentComment (for threading)
- [ ] 1.4 Create EmailLink schema — emailUid (Nextcloud Mail ID), mailboxId, subject, from, to, receivedAt, linkedTo (references decision or agenda item), extractedText, _mail metadata field
- [ ] 1.5 Create NotificationPreference schema — person (Person ref), meetingCreated (boolean), votingOpened (boolean), decisionPublished (boolean), taskAssigned (boolean), commentMention (boolean), deliveryMethod (enum: in-app, email, both)
- [ ] 1.6 Create EngagementRecord schema — meeting (Meeting ref), participant (Person ref), speeches (array: startTime, endTime, text, role), questionsRaised (array), topicsSuggested (array), speakingDuration (seconds), engagementScore (0-100)
- [ ] 1.7 Create CollaborationWorkspace schema — name, type (enum: faction, committee, task-group), members (array of Membership refs), owner (Person ref), createdAt, purpose, accessLevel (enum: private, restricted, public)
- [ ] 1.8 Add Task relation to Meeting, GovernanceBody, and Person for cross-entity queries
- [ ] 1.9 Register all new schemas in decidesk_register.json with OpenAPI definitions and x-openregister extensions

## 2. Task Delegation Framework

- [ ] 2.1 Implement TaskService — saveTask(), findTask(), findTasksByAssignee(), reclaimTask(), updateTaskStatus()
- [ ] 2.2 Implement DelegationService — createDelegation(), findDelegation(), expireDelegation(), revokeDelegation(), isSubstituteActive()
- [ ] 2.3 Create TaskController with REST endpoints: POST /api/task (create), GET /api/task/:id (read), PUT /api/task/:id (update), DELETE /api/task/:id (remove), POST /api/task/:id/reclaim (reclaim)
- [ ] 2.4 Create DelegationController with REST endpoints: POST /api/delegation (create), GET /api/delegation/:id (read), PUT /api/delegation/:id (update), DELETE /api/delegation/:id (revoke)
- [ ] 2.5 Implement task lifecycle state machine — pending → in-progress → completed; reclaimable from any state
- [ ] 2.6 Test with OpenRegister: create, read, update, delete tasks across all governance domain types
- [ ] 2.7 Add @spec PHPDoc tags to all new classes linking to this tasks.md

## 3. Task Tracking Frontend

- [ ] 3.1 Create TaskIndexPage — CnIndexPage with useListView for task list, filters (assigned to me, delegated by me, status), search
- [ ] 3.2 Create TaskDetailPage — task form with assignee/delegator read-only, status dropdown, completion tracking, dueDate calendar
- [ ] 3.3 Create DelegationDialog — create/edit delegation with person select, optional substitute, duration picker for absence dates
- [ ] 3.4 Create ReclaimTaskButton — restore task ownership from current assignee back to original delegator
- [ ] 3.5 Add task progress widget to dashboard — "My Tasks" showing assigned count, delegated count, overdue count
- [ ] 3.6 Create task sidebar card in GovernanceBody and Meeting detail pages showing related tasks
- [ ] 3.7 Store exports in Pinia task store with createObjectStore plugin for CRUD and relations
- [ ] 3.8 Test task delegation workflow across 5 governance domains (legislative, association, corporate, operations, citizen)

## 4. Collaboration Workspace

- [ ] 4.1 Implement WorkspaceService — createWorkspace(), addMembers(), removeMember(), findWorkspace(), findWorkspacesByMember(), updateWorkspace()
- [ ] 4.2 Create WorkspaceController with REST endpoints: POST /api/workspace (create), GET /api/workspace/:id (read), PUT /api/workspace/:id (update), DELETE /api/workspace/:id, POST /api/workspace/:id/members (add member), DELETE /api/workspace/:id/members/:personId (remove)
- [ ] 4.3 Create WorkspaceIndexPage — list of factions, committees, task groups filtered by membership; create workspace button
- [ ] 4.4 Create WorkspaceDetailPage — members list, agenda items pinned to workspace, task list, discussion feed filtered to workspace
- [ ] 4.5 Implement member role permissions (owner, editor, viewer) in WorkspaceService — enforce via AuthorizationService
- [ ] 4.6 Add workspace context to task creation — tasks can be scoped to a workspace for visibility isolation
- [ ] 4.7 Test faction position coordination workflow (presidium agenda setting, vote alignment)

## 5. Discussion and Comments

- [ ] 5.1 Implement CommentService — saveComment(), findComment(), findCommentsForTarget(), deleteComment(), updateComment(), resolveThread()
- [ ] 5.2 Create CommentController with REST endpoints: POST /api/comment (create), GET /api/comment/:id (read), PUT /api/comment/:id (update), DELETE /api/comment/:id (remove), GET /api/comment?target={type}:{uuid} (find by target)
- [ ] 5.3 Create CommentCard component — display single comment with author, timestamp, text, @mentions as links; edit/delete actions for author
- [ ] 5.4 Create CommentThread component — tree view of comments with threading, reply button, mark-as-resolved option
- [ ] 5.5 Create CommentForm — textarea with @mention autocomplete (Person lookup), submit, cancel buttons
- [ ] 5.6 Add comment section to AgendaItemDetailPage, MotionDetailPage, AmendmentDetailPage, DecisionDetailPage using CommentThread
- [ ] 5.7 Implement @mention notification — CommentService triggers NotificationService when person is mentioned
- [ ] 5.8 Test discussions on agenda items for compliance with meeting governance protocols

## 6. Email Integration

- [ ] 6.1 Implement EmailLinkService — linkEmailToDecision(), linkEmailToAgendaItem(), findLinkedEmails(), extractEmailMetadata()
- [ ] 6.2 Create EmailLinkController with REST endpoints: POST /api/emaillink (create from Mail message), GET /api/emaillink?linkedTo={type}:{uuid} (find emails for decision/agenda item), DELETE /api/emaillink/:id (unlink)
- [ ] 6.3 Implement Nextcloud Mail app integration — read _mail metadata from email objects; parse email UID and mailbox ID
- [ ] 6.4 Create EmailDossierCard component — display linked emails in a decision/agenda item detail page with sender, subject, date, preview text; link to Mail app
- [ ] 6.5 Implement email parsing — extract decision references (e.g., "Decision-2024-001") from email subject/body to auto-suggest linking
- [ ] 6.6 Add email link button in Decision and AgendaItem detail pages — opens picker to select email from Mail via API
- [ ] 6.7 Store extracted email text in Comment as structured reference (from: address, subject, date, snippet)
- [ ] 6.8 Test email-to-decision linking with OpenRegister relation system

## 7. Notification Preferences

- [ ] 7.1 Implement NotificationService — createPreference(), findPreference(), updatePreference(), shouldNotify()
- [ ] 7.2 Create NotificationPreferenceController with REST endpoints: GET /api/notification-preference (read own), PUT /api/notification-preference (update own)
- [ ] 7.3 Create NotificationPreferenceCard — checkboxes for meeting alerts, vote alerts, decision alerts, task alerts, mention alerts; deliveryMethod radio (in-app, email, both)
- [ ] 7.4 Integrate NotificationService into task delegation flow — notify assignee when task assigned, delegator when task completed
- [ ] 7.5 Integrate into meeting lifecycle — notify members when meeting scheduled, voting opened, closed; check NotificationPreference before sending
- [ ] 7.6 Implement mention notification — when person is @mentioned in comment, trigger based on preference (in-app via NotificationService, email via MailerService)
- [ ] 7.7 Add notification preferences to UserSettings modal (not a route, opened from gear menu in MainMenu)
- [ ] 7.8 Test notification delivery respects user preferences and absence modes

## 8. Participant Engagement Tracking

- [ ] 8.1 Implement EngagementService — captureEngagement(), findEngagementForMeeting(), calculateScore(), findParticipantStats()
- [ ] 8.2 Create EngagementController with REST endpoints: POST /api/engagement (capture speech/question/topic), GET /api/engagement?meeting={meetingUid} (find by meeting)
- [ ] 8.3 Create SpeechCaptureDialog — during meeting, record participant speech (who, duration, role, text snippet); save via EngagementService
- [ ] 8.4 Create TopicSuggestionForm — participant can suggest agenda topics during meeting for future meetings
- [ ] 8.5 Create QuestionCapture — capture Q&A during meeting, link questioner to Motion/Decision
- [ ] 8.6 Add engagement stats to ParticipantDetailPage — show speeches count, total speaking duration, topics suggested, questions raised per meeting cycle
- [ ] 8.7 Create MeetingEngagementSummary — show at end of minutes (chair + secretary review before approval) with all captured contributions
- [ ] 8.8 Test engagement capture across 5 governance domains and validate with meeting types (plenary, committee, faction meeting)

## 9. Motion Co-Authoring

- [ ] 9.1 Extend Motion schema with co-authors field (array of Person refs), versionHistory (array: author, timestamp, text, change summary)
- [ ] 9.2 Create MotionCoauthorService — addCoauthor(), removeCoauthor(), updateMotionText(), captureVersion(), resolveConflict()
- [ ] 9.3 Create MotionCoauthorController with REST endpoints: POST /api/motion/:id/coauthor (add), DELETE /api/motion/:id/coauthor/:personId (remove), POST /api/motion/:id/text (update text, capture version), GET /api/motion/:id/history (get version history)
- [ ] 9.4 Create MotionDetailPage revision to allow edit mode if user is author or coauthor — lock if motion lifecycle is submitted or later
- [ ] 9.5 Create VersionHistoryCard — display motion text revisions with author, timestamp, diff against previous version
- [ ] 9.6 Implement conflict-free editing — when two coauthors edit simultaneously, merge non-overlapping changes; flag overlapping ranges for manual resolution
- [ ] 9.7 Create CoauthorList component — show authors + coauthors with edit/remove buttons (author only), add coauthor button with person select
- [ ] 9.8 Test co-authoring workflow with multiple governance body types

## 10. Participant Identification

- [ ] 10.1 Extend Person schema in ADR-000 with: photo (URL or file reference), officialTitle (string), partyAffiliation (string), contactMethods (array via ContactDetail relation)
- [ ] 10.2 Create ParticipantLookupService — searchByName(), searchByParty(), searchByRole()
- [ ] 10.3 Create ParticipantLookupDialog — modal search component with name/party/role filters, shows photo + title + contact on result hover
- [ ] 10.4 Update Membership schema to link to Post (formal position) — enable lookup by position (Chair, Secretary, etc.)
- [ ] 10.5 Create ParticipantCard component — displays person photo, name, role, party affiliation, contact method buttons (email, phone)
- [ ] 10.6 Add ParticipantCard to meeting participant list, motion proposer display, vote result breakdown
- [ ] 10.7 Create photo upload in PersonDetailPage — handle file attachment via FileService, store reference in photo field
- [ ] 10.8 Test participant lookup and identification across all governance domains

## 11. Integration Testing

- [ ] 11.1 Create task delegation test scenarios (PHPUnit) — create task, delegate, reclaim, expire substitute, verify OpenRegister storage
- [ ] 11.2 Test task workflow with OpenRegister schema validation — ensure schema constraints enforced (required fields, enum values, relations)
- [ ] 11.3 Create comment threading tests — create comments, verify threading, @mention resolution, parent comment links
- [ ] 11.4 Create email linking tests — link email to decision via EmailLink object, verify relation is created, verify Mail API integration
- [ ] 11.5 Test notification preference system — verify notifications sent per user preference, respect delivery method (in-app vs email)
- [ ] 11.6 Create engagement capture tests — capture speeches, questions, topics; verify OpenRegister storage and relation to Meeting
- [ ] 11.7 Test motion co-authoring — add coauthor, update text, capture version history, verify conflict resolution
- [ ] 11.8 Test workspace member access control — verify members can access workspace data, non-members cannot, permissions enforced
- [ ] 11.9 Run workflow tests for each governance domain — task delegation, voting coordination, collaboration, email evidence in 5 domains
- [ ] 11.10 Test ORI API output for new entities — verify Comment, EmailLink, Task, Delegation serialization in ORI format if applicable

## 12. Browser Testing

- [ ] 12.1 Test task delegation workflow in browser — create task, delegate, assign to person, verify UI updates, reclaim task
- [ ] 12.2 Test collaboration workspace — create faction workspace, add members, assign tasks to workspace, verify scoped visibility
- [ ] 12.3 Test discussion threads — add comments to agenda item, verify threading, @mention autocomplete, mention notification
- [ ] 12.4 Test email integration — link email to decision in Mail app sidebar, verify appears in decision dossier, verify link survives sync
- [ ] 12.5 Test notification preferences — change preferences, trigger events, verify notifications appear per setting
- [ ] 12.6 Test participant engagement capture — record speeches/questions during meeting UI, verify in minutes review, verify stats in participant profile
- [ ] 12.7 Test motion co-authoring — edit motion as coauthor, verify version history, verify conflicts flagged
- [ ] 12.8 Test participant lookup — search participants by name/party/role, verify photo and contact info display
- [ ] 12.9 Test WCAG 2.1 AA compliance for all collaboration UIs — keyboard navigation, form labels, color contrast, screen reader compatibility
- [ ] 12.10 Test across all 5 governance domain workflows — municipal council, association, corporate board, management team, citizen participation

## 13. Documentation

- [ ] 13.1 Write user docs for task delegation — how to create, delegate, reclaim tasks; how to set up substitute delegation during absence
- [ ] 13.2 Write user docs for collaboration workspaces — creating faction/committee workspaces, adding members, scoped visibility
- [ ] 13.3 Write user docs for discussion comments — how to comment, @mention, resolve discussions on agenda items and motions
- [ ] 13.4 Write user docs for email integration — linking emails to decisions, accessing mail sidebar integration, building decision dossier
- [ ] 13.5 Write user docs for notification preferences — configurable alerts, delivery methods, respecting vacation/mute modes
- [ ] 13.6 Write user docs for engagement tracking — recording speeches and questions, viewing participation stats, role in minutes
- [ ] 13.7 Write user docs for motion co-authoring — adding coauthors, editing text, viewing version history, resolving conflicts
- [ ] 13.8 Write user docs for participant identification — finding participants, accessing profiles with photos/titles, contact methods
- [ ] 13.9 Create admin configuration docs — managing notification delivery, setting default preferences, audit logging for shared workspaces
- [ ] 13.10 Include governance domain-specific guidance — examples for municipal councils, associations, corporate boards

## 14. Deduplication Check

- [ ] 14.1 Search OpenRegister services for existing task/delegation logic — verify no overlap with ObjectService task handling, document findings
- [ ] 14.2 Check @conduction/nextcloud-vue for existing comment/discussion components — verify no overlap with existing components, document reuse
- [ ] 14.3 Check existing notification implementations in other apps — verify NotificationService pattern aligns, document reuse
- [ ] 14.4 Check collaboration/workspace patterns in existing apps — verify no duplication, document new pattern
- [ ] 14.5 Verify deduplication findings in design.md "Reuse Analysis" section before code review

## 15. Accessibility & Compliance

- [ ] 15.1 Audit all new Vue components for WCAG 2.1 AA compliance — keyboard shortcuts, form labels, color contrast, screen reader
- [ ] 15.2 Test with NL Design System tokens — verify all colors, fonts, spacing use tokens from token sets (Rijkshuisstijl, Utrecht, etc.)
- [ ] 15.3 Test dark mode support — verify all components render correctly with nldesign theme switching
- [ ] 15.4 Verify i18n for all strings — check l10n/en.json and l10n/nl.json have matching keys, all UI strings translated
- [ ] 15.5 Test governance domain-specific compliance — WCAG for accessibility, ORI format for municipalities, corporate governance standards

## 16. Code Quality

- [ ] 16.1 Add SPDX headers to all new files — `// SPDX-License-Identifier: EUPL-1.2` per ADR-014
- [ ] 16.2 Run linter checks — `composer check:strict` for PHP, `npm run lint` for Vue, all pass before PR
- [ ] 16.3 Verify spec traceability — all classes have `@spec` PHPDoc tags linking to this tasks.md
- [ ] 16.4 Pre-commit verification — run all 15 checks from ADR-015 before opening PR
- [ ] 16.5 Smoke testing — call each new API endpoint with curl, verify happy path and error paths (403, 401, 400, 422)
- [ ] 16.6 Task completeness verification — re-read all tasks, verify every `[x]` task is fully implemented, not stub or TODO
