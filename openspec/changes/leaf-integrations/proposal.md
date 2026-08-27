---
kind: code
---

# Proposal: leaf-integrations

## Summary

Close the gap between the integration leaves OpenRegister/nc-vue already ships and the
subset decidiq actually uses. The registry (`nextcloud-vue/src/integrations/builtin/leaves.js`)
offers ~18 app-agnostic leaves — `calendar`, `contacts`, `email`, `talk`, `bookmarks`,
`collectives`, `maps`, `photos`, `activity`, `analytics`, `cospend`, `deck`, `flow`, `forms`,
`polls`, `time-tracker`, `shares`, `openproject` — consumed via manifest widgets
(`{"type": "integration", "integrationId": "..."}`), schema `configuration.linkedTypes`
(Mail-sidebar object linking) and `configuration.mailObjectTemplate` (the Mail-sidebar
"create from email" button).

decidiq's **verified current state** (grep of `src/manifest.json`, 2026-08-18):
`files` ×10 (GovernanceBodyDetail, MeetingDetail, MeetingIntegrations, DecisionIntegrations,
AgendaItemIntegrations, ParticipantDetail, AgendaItemDetail, MotionDetail, DecisionDetail,
ActionItemDetail), `email` ×2 (DecisionIntegrations, AgendaItemIntegrations), `deck` ×2
(MeetingIntegrations, DecisionIntegrations), `talk` ×1 (MeetingIntegrations), plus `notes` ×1
(MeetingIntegrations) and `tasks` ×1 (AgendaItemIntegrations). The `manifest.d/` fragments add
`files` leaves on ~20 more detail pages and nothing else. `lib/Settings/decidesk_register.json`
declares **zero** `linkedTypes` and **zero** `mailObjectTemplate` entries (repo-wide grep:
no hits), so the Mail sidebar can neither link an email to a decidiq object nor create one
from an email — despite two email tabs already existing in the app.

This change adopts the four leaves with the clearest decidiq fit and wires the Mail sidebar:

1. **calendar** — the obvious one: a `Meeting` is a `schema:Event` (`x-openregister.schemaType`)
   with `scheduledDate`, `endDate`, `location`, `virtualLocation`, `eventAttendanceMode`; the
   calendar leaf surfaces it on MeetingIntegrations.
2. **contacts** — `Person.contactDetails` / `Person.email` and the dedicated `ContactDetail`
   schema (`type`, `value`, `label`, `person`, `governanceBody`) map onto NC Contacts;
   surfaced on ParticipantDetail and GovernanceBodyDetail.
3. **polls** — citizen participation: informal straw polls on a `PublicConsultation` or a
   `Decision` **before** a formal `VotingRound` is opened; advisory only, never a ballot.
4. **forms** — consultation intake: a Forms form bound to a `PublicConsultation`, with
   responses entering the existing `ConsultationReaction` moderation path
   (`ReactionIntakeService`).

## Motivation

Every leaf decidiq skips is a context switch its users pay for daily: a clerk checks the
Calendar app to see whether the council chamber is double-booked, looks up a member's phone
number in Contacts, runs a straw poll in Polls, and collects consultation input in Forms —
none of it visible from the decidiq object it belongs to. The leaves exist precisely so a
domain app gets these surfaces by declaration (ADR-019), the way decidiq already gets Deck
boards and Talk rooms. The Mail-sidebar gap is sharper still: decidiq renders email tabs on
decisions and agenda items, but because no schema declares `configuration.linkedTypes`, the
Mail sidebar's link action never offers a decidiq object — the integration is half-wired,
consuming links that nothing can create.

## Affected Projects

- [x] Project: `decidiq` — manifest widget additions on 4 pages, `configuration.linkedTypes`
  + `configuration.mailObjectTemplate` on a small schema set, register version bump. No new
  backend endpoints; no OpenRegister or nc-vue changes.

## Scope

### In Scope

- `calendar` leaf widget on `MeetingIntegrations`.
- `contacts` leaf widget on `ParticipantDetail` and `GovernanceBodyDetail`.
- `polls` leaf widget on `ConsultationDetail` (manifest.d/citizen-participation.json) and
  `DecisionIntegrations`.
- `forms` leaf widget on `ConsultationDetail`.
- `configuration.linkedTypes` on `Meeting`, `Decision`, `AgendaItem` and `ActionItem` (the
  schemas that already carry email/files surfaces) so the Mail sidebar can link mails to them.
- `configuration.mailObjectTemplate` on `Decision` only — "create draft decision from email"
  (`title` ← `{{subject}}`, `text` ← `{{preview}}`, `externalReference` ← `{{mailRef}}`,
  `lifecycle` pinned to `draft`).
- Register `version` bump (schema re-import is version-gated; an unbumped version makes the
  import a silent no-op).

### Out of Scope

- The remaining unused leaves (`maps`, `photos`, `bookmarks`, `activity`, `time-tracker`,
  `cospend`, `shares`, `openproject`, `flow`) — no articulated decidiq user story yet.
- `collectives` and `analytics` — already owned by the existing capabilities
  `faction-workspace-via-collectives-leaf` and `governance-analytics-via-analytics-leaf`.
- Any change to the deck/talk/email/files surfaces that exist today.
- Bi-directional calendar sync (decidiq meeting ⇄ CalDAV VEVENT write-back) — the leaf is a
  surface; a sync engine would be its own change.
- A calendar surface for action-item deadlines: `ActionItem` is already a CalDAV VTODO
  projection (`x-openregister-object-source: {provider: caldav-vtodo, readOnly: true}`), so
  `dueDate` already lives in CalDAV and surfaces via the existing `tasks` leaf on
  AgendaItemIntegrations and the Tasks app. A second calendar rendering would duplicate it.
- A `mailObjectTemplate` on `ActionItem` — structurally impossible: the create-from-email path
  writes through `ObjectService::saveObject()`, which the read-only VTODO projection rejects
  (same constraint as decidiq-mcp-adoption D3).
- A Person detail page (none exists in the manifest today; `ParticipantDetail` is the people
  surface). Adding one is a separate UI change.

## Approach

Pure declaration, following the pattern `action-item-deck-board` proved: decidiq's `main.js`
already calls `registerBuiltinIntegrations()`, so every leaf in
`nextcloud-vue/src/integrations/builtin/leaves.js` is resolvable — a manifest widget
`{"type": "integration", "integrationId": "<id>"}` is all a page needs. Each leaf declares its
`requiredApp` (`calendar`, `contacts`, `polls`, `forms`); when the app is absent the leaf
degrades per the registry contract instead of erroring, matching REQ-AI-DECK-009's precedent.
The Mail-sidebar wiring is register JSON only: `Schema::setConfiguration()` validates
`linkedTypes` against the registry's `listIds()` (`validateLinkedTypesValue()`, OpenRegister
`lib/Db/Schema.php`), and `mailObjectTemplate` placeholders (`{{subject}}`, `{{sender}}`,
`{{date}}`, `{{preview}}`, `{{messageId}}`, `{{mailRef}}`) are documented on the same class.

## New Dependencies

None. All four leaves ship in nc-vue's builtin registry; the Mail-sidebar mechanisms ship in
OpenRegister. The Nextcloud `calendar`, `contacts`, `polls` and `forms` apps are **optional
runtime** dependencies — absence degrades, never breaks.

## Impact

- `src/manifest.json` — `calendar` widget on MeetingIntegrations; `contacts` on
  ParticipantDetail + GovernanceBodyDetail; `polls` on DecisionIntegrations.
- `src/manifest.d/citizen-participation.json` — `polls` + `forms` widgets on ConsultationDetail.
- `lib/Settings/decidesk_register.json` — `configuration.linkedTypes` on 4 schemas,
  `configuration.mailObjectTemplate` on `Decision`, register version bump.
- No PHP, no Vue components, no routes, no stores.

## Cross-Project Dependencies

- **nc-vue** ≥ the release carrying `builtin/leaves.js` with `calendar`/`contacts`/`polls`/
  `forms` (verified present at the checked-out workspace copy).
- **OpenRegister** at `origin/development` for `linkedTypes` registry validation and the
  Mail-sidebar `ActionsTab.vue` create-from-email flow.

## Risks

### Risk 1: Registry rejects a `linkedTypes` id at register import
**Severity:** Low — **Mitigation:** `validateLinkedTypesValue()` fails loudly at import, not
silently at query time. The task list pins the exact ids to the registry's `listIds()` output
plus the legacy allow-list, and the verification phase imports the register on a dev instance
before merge.

### Risk 2: Create-from-email produces half-formed decisions
**Severity:** Medium — **Mitigation:** The template pins `lifecycle: draft` (non-string
template values pass through verbatim per `Schema.php`), so a mail-born decision enters the
same draft state as a UI-born one and triggers no decision-lifecycle notification or
publication path until a human advances it.

### Risk 3: Polls misread as binding votes
**Severity:** Medium — **Mitigation:** REQ-LEAF-003 states the poll surface is advisory and
SHALL NOT create or mutate `VotingRound`/`Vote`/`CitizenVote` objects; the widget title says
"Straw poll". Formal voting stays in decidiq's own voting system (`VotingRoundOpener` et al.).

### Risk 4: Leaf apps not installed in target environments
**Severity:** Low — **Mitigation:** Same contract as Deck today: `requiredApp` detection hides
or degrades the leaf; the files/email/deck surfaces are untouched.

## Rollback Strategy

Fully additive. Revert the manifest widget entries and the two `configuration` keys, bump the
register version again, re-import. No data migration: leaves store nothing in decidiq, and
mail-created decisions are ordinary draft `Decision` objects that survive rollback as plain
data.

## Open Questions

- Should `AgendaItem` also get a `mailObjectTemplate` ("turn this email into an agenda item on
  the next draft agenda")? Deferred: it needs a meeting-resolution step the template language
  cannot express (which meeting?).
- Should the polls leaf pre-fill poll options from a `Decision.proposedText` amendment set?
  Deferred until the straw-poll workflow has real usage.
