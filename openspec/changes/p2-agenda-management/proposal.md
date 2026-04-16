## Why

Governance bodies — municipal councils, water boards, corporate boards, and associations — depend on well-structured meeting agendas to conduct legitimate proceedings. Without digital agenda management, secretaries manually assemble agendas in word processors, distribute them via email, and track amendments by hand. This change addresses the highest-demand cluster of p2 capabilities: Agenda Builder and Board Pack Publishing Workflow (demand 263), Digital Agenda Distribution (demand 302), Publish complete agenda package (demand 192), Process consent agenda items / hamerstukken (demand 184), Declare conflict of interest for agenda item (demand 185), Track BOB phase per agenda item (demand 142), Agenda amendments (demand 137), and Recurring agenda items (demand 137). All capabilities build on the `AgendaItem` entity delivered in p1-crud-operations and require no schema additions.

## What Changes

- **New**: Drag-and-drop agenda builder with `orderNumber`-driven reordering, estimated duration per item, item type classification (informational / discussion / decision), and recurring item support via the `isRecurring` flag
- **New**: Propose items for the agenda (demand 98) — participants can submit agenda item proposals that the chair reviews and accepts or rejects before publication
- **New**: Assign spokespersons per agenda item (demand 103) — secretary assigns a presenter to each AgendaItem via an OpenRegister relation to Participant
- **New**: Agenda publication workflow (demand 137) — secretary publishes a complete agenda package (AgendaItem list + file attachments) before the meeting; Meeting lifecycle transitions from `scheduled` to `published`
- **New**: Digital Agenda Distribution (demand 302) — distribute agenda to participants via `NotificationService` and `CalDavService` (ADR-002) on publish
- **New**: Attach financial documents and supporting files to agenda items (demand 213) via OpenRegister built-in `FileService`
- **New**: Live agenda amendments during an open meeting (demand 137) — chair can add, remove, and reorder AgendaItems while Meeting lifecycle is `opened`, with changes broadcast in real-time
- **New**: Consent agenda processing / hamerstukken (demand 184) — batch-adopt decision items without individual debate; items tagged `hamerstuk` are grouped and adopted by a single chair action
- **New**: BOB phase tracking per agenda item (demand 142) — track Beeldvorming → Oordeelsvorming → Besluitvorming via OpenRegister built-in `status` field; chair advances phase during the meeting
- **New**: Declare conflict of interest per agenda item (demand 185) — participants record COI against a specific AgendaItem via OpenRegister built-in notes; chair sees all COI declarations before the item is discussed
- **New**: Link Motion to AgendaItem (demand 175) — decision-type agenda items can have one or more Motions linked via OpenRegister relation

## Capabilities

### New Capabilities

- `agenda-builder`: Create and organize AgendaItem objects with drag-and-drop reordering, time allocation, item type selection, recurring item support, spokesperson assignment, and proposal workflow for participant-submitted items
- `agenda-publication`: Publish a complete agenda package for a Meeting — distributes agenda to all participants via Nextcloud notifications and calendar events; supports file attachments per agenda item
- `agenda-live-management`: Live amendments during an open meeting (add/remove/reorder with chair privilege check); consent agenda (hamerstukken) batch processing; BOB phase progression tracking per item via `status` field
- `conflict-of-interest`: Participants declare conflict of interest against specific AgendaItems via structured OpenRegister notes; chair dashboard shows all active COI declarations for a meeting

### Modified Capabilities

- `agenda-item-crud` *(from p1-crud-operations)*: Extend AgendaItemDetail view to show linked Motions, BOB phase status badge, COI note count, and spokesperson name

## Impact

- Uses only the existing `AgendaItem` entity from ADR-000 — no schema changes required
- BOB phase stored in OpenRegister built-in `status` field (allowed values: `beeldvorming`, `oordeelsvorming`, `besluitvorming`, `afgerond`)
- Consent flag stored via OpenRegister built-in `tags` (tag value: `hamerstuk`)
- COI declarations stored via OpenRegister built-in `notes` on AgendaItem (structured with `type: conflict-of-interest` in the note body)
- Spokesperson stored as an OpenRegister `relation` from AgendaItem → Participant
- Participant proposal workflow uses OpenRegister built-in `status` field on AgendaItem with value `voorstel` (pending chair review)
- Agenda publication triggers `NotificationService` and `CalDavService` (ADR-002) — no custom notification infrastructure
- Downstream specs (p2-motion-and-voting, p2-minutes-and-decisions) read AgendaItem status and relations — no breaking changes
- No new PHP controllers or services needed beyond an `AgendaService` for the publish and BOB-transition business rules
