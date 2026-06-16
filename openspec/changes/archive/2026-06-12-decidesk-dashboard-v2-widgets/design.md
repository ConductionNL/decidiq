# Design: decidesk-dashboard-v2-widgets

## Context

Decidesk's current dashboard page (`src/manifest.json` → `id: "Dashboard"`) renders three stats-blocks with hardcoded Dutch titles via the manifest `widgets` array. The `CnDashboardPage` component supports custom slot components registered in the app's ADR-036 registry — exactly the same mechanism that pipelinq uses for its 16 dashboard widgets. This change builds those components. No PHP is required (the app is a thin client reading OpenRegister directly from the frontend). The follow-up `decidesk-dashboard-v2-layout` change wires these components into the manifest.

## Goals / Non-Goals

**Goals:**
- Eleven standalone Vue 2.7 widget SFCs renderable in the `CnDashboardPage` slot system
- Consistent data-fetch pattern (dashboardRefreshMixin + dashboardData service) matching pipelinq conventions
- Honest Playwright e2e coverage for component-renderable scenarios; spec annotations for layout-dependent scenarios
- vitest unit coverage for all computed logic with governance-domain semantics (set-difference, overdue, urgency, grouping)
- Five-language i18n (nl/de/fr/es/it) with English source keys throughout

**Non-Goals:**
- manifest.json wiring — belongs in `decidesk-dashboard-v2-layout`
- `OCP\Dashboard\IWidget` PHP registration
- Engagement / speaking-time analytics
- New OpenRegister schema definitions

## Declarative-vs-Imperative Decisions (ADR-031)

| Behaviour | Choice | Rationale |
|-----------|--------|-----------|
| Upcoming meetings count | **Declarative (client-filter)** | `scheduledDate >= now` is a date comparison applied client-side on the already-fetched meetings collection. Keeping it client-side avoids a bespoke filter endpoint; OR's generic filter would require a `>= now` range which OR supports but date arithmetic belongs in component computed props where it is trivially testable. |
| Upcoming meetings list | **Declarative (client-sort)** | Fetch `lifecycle=scheduled` from OR, sort by `scheduledDate` in the computed property. OR cannot sort by a schema field in all configurations; client sort is safe for governance-scale data volumes. |
| Overdue action items count | **Client-side imperative** | `dueDate < now AND taskStatus NOT IN (completed, cancelled)` — two conditions requiring date comparison and status exclusion. OR filter supports `taskStatus!=completed` but not the compound date-plus-exclusion without a custom query. Easier to fetch open/in-progress items and filter in the component; vitest-testable. |
| Pending votes per user | **Client-side imperative** | Per-user logic requires: (1) resolve current user → participant via `nextcloudUserId` match, (2) fetch all voting-rounds lifecycle=open, (3) fetch votes cast by that participant, (4) set-difference. Steps 2+3 use OR fetch with server-side `lifecycle` filter; step 4 is a JavaScript `Set` difference in the component. This is the minimal server-round-trip approach while keeping the user-identity resolution in the client where `getCurrentUser()` is available. The server cannot perform the join without a cross-schema query (OR does not natively join). If no participant record matches the current user, pending votes = **0** (the user is not a voting member — observers/guests/admins see no pending votes rather than an over-count). |
| Running processes (lifecycle grouping) | **Declarative (client-groupBy)** | Fetch motions with `lifecycle IN [submitted, under-discussion, voting]`; group by `lifecycle` in a computed property using `reduce`. Grouping is a presentational concern, not a data concern. |
| My action items | **Client-side filter on OR fetch** | OR filter: `assignee=<currentUserId>` (server-side). The current user's NC uid is available via `getCurrentUser().uid`. Status filter (`open`, `in-progress`) also sent as an OR filter parameter so the fetch is pre-scoped. Minimal client-side work. |
| Recent decisions | **Server-side page+sort via OR** | Fetch decisions sorted by `decisionDate DESC`, limit N (configurable, default 10). OR supports pagination and sort; this is the correct server-side operation. |
| Governance health chart | **Custom component (declarative ruled out by evidence)** | `quorumPercentage` and `actionItemCompletionRate` exist as materialized fields on the Meeting schema — no duplicate calculation. The declarative path (manifest `type: "chart"` + dataSource) was investigated and ruled out: the lib's chart dataSource resolves ONE metric per query (`useDataSource.js` bucket shorthand) and cannot assemble two named live series; the only declarative option would be hardcoded static series (fake data). Per user decision (2026-06-12, reversing the earlier chart-widget choice on this new evidence), `GovernanceHealthWidget` is a custom component fetching ≤12 recent meetings and rendering two live series. |
| Active decisions count | **Client-side imperative** | The Decision schema has NO lifecycle field — "active" = `outcome` is null (enum: adopted/rejected). OR/stats-block filter support for IS NULL is unverified, and a count-all approximation would lie once decisions resolve. `ActiveDecisionsKpiWidget` fetches decisions and counts `outcome == null` client-side. |
| Empty state detection | **Declarative (client-check)** | After the governance-body collection fetch resolves, if total=0 render DashboardEmptyState instead of the widget grid. This is a simple boolean derived from store state — no server-specific logic needed. |

## Decisions

### Decision 1: Follow pipelinq widget file layout exactly

**Chosen:** `src/views/dashboard/widgets/<ComponentName>.vue` + `dashboardRefreshMixin.js` in the same directory + `src/services/dashboardData.js` for OR fetch helpers.

**Alternative considered:** put widgets in `src/components/dashboard/` (where pipelinq has `BillingCategoryWidget`). Rejected because pipelinq's primary widget location is `src/views/dashboard/widgets/` and the `CnPageRenderer` resolves registry entries by name, not path — consistent placement makes navigation unambiguous.

**Rationale:** pipelinq is the reference implementation; mirroring its structure minimises cognitive load when developers context-switch between apps.

### Decision 2: Registry kind for widget components — VERIFIED

**Chosen:** `kind: "widget"` entries in `src/registry.js` using a new `widget()` helper function mirroring `page()`.

**Verified (2026-06-12):** the lib decidesk actually builds against — the local `../nextcloud-vue/src` via the `useLocalLib` webpack alias (the npm `1.0.0-beta.108` in node_modules is a stale fallback) — supports `kind: "widget"` natively. `CnAppRoot.vue` declares `REGISTRY_KIND_REQUIRED_FIELDS.widget = ['defaultSize', 'minSize', 'maxSize', 'allowedSlots', 'propsSchema']` and `KNOWN_REGISTRY_KINDS` derives from it; unknown kinds throw `RegistryKindError`, known kinds with missing metadata only `console.warn`.

**Consequence:** every `widget()` registry entry MUST supply all five metadata fields (`defaultSize`, `minSize`, `maxSize`, `allowedSlots`, `propsSchema`). No `kind: "page"` fallback is needed.

### Decision 3: dashboardData service over direct useObjectStore calls in each component

**Chosen:** A shared `src/services/dashboardData.js` that wraps `useObjectStore` fetch calls for each schema type used by dashboard widgets. Components import named functions (`getMeetings`, `getVotingRounds`, `getVotes`, `getActionItems`, `getMotions`, `getDecisions`, `getParticipants`, `getMinutes`).

**Rationale:** avoids duplicating OR API call conventions across 10 component files; centralises filter parameters; makes unit testing (mock the service module) straightforward.

### Decision 4: Per-user pending-votes resolution via client-side set-difference

**See declarative-vs-imperative table.** The set-difference algorithm is: `openVotingRoundIds.filter(id => !alreadyVotedRoundIds.has(id))` where `alreadyVotedRoundIds` is derived from fetching votes where `participant=<currentParticipantId>`. Two OR fetches; one Set operation. Total complexity O(V + C) where V = open voting rounds, C = user's cast votes — bounded by governance data volumes (dozens, not millions).

**No-participant rule (user decision):** when no participant record has `nextcloudUserId` matching the current user, the pending-votes count is **0** and the list widget shows its "No pending votes" empty state. Rationale: a user without a participant record is not a voting member (observer, guest, admin); showing all open rounds would be misleading noise.

### Decision 5: Governance health chart — custom component (re-decided on evidence)

**History:** the first user decision picked the declarative path (manifest `type: "chart"` widget, no component). Layout-change research then verified the lib cannot do it with live data: `useDataSource.js:149–162` resolves a single metric to `{ series: [values], categories: [keys] }` and `CnDashboardPage.vue` `getChartProps` only forwards static chart props — two named live series (quorum % + action completion %) are not expressible; the only declarative option was hardcoded seed values, i.e. fake data on a production dashboard (also a hydra stub-scan smell). Presented back to the user 2026-06-12; user chose to reinstate the custom component.

**Chosen:** `GovernanceHealthWidget.vue` fetches up to 12 recent meetings (with non-null materialized `quorumPercentage`/`actionItemCompletionRate`) and renders a two-series chart. Render via the lib's exported chart component if available from the package index; otherwise `vue-apexcharts` (transitive dep) directly — the apply agent verifies which export exists and documents the pick in the component.

**Empty state:** fewer than 2 meetings with materialized values → "Not enough data" placeholder, never an empty/fake chart.

### Decision 5b: ActiveDecisionsKpiWidget instead of a stats-block (user decision)

The Decision schema has no `lifecycle`; "active" = `outcome` is null. Rather than an approximate count-all stats-block (misleading) or an unverified IS-NULL manifest filter, a fourth small KPI component `ActiveDecisionsKpiWidget` counts `outcome == null` client-side — identical pattern to the other KPI widgets, always correct. Click navigates to the Decisions view.

### Decision 6: i18n source keys are English strings, never Dutch

Per ADR (fleet i18n 5-lang program). `t('decidesk', 'Pending votes')` not `t('decidesk', 'Openstaande stemmen')`. All `t()` calls use readable English source strings. Translations (nl/de/fr/es/it) are generated via the app's existing `l10n/` tooling conventions.

### Decision 7: Playwright e2e coverage strategy for layout-dependent scenarios

Two categories of scenarios:
1. **Component-renderable:** The widget can be mounted in a test harness (via a stub dashboard page) and exercised independently. These get real Playwright tests in `tests/e2e/`.
2. **Full-dashboard-only:** Scenarios that require the complete manifest wiring (e.g., "Default grid layout on first load" verifying the 12-column grid). These are annotated in the spec with `@e2e exclude full-dashboard-only — covered by decidesk-dashboard-v2-layout` on a standalone line (gate-19 honest-coverage requirement).

## Seed Data (ADR-001)

Realistic example objects for a Dutch municipality: **Gemeenteraad Westerkwartier** (council, legislative domain).

**Governance body:**
- id: `00000000-0000-0000-0000-000000000001`, name: "Gemeenteraad Westerkwartier", bodyType: "legislative", domain: "municipal"

**Meetings (6 months of data):**
- `00000000-0000-0000-0000-000000000010` — "Raadsvergadering 18 juni 2026", lifecycle: "scheduled", scheduledDate: "2026-06-18T19:30:00Z", quorumPercentage: null, actionItemCompletionRate: null
- `00000000-0000-0000-0000-000000000011` — "Raadsvergadering 21 mei 2026", lifecycle: "minutes_approved", scheduledDate: "2026-05-21T19:30:00Z", quorumPercentage: 87, actionItemCompletionRate: 72
- `00000000-0000-0000-0000-000000000012` — "Commissievergadering Ruimte 10 juni 2026", lifecycle: "scheduled", scheduledDate: "2026-06-10T14:00:00Z", quorumPercentage: null, actionItemCompletionRate: null
- `00000000-0000-0000-0000-000000000013` — "Raadsvergadering 23 april 2026", lifecycle: "minutes_approved", scheduledDate: "2026-04-23T19:30:00Z", quorumPercentage: 93, actionItemCompletionRate: 85

**Participants (linked to NC users):**
- `00000000-0000-0000-0000-000000000020` — displayName: "A. de Vries", role: "council-member", nextcloudUserId: "avries"
- `00000000-0000-0000-0000-000000000021` — displayName: "B. Jansen", role: "council-member", nextcloudUserId: "bjansen"

**Motions (in-flight):**
- `00000000-0000-0000-0000-000000000030` — "Motie duurzame mobiliteit", lifecycle: "submitted", title: "Motion on sustainable mobility"
- `00000000-0000-0000-0000-000000000031` — "Motie woningbouw spoedprocedure", lifecycle: "voting", title: "Emergency housing procedure motion"
- `00000000-0000-0000-0000-000000000032` — "Motie klimaatadaptatie", lifecycle: "under-discussion", title: "Climate adaptation motion"

**Voting rounds (open):**
- `00000000-0000-0000-0000-000000000040` — motion: `...0031`, lifecycle: "open", deadline: "+6h from seed time"
- `00000000-0000-0000-0000-000000000041` — motion: `...0030`, lifecycle: "open", deadline: "+48h from seed time"

**Votes cast (user avries already voted on round 0041, not on 0040):**
- `00000000-0000-0000-0000-000000000050` — votingRound: `...0041`, participant: `...0020`, choice: "for"

**Action items:**
- `00000000-0000-0000-0000-000000000060` — title: "Draft mobility policy document", assignee: "avries", dueDate: "2026-06-01" (overdue), taskStatus: "open"
- `00000000-0000-0000-0000-000000000061` — title: "Consult neighbourhood boards", assignee: "avries", dueDate: "2026-06-20", taskStatus: "in-progress"
- `00000000-0000-0000-0000-000000000062` — title: "Publish housing decision", assignee: "bjansen", dueDate: "2026-05-15" (overdue), taskStatus: "open"

**Decisions (recent):**
- `00000000-0000-0000-0000-000000000070` — title: "Adoption of local transport plan", outcome: "approved", isPublished: "public", decisionDate: "2026-05-21"
- `00000000-0000-0000-0000-000000000071` — title: "Amendment to noise ordinance", outcome: "rejected", isPublished: "internal", decisionDate: "2026-04-23"
- `00000000-0000-0000-0000-000000000072` — title: "Emergency housing designation", outcome: "tabled", isPublished: "draft", decisionDate: "2026-06-05"

**Minutes (awaiting approval):**
- `00000000-0000-0000-0000-000000000080` — meeting: `...0013`, lifecycle: "review", title: "Minutes — Council meeting 23 April 2026"

This seed enables: 1 overdue action item for user avries (item 0060), 1 open vote pending for avries (round 0040 — they cast for 0041 already), 2 upcoming scheduled meetings, 1 minutes awaiting approval, 3 motions in flight across 3 lifecycle stages, governance health chart data from 2 completed meetings.

## Risks / Trade-offs

- [OR cross-schema join limitation] → Mitigation: client-side set-difference for pending votes (two separate fetches + JS Set). Acceptable for governance-scale data.
- [quorumPercentage/actionItemCompletionRate absent on pre-existing meetings] → `GovernanceHealthWidget` guards null values and renders "Not enough data" below 2 usable data points; the seed data above provides materialized values for demo purposes.
- [Local-lib build coupling] → registry `kind: "widget"` support was verified against the local `../nextcloud-vue/src` checkout (used via the `useLocalLib` webpack alias). If decidesk is ever built without the local lib present, the npm fallback must be ≥ a release that ships `widget` in `KNOWN_REGISTRY_KINDS` (beta.108 status unverified; lib ≤ beta.111 is known broken anyway — a new beta is a pending follow-up on nextcloud-vue).

## Migration Plan

No database or schema changes. Deploy: commit → CI → merge to development → `occ app:update decidesk` (or bind-mount rebuild). Rollback: revert commit. No DB rollback needed.

## Open Questions

None — all decisions above are taken provisionally; see `DEFERRED_QUESTIONS` in the parent artifact generation output.
