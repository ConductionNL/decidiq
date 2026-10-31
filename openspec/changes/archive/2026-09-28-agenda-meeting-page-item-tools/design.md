# Design: agenda-meeting-page-item-tools

Read at decidiq development `4d7430ff`.

## Where things are today

| Piece | Where | What it does |
|---|---|---|
| Meeting page | `src/manifest.json:561` `MeetingDetail` (`/meetings/:id`), widget `meeting-agenda` is the custom `MeetingAgendaTab` | Lists items in tree order, add, edit, delete, assemble package |
| Agenda widget | `src/components/tabs/MeetingAgendaTab.vue` | Header comment (`:4-15`) says drag-reorder is left out; row click opens the edit form (`:106` `@rowClick="openEdit"`); row actions are Edit and Delete (`:253-270`) |
| Drag builder | `src/components/AgendaBuilder.vue:112-128` (draggable rows, arrow-key moves), `:592` renumbers, `:604-620` `PUT /api/agendas/{meetingId}/reorder` | Chair only, mounted once |
| Live screen | `src/manifest.json:720` `LiveMeeting` (`/meetings/:id/live`, custom `LiveMeetingView`, `src/registry.js:161`); `src/views/LiveMeeting.vue:93` mounts `AgendaBuilder` when `isChair`, `:191` mounts `MinutesPanel` when `canTakeMinutes`; `isChair` (`:356`) looks for a participant with role `chair` | Nothing in `src/` links to it |
| Reorder endpoint | `appinfo/routes.php:157` `agenda#reorder`, `lib/Controller/AgendaController.php:250` `reorder()` behind `denyUnlessChairOrAdmin` (`:83`), which calls `lib/Service/AgendaAuthorizationGuard.php:94` `requireChairOrAdmin()`: admin, or `ParticipantResolver::hasRole(meetingId, uid, ['chair', 'secretary'])` | `lib/Service/AgendaService.php:373` `reorderItems()` renumbers and sends one change notice |
| Agenda item page | `src/manifest.json:821` `AgendaItemDetail` (`/agenda-items/:id`), widget `agenda-files` is OpenRegister's `files` integration | Where per-item documents are attached |
| Minutes page | `src/manifest.json:1022` `MinutesDetail` (`/minutes/:id`), widgets data, approval, documents, signers, publication | No per-item editor |
| Per-item editor | `src/components/minutesEditor/MinutesPanel.vue` (props `meetingId`, `agendaItems`, `participants`; autosaves `minutes.itemNotes`) | Mounted only on the live screen |
| Minutes schema | `lib/Settings/decidesk_register.json:2990` `Minutes`, property `meeting` (uuid, one-to-one) | Links minutes to their meeting |
| Role check | `lib/Service/ParticipantResolver.php:261` `hasRole()` | The one resolver the server guards use |

## Approach

1. **Roles from the server.** Add `GET /api/meetings/{meetingId}/my-roles` on a small controller method that returns `{ chair, secretary, admin }` booleans from `ParticipantResolver::hasRole()` and `IGroupManager::isAdmin()`. It is `#[NoAdminRequired]` and answers only about the caller, so there is no object to guard beyond the signed-in user. The agenda widget and the minutes widget call it once on load. This replaces the page guessing from the participants list, which is what makes the live screen recognise nobody (decidiq#1374). The sibling change `voting-chair-close-and-amendment-rounds` adds `GET /api/meetings/{meetingId}/voting-permissions` for the voting controls on the same principle; both read `ParticipantResolver::hasRole()` so they cannot disagree, and whichever change is built second checks whether one endpoint can answer both before adding its own.
2. **Reorder in the agenda widget.** When the caller is chair, secretary or admin, `MeetingAgendaTab` renders a drag handle and Move up and Move down row actions. It reuses `flattenTree` and `buildAgendaTree` from `src/services/agendaRules.js` so sub-items move with their parent, exactly as `AgendaBuilder.applyTreeOrder()` does, and saves through the existing `PUT /api/agendas/{meetingId}/reorder`. No new endpoint, no new schema field.
3. **Open the item.** Add an Open row action (`EyeOutline`) that routes to `AgendaItemDetail` with the row id, and make it the primary action. The row click keeps opening the edit form, so nothing a clerk relies on today moves.
4. **Open the live screen.** Add an Open live meeting button to the agenda widget header for chair, secretary or admin, routing to `LiveMeeting` with the meeting id. It sits next to Assemble meeting package.
5. **Minutes per item on the minutes page.** Add a custom widget `minutes-item-notes` to `MinutesDetail` that mounts `MinutesPanel` with the minutes' `meeting`, that meeting's regular agenda items and its participants. The panel already finds the draft minutes by meeting and autosaves `itemNotes`; the widget is a thin wrapper that loads the three props. It is editable only while the minutes lifecycle is draft, and read-only after submission, because approved minutes must not change under the signers.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Reorder | Existing imperative endpoint, reused | Renumbering many objects in one call is already `AgendaService::reorderItems()`; no dialect expresses an ordered bulk write |
| Role answer | Imperative, one read-only method | It reads meeting roles across Participant and Membership through `ParticipantResolver`; no `x-openregister-*` extension answers "which roles does the caller hold on this meeting" |
| Widgets and actions | Declarative manifest edits (`src/manifest.json`) plus the two custom components | Pages are manifest pages already |

No lifecycle, aggregation, calculation or notification is added.

## Seed data

No schema changes. The existing example sets already seed meetings with agenda items and a draft minutes record per profile (`lib/Settings/profiles/*.json`), which is enough to exercise all three tools.

## Files

- `appinfo/routes.php`, `lib/Controller/MeetingController.php` (new `myRoles()` method), `lib/Service/ParticipantResolver.php` (read only)
- `src/components/tabs/MeetingAgendaTab.vue`, `src/components/tabs/MinutesItemNotesTab.vue` (new wrapper), `src/registry.js`, `src/manifest.json` (`MeetingDetail` untouched in layout, `MinutesDetail` gets one widget and one layout row)
- `tests/Unit/Controller/MeetingControllerTest.php`, `tests/vitest/meetingAgendaReorder.spec.js`, `tests/e2e/meeting-agenda-tools.spec.ts`
