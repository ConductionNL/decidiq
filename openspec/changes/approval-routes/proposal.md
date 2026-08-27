---
kind: code
---

# Proposal: approval-routes

## Summary

Give decidiq a **reusable sign-off route** and an **engine that advances it**. Today `DecisionStage` models the stages of one decision's route and nothing in the app ever writes one — six seeded rows, two readers, no writer, and a route tab whose own header says it is read-only. This change adds the two things missing around that model: `ApprovalRoute`, a reusable template a route is instantiated FROM, and `ApprovalAction`, an append-only record of what each actor did — plus `ApprovalRouteService`, which turns a template into stages and advances them as actions arrive.

## Motivation

Dutch parafering (sequential sign-off on a proposal: advies → parafering → accordering, with terugsturen) is implemented today in dossiq, where it works. It does not belong there: routing a document past a sequence of officials for approval is governance, and governance is decidiq's domain. But it cannot move yet, because the target is a schema without an engine.

Measured against the live code on 2026-08-24:

- **No writer.** `grep` for any create/save/update of `decision-stage` across `lib/` returns nothing. `EIDASSignatureService` and `DecisionIntegrationService` READ stages; `DecisionRouteTab.vue` declares "Posture: read-only" in its own header. Six stages are seeded and none has ever been advanced by this app.
- **No template.** A `DecisionStage` is bound to a `Decision`. There is nowhere to say "every collegeadvies travels these four steps" — `ProcessTemplate` is a state machine (states + transitions + guards), not an ordered sequence of actors.
- **No per-action record.** A stage is ONE mutable row. A route needs many actions against one step: an advice recorded, a return, a re-submission, a delegate acting under mandate. Overwriting the stage loses the trail that makes sign-off auditable.
- **No return path.** `outcome` offers `rejected` and `deferred`; neither means "send back to step N and re-run from there".

`routedDocumentsJoin.js` is NOT the model to build on, despite the name. It routes documents onto a MEETING AGENDA — `collectAgendaItemIds` / `filterRoutedIngekomenStukken` over `Meeting → AgendaItem → Raadsinformatiebrief / IngekomenStuk`. Different sense of "routed" entirely; recorded here because the name invites exactly the wrong assumption.

## Affected Projects

- [x] Project: `decidiq` — this change. Two schemas, an engine service, a controller, and tests.
- [ ] Project: `dossiq` — a FOLLOW-UP. It migrates its parafeerroutes onto these and retires its own. It cannot start until this lands.

## Scope

### In Scope

1. **`ApprovalRoute` schema** — the reusable template: `name`, `subjectType` (what kind of thing travels it), `isDefault`, and `steps[]` of `{order, stageType, actorType, actor, mandatory, label}`.
2. **`ApprovalAction` schema** — the append-only log: `subject`, `subjectSchema`, `step`, `actor`, `actorType`, `onBehalfOf`, `mandate`, `action` (`approved` / `returned` / `advised` / `skipped` / `endorsed`), `comment`, `advice`, `recordedAt`. Many rows per step, never overwritten.
3. **`DecisionStage` gains the vocabulary a sign-off route needs** — additively: `stageType` += `endorsement`, `outcome` += `approved`/`endorsed`/`returned`/`skipped`, and `mandatory` (boolean) so a step can be declared skippable in advance rather than only after the fact.
4. **`ApprovalRouteService`** — the engine, and the whole point of the change:
   - `instantiate(route, subject)` — materialise the template's steps as `DecisionStage` rows against a subject, marking the first `active`.
   - `record(action)` — append an `ApprovalAction`, apply it to the active stage, and advance.
   - **Return** — `returned` sets the named earlier step `active` again and every step after it back to `pending`, which is the behaviour no existing outcome expresses.
   - Fail-closed: an action by an actor the active step does not name is refused, never silently accepted.
5. **A controller** exposing `instantiate` and `record`, both routed and authenticated.
6. **Seed** — one route template with realistic steps, so the model is demonstrable on install.

### Out of Scope

- **Migrating dossiq's routes or retiring anything there.** That is dossiq's change.
- **A UI for building routes.** `DecisionRouteTab` already renders a route read-only; write affordances are a follow-up once the engine exists and its shape is settled.
- **Replacing `ProcessTemplate`.** It answers a different question (what states may a decision be in) and keeps doing so.
- **Meeting-agenda document routing.** Owned by `routedDocumentsJoin` / `MeetingRoutedDocumentsTab`; unrelated despite the name.

## Risks

- **Two ways to describe a decision's progress.** `ProcessTemplate` (states) and `ApprovalRoute` (actor sequence) can both be attached. Mitigated by descriptions on both saying which question each answers, and by not making either imply the other.
- **An engine that half-runs is worse than none.** This is the failure the change exists to avoid, and it is the shape already found twice in the consuming app: a route API whose every call returned 400, and a bridge that short-circuited on a property nothing ever set. So the engine's tests assert the ADVANCE and the REFUSAL, not just that a row was written.
- **`DecisionStage.outcome` grows.** Additive only; every existing value keeps its meaning.
