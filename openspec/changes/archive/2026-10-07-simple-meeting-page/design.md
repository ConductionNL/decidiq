# Design: simple-meeting-page

## Where the shape lives

The page gets its simple shape from an overlay in `src/menu-layout.simple.json`. `src/manifest.json` is not edited. The full structure has no overlay, so its page cannot drift, and `tests/vitest/simpleMeetingPage.spec.js` asserts it equals the manifest.

## D1. The steps are `api-call` header actions

```json
{ "id": "meeting-open", "type": "api-call", "method": "POST",
  "url": "/apps/decidiq/api/meetings/@objectId/lifecycle",
  "payload": { "action": "open" }, "confirm": true,
  "visibleWhen": { "any": [
    { "field": "lifecycle", "op": "eq", "value": "scheduled" },
    { "field": "lifecycle", "op": "eq", "value": "adjourned" } ] } }
```

The body is the one the Stage block sends: `{ action }`. Each action is offered in every state the server allows it from and in no other. The spec reads `MeetingService::TRANSITIONS` and fails on a mismatch in either direction.

Open and close ask first. A meeting cannot go back to convened once it is open, and a closed meeting stays closed.

## D2. Which steps, and why not all six

`WorkflowService::isTransitionAllowed` forbids two edges per governance domain: open to paused and open to adjourned. The fallback workflow forbids both. The meeting schema has no `domain` property the page could read, and reading it would put a server rule in the manifest. The header offers the four steps no domain can forbid. The Stage block keeps all six.

The spec reads the targets `isTransitionAllowed` names and fails when a step the header offers is among them.

## D3. Saying who takes the next step

Considered and rejected:

- **Hiding the button with `visibleWhen.endpoint`.** The library fetches that address as written. It does not fill in `@objectId`, so it cannot ask about this meeting (nextcloud-vue 2.60.0 and 2.61.0, `utils/visibleWhen.js#readVisibleWhenValue`).
- **Comparing `chair` with the reader.** That would write the permission in the manifest, and it would be wrong for a secretary and an administrator.
- **Drawing the button inside the step bar.** The page would then have no primary button in the header, which is the point of the pattern.

Chosen: `MeetingStepBar.vue` calls `GET /api/meetings/{id}/transitions` and compares the answer with the step the header button takes from this state (`MEETING_NEXT_ACTION`). A step that is not offered gets one sentence under the bar. Without an answer the bar says nothing. The spec holds `MEETING_NEXT_ACTION` equal to the overlay's primary actions.

## D4. The step bar

Four steps: draft, convened, in session, closed. Paused and adjourned are a break in a meeting that is in session, so the bar keeps such a meeting on the third step and says the break in a sentence. Four equal columns on one row, a number, a label and a word for done, now or next. The tiles are as tall as the two grid rows a card gets at the least, so the card has no empty band.

## D5. Tabs

| Tab | Blocks |
| --- | --- |
| Agenda | Agenda, Incoming documents, Technical questions, Publication |
| Participants | Participants, Proxy authorizations, Video call |
| Documents | Documents, Document details, Papers as PDF |
| Decisions | Decisions, Votes, Signing the decision list |
| Minutes | Minutes, Transcription |
| More: Planning | Planning, Outcome, Agenda items, Action items, Series, Sessions |
| More | Broadcast, Case system, Audit statements, Stage |

Twenty-five blocks, each in one tab. A tab with several blocks is a `detail-sections` panel, the container `simple-decision-page` added. Each section is headed by the title its block has as a card.

## D6. The nineteen custom blocks

The manifest declares nineteen blocks as `type: "custom"`. A tab resolves a widget by type and does not read the page's slot map. The overlay patches the type of each to the component the block already names (`MeetingAgendaTab` and so on). The spec asserts the patched type equals the block's `component`, and its slot where it has one, and that nothing else about a block changes.

## D7. The side column

One `data` card, Key facts, not editable, empty fields hidden. It leads with `scheduledDate`, which the schema requires, so the card is never an empty box.
