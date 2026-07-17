---
kind: code
---

# Proposal: constituency-consultation

## Summary

Add an **achterbanraadpleging / ledenraadpleging** capability: an informal, explicitly **non-binding** poll of a defined member audience (a governance body's active members, a fractie, or a configured Nextcloud group such as an OR's achterban), linked to an agenda item and/or decision, whose summarised outcome flows back into the meeting as an input artifact. Delivered as two new OpenRegister schemas in a `lib/Settings/register.d/48-constituency-consultation.json` fragment (declarative lifecycle `concept → open → gesloten → verwerkt`, declarative open/closing-soon notifications), a small imperative audience-resolution + response-intake guard, a results-summary step, and manifest-v2 list/detail/respond pages with "non-binding raadpleging" labelling everywhere.

## Motivation

Novelty-verified missing (2026-07-17): decidesk's `citizen-participation` / `portal-contribution` cover **public/citizen** input only, and `voting-system` / `preferential-ballot` cover **formal ballots** only — there is no member-poll capability in between. Yet the between-space is exactly where Dutch governance practice lives: a fractie polls its leden before a council vote, an association board sounds out the ledenvergadering before tabling a proposal, an OR consults its achterban before an instemmingsbesluit. Demand evidence: `consult-constituency-before-council-vote` (1115) and `prepare-for-member-council-meeting-with-constituency-input` (780). Without this, members improvise in mail/Forms outside the meeting context, and the outcome never lands next to the agenda item it was meant to inform. The sibling change `works-council-consultation` will reference this capability for its achterban step, so the audience model must be generic (body membership *and* NC group).

## Affected Projects

- [ ] Project: `decidesk` — new `MemberConsultation` + `MemberConsultationResponse` schemas (register.d fragment 48), manifest pages + menu (manifest.d fragment), audience-resolution/response-guard service + routes, results-summary step, seed data, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, RBAC, relations are existing capabilities).

## Scope

### In Scope

1. **MemberConsultation schema** (register.d fragment 48): question, description, response type (single choice / multi choice / open text) with choice options, audience (governance body's active members, a fractie within a body, or a configured NC group), linked agenda item and/or decision, open/close window (`opensAt`/`closesAt`), anonymous-responses flag, declarative lifecycle `concept → open → gesloten → verwerkt`.
2. **Response collection** for audience members: one `MemberConsultationResponse` per member, editable until close, window and audience enforced server-side.
3. **Results summary**: aggregate counts per option + optional open-text digest, stored on the consultation and surfaced on the linked agenda item/decision as an input artifact visible in the meeting context; explicit "non-binding raadpleging" labelling everywhere.
4. **Boundary as a requirement**: a MemberConsultation is *not* a VotingRound and *not* a PublicConsultation — stated normatively, mirroring how `toezeggingen-register` REQ-003 states its not-an-ActionItem boundary.
5. **Declarative notifications** (ADR-031 `x-openregister-notifications`): audience notified on open and closing-soon.
6. **List/detail/respond pages** per manifest-v2 conventions (schema refs by slug) in a `src/manifest.d/` fragment.

### Out of Scope

- Public/citizen polls — `citizen-participation` owns those (PublicConsultation/ConsultationReaction stay untouched).
- Formal voting of any kind — `voting-system`/`preferential-ballot`; a raadpleging never creates or feeds a VotingRound tally.
- Statistical weighting, quotas, or representativeness analysis of responses.
- External survey-tool integration (Forms, LimeSurvey, etc.).
- The works-council (OR) instemmings/adviestraject itself — sibling change `works-council-consultation`; this change only provides the generic audience model it reuses.

## Approach

Thin-client extension per ADR-022/ADR-037: two schemas shipped as `lib/Settings/register.d/48-constituency-consultation.json` (never editing `decidesk_register.json`), lifecycle via `x-openregister-lifecycle` (canonical `initial` keyword), open/closing-soon notifications via `x-openregister-notifications`. UI is a `src/manifest.d/constituency-consultation.json` fragment (index + detail + respond surface) rendered by `CnPageRenderer`. Imperative code is limited to what dialects cannot express: an audience-resolution service (active Memberships of a body, party-filtered fractie subset, or NC group members) guarding response create/update (audience check, respond-once, window), and a results-summary step on the `gesloten → verwerkt` transition. Details in design.md.

## New Dependencies

None. All capabilities used (lifecycle, notifications, relations, RBAC, Membership queries, manifest pages) already exist in OpenRegister, nc-vue, and decidesk.

## Impact

- `lib/Settings/register.d/48-constituency-consultation.json` (new — schemas + dialects + seed data; fragment number 48 is assigned to this change, 40–47/49–65 belong to siblings).
- `src/manifest.d/constituency-consultation.json` (new — pages + menu entries).
- `lib/Service/ConsultationAudienceService.php`, `lib/Service/ConsultationResponseService.php`, `lib/Service/ConsultationSummaryService.php` (new — audience resolution, guarded intake, summary generation).
- `lib/Controller/` + `appinfo/routes.php` (edit — respond/summary routes with per-object guards).
- Agenda-item / decision detail pages gain a "Raadpleging (niet-bindend)" input section via reverse lookup — additive manifest section, no existing schema edits.
- Docs + PHPUnit/e2e per hydra gates.

## Cross-Project Dependencies

- `works-council-consultation` (decidesk sibling change) will REFERENCE this change's audience model for its achterban step (NC-group audience). This change must therefore keep the audience model generic; no reverse dependency.
- `fractievoorzitter-fractie-koppeling` (decidesk sibling change) introduces a first-class `Fractie` schema. This change models a fractie audience via `Membership.party` within a body (works today, `person-and-membership`); upgrading the fractie audience to reference the `Fractie` object is deferred to after that change lands. Soft, forward-only relationship.
- OpenRegister: consumed, not changed.

## Risks

### Risk 1: Raadpleging outcome mistaken for a formal vote

**Severity:** High — **Mitigation:** boundary is a normative requirement (REQ-CCO-005): results never write into any VotingRound/tally, the summary artifact and every page/section carry an explicit "niet-bindende raadpleging" label, and the schema is unrelated to Vote/VotingRound. Playwright asserts the label on index, detail, respond, and meeting-context surfaces.

### Risk 2: Anonymous-responses flag over-promises

**Severity:** Medium — **Mitigation:** respondent identity is always stored for respond-once/edit enforcement; the flag governs display and summary only (no view or summary shows identities). The spec states this honestly (pseudonymous-at-display, not at-rest anonymity) and the UI copy says so; at-rest anonymisation is explicitly out of scope.

### Risk 3: Audience drift — polling people outside the intended constituency

**Severity:** Medium — **Mitigation:** audience is resolved server-side on every response write (active Membership window per `person-and-membership` REQ-PMB-002 semantics, or NC group membership); never trusted from the client; Newman IDOR-style tests submit as a non-audience user and assert 403.

### Risk 4: Overlap/confusion with citizen-participation schemas

**Severity:** Low — **Mitigation:** distinct schema names (`MemberConsultation` vs `PublicConsultation`, `MemberConsultationResponse` vs `ConsultationReaction`), no public/anonymous surface at all (audience is authenticated members by definition), and the boundary requirement names the owning capability for citizen input.

## Rollback Strategy

Revert the PR: removing the register.d and manifest.d fragments de-registers schemas/pages on next load/build (ADR-037 fragments are additive; `decidesk_register.json` is never edited). Existing MemberConsultation/Response objects remain soft-retained in OpenRegister; routes and services disappear with the revert. No data migration to undo.

## Open Questions

- Closing-soon window (48h before `closesAt`?) — provisional value in the notification trigger; admin-configurable tuning deferred.
- Whether the respond surface should also appear inside the live meeting view (agenda-live-management) — deferred; this change guarantees the meeting-context *summary* section only.
