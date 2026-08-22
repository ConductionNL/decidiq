---
kind: code
---

# Proposal: member-onboarding

## Summary

Add a guided member onboarding & offboarding workflow to decidiq: an `OnboardingTraject` and an `OffboardingTraject` OpenRegister schema (delivered as a `lib/Settings/register.d/` fragment per ADR-037) that carry a structured checklist per incoming/departing member — beëdiging recording (date, eed/belofte, meeting where sworn in), Nextcloud account linkage with role-based group/RBAC-scope assignment, induction-pack delivery into the member's Files, and reference-only steps for nevenfuncties intake and fractie assignment — with a declarative lifecycle (`gestart → in-uitvoering → afgerond`), declarative step reminders, a raadswisseling batch orchestration that diffs a completed Member Import into griffie-confirmed onboarding/offboarding suggestions, a griffie progress dashboard, and list/detail pages. This covers the raadswisseling/installatie after municipal elections and its analogues for boards and associations (new board member, ALV-elected member).

## Motivation

Demand cluster `onboard-new-council-member-digitally` scores **763 (must)** in the 2026-07-16 market deep-dive: every raadswisseling a griffie onboards 20–45 members at once (accounts, oaths, iPads, induction packs, group memberships) and offboards the departed — today with Excel checklists next to iBabs/Notubiz, because no RIS vendor ships the workflow. Novelty verification against this worktree (2026-07-17) confirms decidiq covers only fragments:

- `admin-settings` has bulk **Member Import** (Groups/Contacts/CSV with matching and manual-link flagging) — it creates member records, but no workflow around them.
- The `fractievoorzitter-fractie-koppeling` change carries the Raadslid vocabulary (`beëdigings-datum`, `einde-raadslidmaatschap` as a FractieLidmaatschap `redenEind`) and creates Raadslid/FractieLidmaatschap records *after* beëdiging — but nothing records the beëdiging itself as an event, and nothing guides the steps around it.
- `person-and-membership` gives Membership `startDate`/`endDate` — the data model for taking and leaving a seat, with no process on top.

There is **no onboarding workflow object, no oath recording, no induction pack, no guided provisioning, and no offboarding/de-provisioning flow**. Departed members keeping their Nextcloud groups (and thus OpenRegister RBAC scopes, per `authorization-via-or-rbac`) is not just a gap but a standing access-control risk.

## Affected Projects

- [ ] Project: `decidiq` — new `OnboardingTraject` + `OffboardingTraject` schemas (`lib/Settings/register.d/59-member-onboarding.json`), manifest pages + menu (manifest.d fragment), provisioning/de-provisioning service, induction-pack delivery, raadswisseling batch orchestration, dashboard widgets, seed data, docs, tests.

No other apps change. OpenRegister is consumed as-is (lifecycle, notifications, RBAC scopes, FileService, widget aggregations are existing capabilities).

## Scope

### In Scope

1. **OnboardingTraject schema** (register.d fragment 59): person ref, target body + role, trigger (`nieuw-lid` / `raadswisseling-batch` / `tussentijdse-opvolging`), structured checklist steps with per-step status/due date/completion metadata, beëdiging fields aligned with the fractievoorzitter vocabulary (`beëdigingsDatum`, type eed/belofte, meeting ref), NC account linkage fields, declarative lifecycle `gestart → in-uitvoering → afgerond` (`x-openregister-lifecycle`, ADR-031).
2. **Guided provisioning**: NC account linkage + role-based Nextcloud group and OR RBAC-scope assignment as an explicit griffie-confirmed step, aligned with `authorization-via-or-rbac` (role→scope projection, fail-closed).
3. **Induction pack delivery**: a welcome bundle (governing documents, vergaderschema, handbook links) delivered into the member's Files, reusing the meeting-pack/Files delivery vocabulary (FileService, skip-report, honest fallback) from `meeting-pack-board-book`.
4. **Reference-only steps**: nevenfuncties intake (the `interests-and-integrity` sibling owns that register) and fractie assignment (`fractievoorzitter-fractie-koppeling` owns Fractie/FractieLidmaatschap) — checklist steps link to those capabilities and degrade gracefully when absent; this change never duplicates their objects.
5. **OffboardingTraject schema**: end-date memberships (person-and-membership `endDate`), revoke-groups/roles checklist, personal-data note (referencing `document-annotations` GDPR export for the member's own annotations), exit confirmation; triggered per member or as a raadswisseling batch.
6. **Raadswisseling batch orchestration**: diff a completed Member Import (admin-settings) against current active memberships into a suggestion list — onboarding trajecten for new members, offboarding for departed — which the griffie reviews and confirms; **never automatic**.
7. **Griffie progress dashboard**: trajecten per lifecycle status + overdue-steps KPI (declarative widget aggregations) and declarative step-reminder notifications (`x-openregister-notifications`, ADR-031).
8. **List/detail pages** for both trajecten as manifest.d fragment pages (schema refs by slug).

### Out of Scope

- HR/payroll onboarding (contracts, wedde, expenses) — hrmq domain.
- Identity-provider/user provisioning beyond Nextcloud users + groups (no LDAP/SCIM/Entra writes).
- Training-content authoring — the induction pack carries links/attachments only.
- The political installatievergadering procedure itself (agenda, quorum, ceremony) — meetings own that; this change only records its outcome (the beëdiging) on the traject.
- The nevenfuncties register and the Fractie/FractieLidmaatschap objects themselves (owned by `interests-and-integrity` and `fractievoorzitter-fractie-koppeling`).

## Approach

Two new OpenRegister schemas in fragment `59-member-onboarding.json` with declarative lifecycle and notifications; checklist steps as a structured array property (not separate objects — see design D2). A small imperative `OnboardingProvisioningService` for the two side-effectful steps (NC group/scope assignment and revocation) because no OR dialect writes NC groups; an `InductionPackService` following the `MeetingPackageService` folder-delivery pattern; a `RaadswisselingService` that computes the import-vs-membership diff and creates trajecten only on griffie confirmation. Pages, menu, and dashboard widgets are declarative manifest fragments. Details in design.md.

## New Dependencies

None. All capabilities used (OpenRegister lifecycle/notifications/RBAC/FileService, IGroupManager, manifest v2) already exist.

## Impact

- `lib/Settings/register.d/` — new fragment 59 (additive; no existing schema modified).
- `lib/Service/` — new provisioning, induction-pack, and raadswisseling services; new controller endpoints in `appinfo/routes.php`.
- `src/manifest.d/` — new pages/menu fragment; `src/manifest.json` — dashboard widgets.
- Nextcloud groups: the provisioning step writes group memberships via `IGroupManager` — gated on explicit griffie confirmation.

## Cross-Project Dependencies

None hard. Soft, reference-only alignments: `fractievoorzitter-fractie-koppeling` (beëdiging vocabulary, fractie-assignment step target), `interests-and-integrity` (nevenfuncties intake step target), `meeting-pack-board-book` (Files delivery pattern), canonical specs `admin-settings` (Member Import), `person-and-membership` (Membership start/end), `authorization-via-or-rbac` (role/scope model). Steps referencing sibling changes degrade gracefully if those changes have not landed.

## Risks

### Risk 1: Offboarding that does not actually revoke access
**Severity:** High — **Mitigation:** the revoke step is fail-closed and verifiable: it reports the resulting group/scope memberships after execution, the traject cannot reach `afgerond` while the revoke step is not `afgerond`, and the step is unit-tested against `IGroupManager` plus the RBAC-scope projection (never assumed from a green checklist tick — spec REQ-MOB-008).

### Risk 2: Batch orchestration silently creating or ending memberships
**Severity:** High — **Mitigation:** the raadswisseling diff only ever produces a *suggestion list*; nothing is created or end-dated without an explicit griffie confirmation per suggestion (REQ-MOB-009). No background job mutates memberships.

### Risk 3: Vocabulary drift against the fractievoorzitter change (parallel wave sibling)
**Severity:** Medium — **Mitigation:** beëdiging field names are pinned in the spec (`beëdigingsDatum`, `beëdigingsType` eed/belofte, meeting ref) to match that change's stated vocabulary; the fractie step stores only a reference. Flagged as a deferred review question.

### Risk 4: Checklist-as-array property update races (PUT-semantic saveObject)
**Severity:** Medium — **Mitigation:** step updates always carry the full steps array forward (OR saveObject is PUT-semantic; nulls omitted properties); the UI patches one step within the freshly-read object, and a test asserts an untouched step survives another step's update.

## Rollback Strategy

Additive change: remove fragment 59, the manifest fragment, the new services/routes, and the dashboard widgets. No existing schema, page, or service is modified, so reverting the PR restores the previous state; existing traject objects remain inert in the register and can be pruned via the register admin.

## Open Questions

- Should a traject support a `vervallen` (cancelled) terminal state for members who never take their seat (e.g. opvolger declines)? Proposed: yes, as a fourth lifecycle state — confirmed in design, flagged for review.
- The `interests-and-integrity` sibling change dir is scaffolded but empty at time of writing — the nevenfuncties step references the capability by name; exact deep-link target to be aligned at apply time.
