---
kind: code
depends_on: [toezeggingen-ingekomen-stukken, motie-amendement-administratie]
---

# Proposal: termijnagenda

## Summary

Add a Termijnagenda (long-term agenda / LTA) to decidesk: the forward-planning register of upcoming proposals and topics per governance body, jointly maintained by griffie and college. Each item records what is expected (raadsvoorstel, raadsinformatiebrief, themabijeenkomst, begrotingsstuk), for which body, in which planned period (month or quarter), who owns it, and where it came from (toezegging, motie, or earlier decision). Items move through a declarative lifecycle `gepland → verschoven → gerealiseerd | vervallen` with a mandatory reason and a preserved shift history on every reschedule. The register gets a per-body board view grouped by period with drag-to-reschedule, a list view with CSV export, declarative rappel notifications to owners when a planned period arrives without a linked agenda item, public publication via the existing OR published-predicate machinery, and a dashboard KPI for overdue items.

## Motivation

The termijnagenda is the classic griffie planning instrument that every Dutch raadsinformatiesysteem ships (iBabs LTA, NotuBiz bestuurlijke planning): without it, griffies plan the council's forward calendar in Excel next to decidesk, and the accountability chain breaks — toezeggingen and moties promise future proposals, but nothing tracks whether those proposals actually arrive in the period the college promised. Novelty was verified against the decidesk spec corpus on 2026-07-17: zero hits for termijnagenda/LTA/bestuurlijke planning. The linkage targets now exist in sibling changes (`toezeggingen-ingekomen-stukken` for Toezegging, `motie-amendement-administratie` for motion execution), so this is the right moment to close the gap: origin links can be first-class references instead of free text. The termijnagenda is also a transparency instrument — municipalities publish it so residents can see what is coming — which the existing publication machinery supports without new anonymous surfaces.

## Affected Projects

- [ ] Project: `decidesk` — new TermijnagendaItem schema (register.d fragment 50), manifest pages (board + list + detail), rappel notifications, publication predicate, dashboard KPI, seed data
- [ ] Project: `openregister` — consumer only; uses existing lifecycle/notifications dialects, RBAC published-predicate, and object API (no OR changes)

## Scope

### In Scope

1. **TermijnagendaItem schema** in the decidesk register (fragment `lib/Settings/register.d/50-termijnagenda.json`): onderwerp, governance body ref, planned period at month or quarter granularity (e.g. `2026-Q4` or `2026-11`), expected type (raadsvoorstel / raadsinformatiebrief / themabijeenkomst / begrotingsstuk), owner (griffie / college / portefeuillehouder Person ref), nullable origin links (toezegging ref, motie ref, decision ref), and lifecycle `gepland → verschoven (mandatory reason + full shift history) → gerealiseerd (linkable to the actual AgendaItem/Decision) | vervallen (with reason)`.
2. **Per-body board view** grouped by planned period with drag-to-reschedule (each drop records a `verschoven` entry with reason), plus a list view and CSV export.
3. **Rappel notification** to the owner when an item's planned period arrives without a linked agenda item — declarative `x-openregister-notifications` only.
4. **Public publication** of the termijnagenda via the existing OR published-predicate machinery.
5. **Dashboard KPI**: overdue termijnagenda items (planned period in the past, non-terminal, no realisation link).

### Out of Scope

- **Automatic scheduling into meetings** — linkage from a termijnagenda item to the actual AgendaItem is manual/assistive; the system never creates or moves agenda items itself.
- **College-internal project management** — the termijnagenda tracks what the council will receive and when, not how the college organises the work behind it (no subtasks, no capacity planning).
- Griffie-configurable rappel tuning (admin settings) — provisional windows ship declaratively; tuning is a future admin-settings change.

## Approach

Pure thin-client extension per ADR-022/ADR-037: one additive register.d fragment (schema + lifecycle + notifications dialects + publication predicate + seed data) and one manifest.d fragment (board/list/detail pages + menu), rendered by the shared manifest-v2 machinery. The only genuinely imperative surface is the drag-to-reschedule interaction on the board view, which composes a `verschoven` transition carrying the mandatory reason and appends to the shift history via the standard object store (PUT-semantic saveObject, all fields carried forward). Details, including the declarative-vs-imperative table and the shift-history data shape, go in design.md.

## New Dependencies

None. Board drag-and-drop uses the drag capabilities already shipped in the shared nc-vue component layer (same family as the deck-style board in `action-item-deck-board`); no new packages.

## Impact

- `lib/Settings/register.d/50-termijnagenda.json` — new (schema, dialects, predicate, seed). Number 50 is assigned to this change; 40–49 and 51–65 belong to sibling changes.
- `src/manifest.d/termijnagenda.json` — new (board + list + detail pages, menu entry).
- `src/manifest.json` — one Dashboard stat widget added (fragment merge replaces same-id pages wholesale, so the KPI edits the base Dashboard page directly, same rationale as the toezeggingen change).
- No new controllers or services expected (redundant-controller gate); no app tables.
- Existing schemas (`Toezegging`, `Decision`, `AgendaItem`, `GovernanceBody`, `Person`) are referenced, never modified.

## Cross-Project Dependencies

- `toezeggingen-ingekomen-stukken` (decidesk change): `originToezegging` references the `toezegging` schema. Nullable reference — degrades gracefully if that change lands later.
- `motie-amendement-administratie` (decidesk change): `originMotie` references a `Decision` of `decisionType: motion`; execution narrative stays on that change's UitvoeringsUpdate log — the termijnagenda only points at it.
- OpenRegister: existing dialects and predicate surface only; no OR-side work.

## Risks

### Risk 1: Period granularity mixing (month vs quarter) breaks grouping and overdue math
**Severity:** Medium — **Mitigation:** single canonical `plannedPeriod` string with a strict pattern (`YYYY-Qn` or `YYYY-MM`) validated by the schema, plus a derived sort key convention defined once in design.md; the board groups on the raw value, overdue compares against the period's last day.

### Risk 2: Drag-to-reschedule bypasses the mandatory-reason rule
**Severity:** Medium — **Mitigation:** the drop never saves directly; it opens a reason dialog (modal-isolation gate) and only the confirmed dialog composes the `verschoven` transition with reason + history append. Cancel restores the card.

### Risk 3: Lifecycle/notification dialect drift silently disables the workflow
**Severity:** Medium — **Mitigation:** canonical `x-openregister-lifecycle` (`initial` keyword) and `x-openregister-notifications` copied from the gate-checked sibling fragments; hydra gates 18/28/30/51/52 run on register+manifest changes.

### Risk 4: Fragment number collision with sibling wave-2 changes
**Severity:** Low — **Mitigation:** number 50 is explicitly assigned to this change in the wave plan; siblings own 40–49 and 51–65; verified no existing fragment or sibling spec claims 50.

## Rollback Strategy

Revert the PR: the register.d and manifest.d fragments disappear, pages and menu entries unregister, the KPI widget edit reverts, and the notification rules stop evaluating. Existing TermijnagendaItem objects remain soft-retained in OpenRegister; published items can be withdrawn beforehand by clearing the predicate (`depublicatiedatum`) via the normal staff flow. No data migration in either direction.

## Open Questions

- Whether the manifest board page type supports period-valued columns natively or needs a small board-column-source extension in nc-vue (assistive; list view is the functional fallback) — resolve during design/apply.
- Relative-date token for the overdue KPI filter (same open question as the toezeggingen change D6; reuse whatever that change lands).
