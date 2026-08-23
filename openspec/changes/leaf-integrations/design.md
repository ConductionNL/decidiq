# Design: leaf-integrations

## Context

Two mechanisms, both already live in decidiq's stack, carry every integration in this change:

1. **Manifest integration widgets** — `{"type": "integration", "integrationId": "<leafId>"}`
   inside a detail page's widget list. decidiq's `main.js` calls
   `registerBuiltinIntegrations()`, so the full builtin leaf set from
   `nextcloud-vue/src/integrations/builtin/leaves.js` is resolvable. Verified leaf entries
   (with their `requiredApp` gates):
   `calendar`→`calendar`, `contacts`→`contacts`, `polls`→`polls`, `forms`→`forms`.
2. **Schema `configuration`** (OpenRegister `lib/Db/Schema.php`):
   - `linkedTypes` (array of registry ids, validated by `validateLinkedTypesValue()` against
     `IntegrationRegistry::listIds()` + a legacy allow-list) — the Mail sidebar lists only
     schemas whose `configuration.linkedTypes` includes the mail linked type
     (`ActionsTab.vue`, "Filter to schemas with mail in linkedTypes"), and
     `LinkedEntityService` enforces the same list server-side.
   - `mailObjectTemplate` (field map; string values may use `{{subject}}`/`{{sender}}`/
     `{{senderName}}`/`{{date}}`/`{{date30}}`/`{{datetime}}`/`{{preview}}`/`{{messageId}}`/
     `{{mailRef}}`; non-string values pass through verbatim) — only schemas declaring the
     template get a create-from-email button.

## Verified current state (2026-08-18)

| Leaf | Where decidiq uses it today |
|---|---|
| `files` | ×10 in `src/manifest.json` (GovernanceBodyDetail, MeetingDetail, MeetingIntegrations, DecisionIntegrations, AgendaItemIntegrations, ParticipantDetail, AgendaItemDetail, MotionDetail, DecisionDetail, ActionItemDetail) + ~20 more in `manifest.d/` fragments |
| `email` | ×2 (`di-email` on DecisionIntegrations, `ai-email` on AgendaItemIntegrations) |
| `deck` | ×2 (`mi-deck` on MeetingIntegrations, `di-deck` on DecisionIntegrations) |
| `talk` | ×1 (`mi-talk` on MeetingIntegrations) |
| `notes` | ×1 (`mi-notes` on MeetingIntegrations) |
| `tasks` | ×1 (`ai-tasks` on AgendaItemIntegrations) |
| `linkedTypes` / `mailObjectTemplate` | **zero** declarations anywhere in `lib/Settings/` |

`collectives` and `analytics` are owned by existing capabilities
(`faction-workspace-via-collectives-leaf`, `governance-analytics-via-analytics-leaf`) and are
not re-touched here.

## Decisions

### D1: Calendar binds to the meeting, not to action items

`Meeting` is already typed `schema:Event` (`x-openregister.schemaType`) and carries the full
event shape: `scheduledDate` (required), `endDate`, `location`, `virtualLocation`,
`eventAttendanceMode`, `meetingMode`. The calendar leaf renders on **MeetingIntegrations**
next to the existing deck/talk/files/notes row.

Action-item deadlines get **no** calendar widget: `ActionItem` is a read-only projection over
CalDAV VTODOs (`x-openregister-object-source: {provider: "caldav-vtodo", readOnly: true}`),
so `dueDate` is already CalDAV-native — visible in the Tasks app and via the existing
`ai-tasks` leaf. Rendering VTODOs a second time through the calendar leaf would create two
surfaces for one record with no new information.

### D2: Contacts binds to ParticipantDetail and GovernanceBodyDetail

The person-shaped data lives on `Person` (`email`, `contactDetails`) and the dedicated
`ContactDetail` schema (`type`, `value`, `label`, `note`, `validFrom`, `validUntil`, refs
`person` / `governanceBody`). But the manifest has **no PersonDetail page** — the people
surface that exists is `ParticipantDetail` (with `Participant.email`,
`Participant.nextcloudUserId`), and bodies have `GovernanceBodyDetail`. The leaf therefore
lands on those two pages. When a person-level page is introduced (the `participant` schema is
deprecated in favour of Person + Membership, per decidiq-mcp-adoption D2), the widget moves
with it — a one-line manifest edit, deliberately not blocked on that UI change.

### D3: Polls are advisory, upstream of the formal voting system

decidiq owns a formal, guarded voting pipeline (`VotingRoundOpener` → `VoteCastingService` →
`VotingRoundCloser`, plus `CitizenVote`/`CitizenPanel` for participatory processes). The
polls leaf must not blur into it:

- Placement: `ConsultationDetail` (the citizen-participation surface;
  `PublicConsultation.votingEnabled` marks consultations that will later vote formally) and
  `DecisionIntegrations` (straw-poll a draft decision before the chair opens a round).
- The poll is a linked NC Polls object; its result is **advisory input**, hand-carried by a
  human into the formal process. The leaf never creates or mutates `VotingRound`, `Vote` or
  `CitizenVote` objects — enforced by construction, since the leaf has no decidiq write path
  at all.
- Widget title: "Straw poll", so the UI itself states the non-binding nature.

### D4: Forms feed the existing reaction-intake path

The forms leaf on `ConsultationDetail` links an NC Forms form as the structured intake
channel of a consultation. Response import lands as `ConsultationReaction` objects
(`body`, `moderationStatus`, `submitterId`, `submittedAt`, `consultation` ref) through the
existing `ReactionIntakeService` moderation flow — form responses enter as
`moderationStatus: pending` like any other reaction, so the ModerationQueue page needs no
change. v1 imports on demand (a "import responses" action on the widget); scheduled sync is
deferred.

### D5: Mail sidebar — four linkable schemas, one creatable

`linkedTypes` goes on exactly the schemas that already render an email or files surface and
that a mail plausibly concerns: `Meeting`, `Decision`, `AgendaItem`, `ActionItem`. That makes
the existing `di-email`/`ai-email` tabs finally two-sided: the Mail sidebar can create the
link those tabs display.

`mailObjectTemplate` goes on **`Decision` only**:

```json
"configuration": {
  "linkedTypes": ["<mail id per registry listIds()>", "files"],
  "mailObjectTemplate": {
    "title": "{{subject}}",
    "text": "{{preview}}",
    "externalReference": "{{mailRef}}",
    "lifecycle": "draft",
    "decisionType": "resolution"
  }
}
```

- `lifecycle: "draft"` is a non-string-free verbatim value → a mail-born decision is inert
  until a human advances it (no publication, no voting, no lifecycle notification).
- `ActionItem` gets `linkedTypes` (linking a mail to an existing action item is a read-side
  association) but **must not** get `mailObjectTemplate`: create-from-email writes through
  `ObjectService::saveObject()`, which the read-only VTODO projection rejects — the identical
  constraint that shaped decidiq-mcp-adoption D3. The spec pins this so a future change
  cannot "helpfully" add it and ship a permanently broken button.
- The exact mail linked-type id is resolved in the task against
  `IntegrationRegistry::listIds()` + `legacyLinkedTypeIds()` at implementation time (the
  registry is the validator; guessing the string here would be the denylist-rot mistake).

### D6: Alternatives considered

- **Build bespoke tabs (a CalendarTab.vue, ContactsTab.vue…) instead of leaves.** Rejected:
  ADR-019 exists so domain apps declare, not build; the deck leaf precedent shows the
  registry path costs one JSON line per surface.
- **Put contacts on every page that shows a person ref.** Rejected: leaf sprawl; the two
  people-centric pages cover the ask, and refs elsewhere already deep-link.
- **Wire `linkedTypes` on all 39 schemas.** Rejected: the Mail sidebar's schema picker is a
  selection UI; 39 entries would bury the four that matter (same dilution logic as
  decidiq-mcp-adoption D1).
- **`x-openregister.mailEnabled: true` instead of `linkedTypes`.** Rejected: the Mail sidebar
  filters on `configuration.linkedTypes` (verified in `ActionsTab.vue`), not on the
  `mailEnabled` flag decidiq's schemas currently carry as `false`.

## Rollback

Remove the widgets and the two `configuration` keys, bump the register version, re-import.
Poll/form/calendar/contact links live in the leaf apps and OpenRegister's link store, not in
decidiq schemas — no decidiq data migration in either direction.
