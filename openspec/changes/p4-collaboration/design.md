# Design: Collaboration

## Context

Decidesk has complete coverage of meeting lifecycle, agenda management, motions, voting, and
minutes. What it lacks is the connective tissue that lets governance participants work together
*between* and *during* meetings: task delegation, position coordination inside factions,
threaded discussion on proposals, email evidence linking, and structured engagement capture.

The change builds on the Person/Membership/GovernanceBody model from p3-governance-bodies,
the decision dossier from p2-minutes-and-decisions, and the document layer from
p3-document-management. It introduces 7 new OpenRegister schemas and extends 2 existing ones
(Motion, Person).

**Current state gaps:**
- Faction leaders have no shared workspace; they co-ordinate via email and WhatsApp
- There is no way to delegate governance tasks with a substitute during absence
- Discussion on motions happens off-platform; no record survives in the dossier
- Emails documenting decisions are disconnected from decision objects
- Speech contributions during meetings are uncaptured unless a dedicated stenographer is present
- Motion text is single-author; only co-signers are tracked, not collaborating authors

**Stakeholders:** Faction chairs (coordination), council clerks (minutes, engagement),
committee chairs (workspace, tasks), secretaries (email dossier), IT administrators
(notification delivery, register setup).

---

## Goals / Non-Goals

**Goals:**
- Task delegation with substitute-during-absence and reclaim semantics
- Task tracking with status visibility for assignee, delegator, and team
- Bounded collaboration workspaces scoped to faction, committee, or task group
- Threaded comments on agenda items, motions, amendments, and decisions
- Email-to-decision linking via Nextcloud Mail `_mail` metadata
- User-configurable notification preferences (event type × delivery method)
- Participant engagement capture (speeches, questions, topics) per meeting
- Motion co-authoring with version history and conflict flagging
- Enhanced participant profiles: official title, party affiliation, photo

**Non-Goals:**
- Real-time concurrent editing (Google Docs-style CRDT). Conflict flagging on save is sufficient.
- Independent general-purpose task/project management (tasks are governance-scoped only)
- Full email client within Decidesk (read-only linking to Nextcloud Mail)
- Proxy voting via delegation (Vote.isProxy already exists; this change does not add proxy casting)
- Video/audio conferencing
- External calendar sync for collaboration workspaces

---

## Decisions

### Decision 1: Task schema in OpenRegister, separate from ActionItem (CalDAV VTODO)

ActionItem (per ADR-002) lives in CalDAV as VTODO — it is a follow-up task from an adopted
motion, natively visible in Nextcloud Tasks. Task (this change) is a governance delegation
artifact with domain-specific state: `delegator`, `delegate`, `substitute`, `reclaimed`,
`delegatedAt`, `expiresAt`. CalDAV VTODO has no native support for substitute delegation or
the reclaim state machine.

**Alternative considered:** Extend VTODO with `X-DECIDESK-DELEGATOR`, `X-DECIDESK-SUBSTITUTE`,
`X-DECIDESK-RECLAIMED` X-properties. Rejected because the reclaim lifecycle (pending →
in-progress → completed | reclaimed) requires OpenRegister's query engine for cross-entity
relations and audit trail. Keeping both types is justified: ActionItem = post-decision
follow-up (CalDAV-native), Task = pre/during-meeting governance delegation (OpenRegister).

### Decision 2: Comment uses polymorphic target via OpenRegister relation reference

Comments are stored as OpenRegister objects with a `target` field encoding the reference as
`{register}:{schema}:{uuid}`. This allows a single Comment schema to attach to any governance
artifact (AgendaItem, Motion, Amendment, Decision) without creating entity-type-specific
schemas.

**Alternative considered:** Four separate comment schemas (AgendaItemComment, MotionComment,
etc.) or a custom REST endpoint with its own DB table. Rejected: duplicates CRUD logic and
loses OpenRegister's built-in audit trail, search, and pagination.

**Trade-off:** Target referential integrity is not enforced at the DB level (OpenRegister uses
JSON objects, not FK constraints). A soft-delete guard in CommentService validates that the
target object exists before persisting a new comment.

### Decision 3: EmailLink as a dedicated OpenRegister schema

One decision may accumulate many emails over time (correspondence from citizens, province,
advisors). A dedicated EmailLink schema stores the email metadata (UID, mailbox, sender,
subject, received timestamp, extracted snippet) plus a relation to the target Decision or
AgendaItem. OpenRegister's `_mail` metadata column on the target object provides the reverse
linkage visible in the Nextcloud Mail app sidebar.

**Alternative considered:** Embed an emails array directly on Decision. Rejected: violates the
one-to-many relationship (multiple emails per decision, each with its own metadata), and
embedding email text inside the decision object pollutes full-text search indexing.

### Decision 4: NotificationPreference as OpenRegister schema (one object per Person)

User preferences are stored as OpenRegister objects keyed by Person reference rather than in
`IAppConfig`. This enables per-user configuration to be queried by other services (e.g.
CommentService checks NotificationPreference before dispatching mention alerts) and leverages
OpenRegister's built-in permission model.

**Alternative considered:** Nextcloud `IAppConfig` with per-user namespace. Rejected because
IAppConfig is not easily queryable across users by server-side services, and it lacks the
relation capabilities needed to attach preferences to a Person entity.

Delivery is handled by `NotificationService` (Nextcloud notification API, for in-app) and
`MailerService` (for email), both provided by OpenRegister — no custom notification dispatcher.

### Decision 5: EngagementRecord aggregates Speech entities for per-meeting analytics

Speech (ADR-000, Popolo) captures individual formal speech transcripts with start/end times and
audio/video URLs. EngagementRecord aggregates engagement data for a single participant across
one full meeting: total speaking duration, count of speeches, questions raised, topics
suggested, and a derived engagement score. EngagementRecord references Speech objects via
OpenRegister relations rather than duplicating speech text.

**Alternative considered:** Add aggregation fields directly to Speech. Rejected: a Speech is a
single utterance; meeting-level aggregation is a separate concern. EngagementRecord is the
meeting-participant intersection object that drives the minutes summary view.

### Decision 6: CollaborationWorkspace with OpenRegister RBAC, not Nextcloud Circles

CollaborationWorkspace stores workspace metadata and member list in OpenRegister. Access
control uses `AuthorizationService` with workspace-level policies (owner, editor, viewer).

**Alternative considered:** Use Nextcloud Circles/Teams as the workspace container. Rejected
because Circles are independent from OpenRegister objects; tasks and agenda items scoped to a
workspace need to live in the same data store to be queryable via OpenRegister relations. A
custom schema keeps everything co-located and avoids a cross-app sync layer.

### Decision 7: Motion co-authoring with inline versionHistory array, not a separate entity

Motion.versionHistory stores an array of snapshots `{author, timestamp, text, summary}` inline
on the Motion object. OpenRegister's built-in audit trail already provides field-level change
history; versionHistory adds explicit named snapshots with human-readable summaries.

**Alternative considered:** Separate MotionVersion entity. Rejected: versions are tightly bound
to a single motion and have no independent lifecycle; a separate entity would add a join for a
common read path. Snapshots are append-only (max ~20 per motion in practice) so inline storage
is acceptable.

Conflict resolution strategy: paragraph-level locking (last writer wins per paragraph segment;
overlapping edits on the same segment are flagged before save, not merged automatically). This
avoids CRDT complexity while covering the common case where two authors work on different parts.

### Decision 8: Person.officialTitle and Person.partyAffiliation as denormalized convenience fields

The canonical party affiliation is `Membership.party` (Popolo `on_behalf_of`). Displaying a
participant's party in meeting views and search results would require a Membership join on every
Person render. Person.partyAffiliation is a denormalized convenience field refreshed whenever
a Person's active Membership is updated. It is explicitly non-authoritative.

Similarly, `officialTitle` (e.g. "Wethouder Verkeer en Openbare Ruimte") corresponds to the
current `Post.label` for the Person's most senior active Membership, but is stored directly
on Person for quick display.

**Implication:** ParticipantService must keep these fields in sync when Membership or Post
changes. Documented as eventual consistency; the canonical values remain on Membership/Post.

---

## Reuse Analysis

The following OpenRegister and `@conduction/nextcloud-vue` capabilities are leveraged directly.
No custom equivalents are built.

| Concern | Platform capability used | Location |
|---|---|---|
| CRUD for all new schemas | `ObjectService.saveObject()` / `deleteObject()` | OpenRegister |
| Notification dispatch (in-app) | `NotificationService` | OpenRegister |
| Notification dispatch (email) | `MailerService` | OpenRegister |
| Workspace access control | `AuthorizationService` + `PropertyRbacHandler` | OpenRegister |
| Photo upload for Person | `FileService` + `CnObjectSidebar` → Files tab | OpenRegister / @conduction/nextcloud-vue |
| Audit trail on all objects | `AuditTrailService` (automatic) | OpenRegister |
| Task display in sidebars | `CnTasksCard` inside `CnObjectSidebar` | @conduction/nextcloud-vue |
| Pinia stores for new entities | `createObjectStore` | @conduction/nextcloud-vue |
| List/detail pages | `CnIndexPage` + `CnDetailPage` + `useListView` | @conduction/nextcloud-vue |
| Workflow stage visualization | `CnTimelineStages` | @conduction/nextcloud-vue |
| Dashboard KPI widgets | `CnStatsBlock` + `CnDashboardPage` | @conduction/nextcloud-vue |
| Full-text search | `IndexService` | OpenRegister |
| Schema-driven forms | `CnFormDialog` / `CnAdvancedFormDialog` | @conduction/nextcloud-vue |

**Deduplication findings:**
- OpenRegister's `TasksController` handles generic workflow tasks; governance delegation Task
  schema is distinct (domain-specific state machine). No overlap.
- `@conduction/nextcloud-vue` has no existing comment/discussion component — CommentThread and
  CommentCard are new.
- NotificationService in OpenRegister provides delivery; NotificationPreference schema is a new
  governance-specific preference object, not a duplicate of any existing notification system.

---

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Polymorphic comment target has no FK constraint — orphaned comments if target deleted | CommentService validates target UUID exists before save; orphaned comments are filtered on read via soft-delete check |
| Email integration requires Nextcloud Mail app to be installed | EmailDossierCard and EmailLinkService degrade gracefully: show empty state and disable link button if `mail` app is absent |
| EngagementRecord quality depends on clerk data entry during live meeting | Pre-populate Speech-linked records automatically; SpeechCaptureDialog defaults to current agenda item and time; bulk entry at end of meeting |
| Motion co-authoring paragraph conflicts may frustrate collaborators | Version history allows chair to restore any snapshot; overlapping edit detected before save with diff shown |
| Person.partyAffiliation/officialTitle drift from canonical Membership/Post | ParticipantService emits denormalization refresh on Membership or Post update event; eventual consistency documented in ADR |
| CollaborationWorkspace RBAC adds a new permission scope — risk of data leak if misconfigured | Workspace access defaults to `private`; all workspace-scoped queries enforce AuthorizationService checks before returning objects |
| 7 new schemas increase decidesk_register.json import time | Repair step is idempotent; import skips unchanged schemas via version_compare; staging environment import tested before deploy |

---

## Seed Data

Seed objects are loaded via `ConfigurationService::importFromApp()` in the repair step.
Slugs are unique human-readable identifiers used for idempotent re-import matching.
All person names, addresses, and identifiers are fictional but distinguishable from real data.

### Task

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Task", "slug": "task-verkeersveiligheid-review" },
    "title": "Raadsvoorstel verkeersveiligheid Leidsestraat reviewen",
    "description": "Controleer juridische grondslag en bekijk inspraakreacties voor raadsvergadering 22 mei 2026",
    "taskStatus": "pending",
    "dueDate": "2026-05-19T17:00:00Z",
    "delegatedAt": "2026-04-28T09:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Task", "slug": "task-amendement-medeondertekening" },
    "title": "Amendement A-2026-017 controleren en medeondertekenen",
    "description": "Beoordeel of amendement A-2026-017 in lijn is met het fractiestandpunt over woningbouw",
    "taskStatus": "in-progress",
    "dueDate": "2026-05-05T12:00:00Z",
    "delegatedAt": "2026-04-30T10:15:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Task", "slug": "task-presidium-agenda-mei" },
    "title": "Agenda presidiumvergadering 14 mei 2026 opstellen",
    "description": "Verzoeken van commissies bundelen en prioriteren voor presidiumbespreking",
    "taskStatus": "completed",
    "dueDate": "2026-05-10T10:00:00Z",
    "completedAt": "2026-05-09T16:45:00Z",
    "delegatedAt": "2026-04-20T08:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Task", "slug": "task-notulen-commissie-april" },
    "title": "Notulen commissievergadering Ruimtelijke Ordening 8 april goedkeuren",
    "description": "Controleer nauwkeurigheid van de verslaglegging en geef akkoord namens de voorzitter",
    "taskStatus": "pending",
    "dueDate": "2026-04-25T17:00:00Z",
    "delegatedAt": "2026-04-22T13:00:00Z"
  }
]
```

### Delegation

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Delegation", "slug": "delegation-de-vries-vakantie-mei" },
    "delegatedAt": "2026-04-28T09:00:00Z",
    "expiresAt": "2026-05-16T17:00:00Z",
    "status": "active"
  },
  {
    "@self": { "register": "decidesk", "schema": "Delegation", "slug": "delegation-vermeer-ziek-april" },
    "delegatedAt": "2026-04-10T08:00:00Z",
    "expiresAt": "2026-04-22T17:00:00Z",
    "status": "expired"
  },
  {
    "@self": { "register": "decidesk", "schema": "Delegation", "slug": "delegation-commissievoorzitter-secretaris" },
    "delegatedAt": "2026-03-01T09:00:00Z",
    "expiresAt": null,
    "status": "active"
  }
]
```

### Comment

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Comment", "slug": "comment-motie-m-2026-014-artikel3" },
    "text": "De formulering van artikel 3 behoeft aanscherping. Ik stel voor: 'de gemeente stelt uiterlijk 1 september 2026 een uitvoeringsplan vast'.",
    "createdAt": "2026-04-10T14:23:00Z",
    "updatedAt": "2026-04-10T14:23:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Comment", "slug": "comment-agendapunt-parkeergarage-vraag" },
    "text": "Is de financiële paragraaf al afgestemd met de wethouder? @Maaike kun jij dit bevestigen vóór de raadsvergadering?",
    "createdAt": "2026-04-12T09:05:00Z",
    "updatedAt": "2026-04-12T09:05:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Comment", "slug": "comment-besluit-b-2026-031-reactie" },
    "text": "Eens met de bovenstaande kanttekening. Het besluit heeft een uitvoeringstermijn van 6 weken nodig, niet 4.",
    "createdAt": "2026-04-13T16:30:00Z",
    "updatedAt": "2026-04-13T16:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Comment", "slug": "comment-amendement-a-2026-009-akkoord" },
    "text": "Fractie akkoord met gewijzigde tekst na aanpassing van de financieringsclausule.",
    "createdAt": "2026-04-15T11:20:00Z",
    "updatedAt": "2026-04-15T11:20:00Z"
  }
]
```

### EmailLink

```json
[
  {
    "@self": { "register": "decidesk", "schema": "EmailLink", "slug": "emaillink-bezwaar-besluit-b-2026-042" },
    "subject": "Bezwaarschrift tegen verkeersbesluit Leidsestraat – kenmerk 2026-BW-0083",
    "from": "j.bakker@example.nl",
    "receivedAt": "2026-04-08T11:45:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "EmailLink", "slug": "emaillink-subsidiebrief-provincie" },
    "subject": "Toekenning subsidie Klimaatfonds 2026 – ref PZH-2026-0412",
    "from": "subsidies@pzh.nl",
    "receivedAt": "2026-03-25T14:10:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "EmailLink", "slug": "emaillink-urgentie-commissie" },
    "subject": "Urgentieverzoek behandeling bestemmingsplan Meerwijkzone",
    "from": "griffie@gemeentehollandia.nl",
    "receivedAt": "2026-04-02T08:55:00Z"
  }
]
```

### NotificationPreference

```json
[
  {
    "@self": { "register": "decidesk", "schema": "NotificationPreference", "slug": "notifpref-de-vries" },
    "meetingCreated": true,
    "votingOpened": true,
    "decisionPublished": true,
    "taskAssigned": true,
    "commentMention": true,
    "deliveryMethod": "in-app"
  },
  {
    "@self": { "register": "decidesk", "schema": "NotificationPreference", "slug": "notifpref-vermeer" },
    "meetingCreated": false,
    "votingOpened": true,
    "decisionPublished": true,
    "taskAssigned": true,
    "commentMention": true,
    "deliveryMethod": "email"
  },
  {
    "@self": { "register": "decidesk", "schema": "NotificationPreference", "slug": "notifpref-secretaris-griffie" },
    "meetingCreated": true,
    "votingOpened": true,
    "decisionPublished": true,
    "taskAssigned": true,
    "commentMention": true,
    "deliveryMethod": "both"
  }
]
```

### EngagementRecord

```json
[
  {
    "@self": { "register": "decidesk", "schema": "EngagementRecord", "slug": "engagement-raad-20260408-pietersen" },
    "speakingDuration": 2700,
    "engagementScore": 78,
    "questionsRaised": [],
    "topicsSuggested": []
  },
  {
    "@self": { "register": "decidesk", "schema": "EngagementRecord", "slug": "engagement-raad-20260408-de-vries" },
    "speakingDuration": 720,
    "engagementScore": 55,
    "questionsRaised": [],
    "topicsSuggested": []
  },
  {
    "@self": { "register": "decidesk", "schema": "EngagementRecord", "slug": "engagement-commissie-ro-20260415-voorzitter" },
    "speakingDuration": 4800,
    "engagementScore": 92,
    "questionsRaised": [],
    "topicsSuggested": []
  }
]
```

### CollaborationWorkspace

```json
[
  {
    "@self": { "register": "decidesk", "schema": "CollaborationWorkspace", "slug": "workspace-fractie-vvd" },
    "name": "Fractie VVD",
    "type": "faction",
    "purpose": "Interne fractiecoördinatie voor raadsvergaderingen: standpuntbepaling, woordvoerdersplanning en motie-strategie",
    "accessLevel": "private",
    "createdAt": "2026-01-15T10:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "CollaborationWorkspace", "slug": "workspace-commissie-ro" },
    "name": "Commissie Ruimtelijke Ordening",
    "type": "committee",
    "purpose": "Coördinatie commissievergaderingen, agendavoorbereiding en behandeling bestemmingsplannen",
    "accessLevel": "restricted",
    "createdAt": "2026-01-20T09:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "CollaborationWorkspace", "slug": "workspace-presidium" },
    "name": "Presidium",
    "type": "task-group",
    "purpose": "Agendacoördinatie raadsperiode, toewijzing hamerstukken en bespreekstukken, en presidiumcommunicatie",
    "accessLevel": "restricted",
    "createdAt": "2026-01-08T08:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "CollaborationWorkspace", "slug": "workspace-werkgroep-participatie" },
    "name": "Werkgroep Burgerparticipatie 2026",
    "type": "task-group",
    "purpose": "Uitwerking participatietraject omgevingsvisie: projectdossier, tijdlijn, stakeholdercontacten",
    "accessLevel": "restricted",
    "createdAt": "2026-02-10T13:00:00Z"
  }
]
```

---

## Migration Plan

1. **Register update** — add 7 new schema definitions and extended Person/Motion schemas to
   `lib/Settings/decidesk_register.json`. New fields on Person and Motion are optional
   (non-breaking). No existing objects are invalidated.
2. **Repair step** — run `ConfigurationService::importFromApp()` in the app's repair step.
   Import is idempotent: re-running on an already-configured register is a no-op.
3. **Seed data** — loaded alongside schemas via the same repair step import. Existing objects
   matched by slug are skipped.
4. **No data migration required** — all new schemas start empty; existing objects are not
   transformed. Person.officialTitle and Person.partyAffiliation default to null.
5. **Rollback** — deleting schema definitions from the register leaves existing OpenRegister
   objects in place (orphaned but harmless). A rollback removes UI access without data loss.

---

## Open Questions

1. **EngagementRecord and Speech relation** — Should EngagementRecord.speeches be an array of
   Speech UUIDs (OpenRegister relation) or embed speech summaries inline? Inline is simpler
   but duplicates data if Speech objects are edited post-meeting.
2. **Workspace task visibility** — Should Tasks created inside a CollaborationWorkspace be
   invisible to non-members, even if they are assigned to that non-member person? This has
   implications for the "My Tasks" dashboard widget.
3. **EmailLink auto-detection** — Should reference detection (regex match against decision
   identifiers in email subject/body) run as a background job on inbox sync, or only on
   manual trigger? Background job has privacy implications (scanning all incoming mail).
4. **Notification vacation mode** — How does vacation/mute mode interact with task assignment
   notifications? Does the substitute receive the notification instead?
