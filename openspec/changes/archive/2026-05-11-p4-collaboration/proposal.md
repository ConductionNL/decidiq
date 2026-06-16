# Proposal: Collaboration

## Why

Decidesk currently handles governance meetings, motions, voting, and minutes for individual governance bodies, but lacks collaborative features that enable team and faction members to work together effectively on governance activities. Decision-makers face fragmented workflows: faction leaders cannot easily coordinate voting positions across committees; team members cannot track delegated tasks; participants cannot comment on agenda items or motions; emails documenting decisions are disconnected from the decision dossier. This proposal adds collaboration, delegation, and communication capabilities that connect people, tasks, and decision artifacts into a unified workspace aligned with governance workflows.

## What Changes

- **Task delegation framework** — Enable members to delegate governance-related tasks to colleagues with optional time-bound absence delegation (substitute during vacation/sick leave)
- **Task tracking and reclamation** — Track status of delegated tasks; original assignee can reclaim tasks if needed
- **Shared workspace for factions and committees** — Enable faction leaders and committee chairs to coordinate positions, voting strategy, and task assignments in a bounded collaborative space
- **Discussion and comments** — Add threaded comments to agenda items, motions, amendments, and decisions; enable governance discussions without external tools
- **Email integration** — Link incoming emails to decision objects via metadata; populate decision dossier with supporting correspondence; one-click integration with Nextcloud Mail app
- **Notification preferences** — Configurable alerts for meetings, votes, decisions, task assignments, and mentions; respect user availability settings (vacation, mute modes)
- **Participant engagement tracking** — Record who spoke, suggested topics, raised questions; track participation metrics per person per meeting
- **Motion co-authoring** — Multiple participants collaborate on motion text with version history and conflict resolution
- **Participant identification** — Enhanced participant profiles with photos, roles, party affiliation, contact details; one-click lookup during meetings

## Capabilities

### New Capabilities

- `task-delegation`: Create and manage delegated tasks with optional substitute delegation during absence; enable task reclamation by original assignee
- `task-tracking`: Track status of delegated tasks; progress visibility for assignee, delegator, and team
- `collaboration-workspace`: Shared bounded workspace for faction/committee coordination—agenda planning, position tracking, task management
- `discussion-and-comments`: Threaded comments on agenda items, motions, amendments, and decisions with @mentions and conflict-free editing
- `email-integration`: Link emails to decisions via Nextcloud Mail metadata; display in decision dossier; sync back to Mail sidebar
- `notification-preferences`: User-configurable alerts for meetings, votes, decisions, task assignments, mentions; respect mute/vacation modes
- `participant-engagement-tracking`: Capture contributions (speeches, questions, suggestions, topics) per participant per meeting; track engagement metrics
- `motion-coauthoring`: Multi-person collaborative editing of motion text with version history, conflict resolution, and attribution
- `participant-identification`: Enhanced participant profiles with photos, official titles, party affiliation, contact methods; quick lookup UI

### Modified Capabilities

- `meeting-management`: Add participant engagement capture (speeches, questions) during meeting lifecycle; integrate with discussion-and-comments
- `governance-bodies`: Extend Person, Membership, ContactDetail with enhanced identification fields (photo, official title, party affiliation)

## Impact

- **Core entities modified:** Person (photo, official title, party affiliation fields); Membership (enhanced role/position tracking); Meeting (participant engagement capture); DigitalDocument (new email metadata fields)
- **New entities introduced:** Task (governance-specific follow-up tasks), Delegation (task delegation with substitute), Comment (threaded discussions), EmailLink (email-to-decision mapping), NotificationPreference, EngagementRecord, CollaborationWorkspace
- **APIs affected:** New endpoints for task/delegation CRUD, comment management, workspace coordination, notification subscription; email integration via Nextcloud Mail API
- **Dependencies:** OpenRegister for new entity storage; Nextcloud Mail app for email linking; Nextcloud Tasks app for task visualization; CalDAV for meeting context
- **Workflow changes:** Governance bodies now support internal task delegation loops; faction coordination happens in bounded workspaces; decision dossiers auto-populate with email evidence; meeting minutes capture participant contributions
- **Governance domain validation required:** Test task delegation, co-authoring, and workspace coordination across all 5 governance domains (legislative, association, corporate governance, corporate operations, citizen participation)
