# Coverage Report — decidiq

Generated: 2026-05-25 UTC
Branch: development (commit `2c2191f1`, post-#259/#260 merge)
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | ~470 (60 lib files + 8 frontend, 473 `@spec` occurrences) | — (already tagged) |
| plumbing | ~11 | — (never tagged) |
| 1 — REQ matched | 0 | — (nothing un-annotated to annotate) |
| 2a — existing capability, no REQ | 12 (7 clusters) | `/opsx-reverse-spec decidiq --extend <cap>` |
| 2b — no capability owner | 0 | — |
| 3a — REQ possibly broken (code removed) | 2 | Verify against git history |
| 3b — REQ never implemented | 10 | Mark deferred (mostly V1 future) |
| 4 — ADR conformance | 0 findings (2 spec-drift notes) | Fix malformed `@spec` targets |

## Headline finding

**decidiq is already fully retrofit-annotated.** Every one of the 60 `lib/` PHP files carries `@spec openspec/changes/...` tags at file, class, and method level — 473 occurrences total. Every public/protected method and most private helpers are tagged. 8 frontend files are also tagged. The `/opsx-annotate` step is effectively a **no-op** for this app; there is nothing in Bucket 1.

All `@spec` targets resolve to the **15 archived phase changes** (`p1-*`, `p2-*`, `p4-*`, `decidesk-manifest-v1`, `decidesk-mcp-tools`, `decidesk-store-migration`) under `openspec/changes/archive/` — valid annotation targets.

## Annotation ↔ active-spec mismatch (read before reverse-spec)

The 473 annotations point at the original **build-time phase changes** (archived). The 14 **current consolidated specs** (`meeting-management`, `voting-system`, `decision-management`, …) are a *later* consolidation authored at `status: idea` with prose REQ titles. The two taxonomies do not line up 1:1. So:

- Code is annotated, but **not against the active specs** — it's annotated against the historical changes that built it.
- 13 of 14 active specs are `status: idea`. Only `mcp-tools` is a concrete spec (10 `REQ-DMCP-*` REQs), and it is fully implemented + annotated in `lib/Mcp/DecidiqToolProvider.php`.
- The in-flight `openspec/changes/mcp-tools/` delta (10 REQs) mirrors the active mcp-tools spec — already synced.

## Bucket 2a — Existing capability, no active REQ (reverse-spec --extend)

These are un-annotated **frontend** relation-tab components + settings views. They belong to clear capabilities but no active-spec REQ describes them. Backend (`lib/`) has zero Bucket 2a — it is 100% annotated.

- **meeting-management (3):** `src/views/MeetingIntegrations.vue` (integration-registry panel, ADR-019), `MeetingAgendaTab.vue`, `MeetingParticipantsTab.vue`
- **agenda-management (1):** `AgendaMotionsTab.vue`
- **motion-amendment (2):** `MotionAmendmentsTab.vue`, `MotionVotesTab.vue`
- **decision-management (1):** `DecisionActionItemsTab.vue`
- **resolution-minutes (1):** `MinutesSignersTab.vue`
- **admin-settings (2):** `src/views/settings/AdminRoot.vue`, `GovernanceBodyMembersTab.vue`
- **user-settings (1):** `src/views/settings/UserSettings.vue`

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (code removed)
- **nextcloud-integration#Search Integration** — removed-lines cache matched 345 hits; no `ISearchProvider` in current `lib/src`. Likely existed and was removed/refactored. Verify.
- **nextcloud-integration#Calendar Integration** — 54 removed-line hits; current calendar refs are incidental date handling (AgendaService/AnalyticsController), not CalDAV. Possible prior implementation removed. Verify.

### 3b — never implemented (mostly V1 future features)
- **meeting-efficiency#Agenda Item Timer**, **#Speaking Time Management**, **#Meeting Cost Calculator**, **#Meeting Analytics Dashboard** — entire `meeting-efficiency` capability is unimplemented (0 timer/cost/speakingTime hits).
- **nextcloud-integration#Talk Integration** — 0 spreed/Talk-room references in `lib/`.
- **dashboard#KPI Cards**, **#My Pending Votes Widget**, **#Upcoming Meetings Widget**, **#Nextcloud Dashboard Widget Integration** — `DashboardController` only does `page()`/`catchAll()` routing; no KPI/widget code, no `IWidget` registration anywhere.
- **process-configuration#Built-in Process Templates** — `WorkflowService` exists but no shipped template seed.

The remaining 56 active-spec REQs map to implemented + annotated code across the 9 core capabilities + mcp-tools (meeting-management, agenda-management, motion-amendment, voting-system, decision-management, resolution-minutes, admin-settings, openregister-integration, user-settings/delegation, mcp-tools).

## Bucket 4 — ADR conformance findings

**No conformance findings.** ADR sweep clean:
- No forbidden debug patterns (`var_dump`/`dd`/`die`/`error_log`/`print_r`) in `lib/`.
- No direct SQL (`$this->db->query`/`prepare`/`getQueryBuilder`) — uses OpenRegister per ADR-001.
- Every `lib/` PHP file docblock carries `@license` + `@copyright` + SPDX.

### Spec drift (surface only)
- `lib/Lifecycle/MeetingTransitionGuard.php` (3×) and `lib/Service/MeetingService.php` (3×) carry a malformed `@spec openspec/changes/spec/tasks.md#task-1` — `spec` is a placeholder slug pointing at a non-existent change. Should be re-pointed at the real change (likely `quorum-guard-rewrite` / a `p2-meeting-management-*` change).

## Notes for the human reviewer

- **/opsx-annotate is a no-op here** — there is no un-annotated backend code to tag. The realistic next step is *not* annotate; it's deciding whether to (a) leave the historical-change annotations as-is, or (b) author a fresh ghost change re-pointing them at the consolidated active specs.
- The annotation-vs-active-spec taxonomy gap is the main surprise. The code traces to its build history correctly; it just doesn't trace to the current idea-stage spec set.
- Bucket 2a is entirely frontend relation-tabs. `/opsx-reverse-spec --extend` per capability would document them, but they are thin CRUD-relation UIs — low value to spec individually.
- Bucket 3b is dominated by genuine V1-future features (meeting-efficiency, dashboard widgets, Talk). Recommend marking deferred rather than removing.
- Two malformed `@spec` placeholders (`changes/spec/`) are worth a one-line fix.
