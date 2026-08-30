# Tasks — Decidesk manifest v1: per-page Vue → JSON manifest renderer

## 1. Per-page mapping decision

- [x] 1.1 Walk every page in `src/manifest.json` (20 entries). For each, read the corresponding `src/views/<Page>.vue` to understand its current behaviour.
- [x] 1.2 Decide each page's target type per the `design.md` mapping table: 8 `index`, 9 `detail`, 1 `dashboard`, 2 `custom`.
- [x] 1.3 Document genuine exceptions vs lib gaps vs migration cost in `design.md`'s "Custom-fallback inventory" section.

## 2. Manifest rewrite

- [x] 2.1 Rewrite `src/manifest.json`. Bump `version` from `0.4.0` to `1.0.0`.
- [x] 2.2 Keep all 20 page `id`s and `route`s identical so vue-router routes don't break.
- [x] 2.3 For each `type: "index"` page, declare `config.{ register, schema, columns, sidebar }`. Source `register` slug `"decidesk"` and `schema` slug from `lib/Settings/decidesk_register.json`.
- [x] 2.4 For each `type: "detail"` page, declare `config.{ register, schema, sidebarTabs }`. Tab inventory per `design.md`.
- [x] 2.5 For `Dashboard`, declare `config.{ widgets, layout }`. Source widgets from existing `Dashboard.vue` KPIs.
- [x] 2.6 For surviving `type: "custom"` pages (`LiveMeeting`, `Settings`), preserve `component` field intact.
- [x] 2.7 Drop `component` field from all 18 migrated entries.

## 3. Validator script

- [x] 3.1 Add `tests/validate-manifest.js` — a Node script that loads `src/manifest.json`, loads the v1.1.0 schema (read from the `manifest-page-type-extensions` worktree as the canonical source), runs Ajv with formats, prints validation result.
- [x] 3.2 Run the validator. Confirm zero schema errors.
- [x] 3.3 Document how to re-run the validator in `tasks.md` (this section). Re-run command: `node tests/validate-manifest.js`.

## 4. Per-page Vue files (TODO markers)

- [x] 4.1 Add a TODO header comment to each of the 18 migrated `src/views/<Page>.vue` files: `// TODO(decidesk-manifest-v1): obsolete after @conduction/nextcloud-vue release ships manifest-page-type-extensions + manifest-abstract-sidebar; delete in cleanup commit.`
- [x] 4.2 Do NOT delete any Vue file in this commit — the existing router still imports them.
- [x] 4.3 List the obsolete files in `design.md`'s "Cleanup follow-up" section so the next commit knows what to delete.

## 5. Spec artifacts

- [x] 5.1 `openspec/changes/decidesk-manifest-v1/proposal.md` — rewritten to reflect this scope.
- [x] 5.2 `openspec/changes/decidesk-manifest-v1/design.md` — full mapping table + custom-fallback inventory + cleanup follow-up.
- [x] 5.3 `openspec/changes/decidesk-manifest-v1/tasks.md` — this file.
- [x] 5.4 `openspec/changes/decidesk-manifest-v1/specs/decidesk-app-manifest/spec.md` — Requirements REQ-DMV1-1 through REQ-DMV1-N covering the migration deltas.

## 6. Validation + commit

- [x] 6.1 Run `node tests/validate-manifest.js` and confirm zero schema errors.
- [x] 6.2 Confirm all 20 page `id`s round-trip (no rename, no drop).
- [x] 6.3 Stage `src/manifest.json`, `tests/validate-manifest.js`, `openspec/changes/decidesk-manifest-v1/`, and the TODO-marked Vue files.
- [x] 6.4 Commit on branch `feature/decidesk-manifest-v1` with no `Co-Authored-By` trailer (per project convention).
- [x] 6.5 Do NOT push, do NOT open a PR — Hydra coordination handles those.

## 7. Adoption follow-up (cleanup commit — landing alongside lib v1.x)

- [x] 7.1 Bump `package.json` `@conduction/nextcloud-vue` floor to `^1.0.0-beta.2` (the consolidated `manifest-v1` worktree's current declared version, which contains all six manifest sibling changes — `manifest-page-type-extensions`, `manifest-abstract-sidebar`, `manifest-schema-config-defs`, `manifest-settings-rich-sections`, `manifest-detail-sidebar-config`, `manifest-config-refs`). Placeholder pre-release tag — bump to the published v1.x semver when it ships.
- [x] 7.2 Replace `src/main.js` + `src/App.vue` with `CnAppRoot` + `CnPageRenderer` consumption. main.js now derives the vue-router routes from `manifest.pages[*].{id, route}` and mounts every route with `CnPageRenderer`. App.vue mounts `<CnAppRoot>` with the `#sidebar` slot wired to a single host `CnObjectSidebar` via the `objectSidebarState` provide/inject channel.
- [x] 7.3 Replace `src/router/index.js` with a router-from-manifest builder. Router config moved inline into `src/main.js` (`routesFromManifest()`); the standalone file is removed. Catch-all redirect to `/` preserved.
- [x] 7.4 Add `src/customComponents.js` exporting the surviving entries: `LiveMeetingView` (genuine realtime exception) plus 9 detail-tab custom components (`GovernanceBodyMembersTab`, `MeetingAgendaTab`, `MeetingParticipantsTab`, `AgendaMotionsTab`, `MotionAmendmentsTab`, `MotionVotesTab`, `AmendmentParentMotionTab`, `MinutesSignersTab`, `DecisionActionItemsTab`). `SettingsView` is NO LONGER in the registry — Settings page migrated to `type: "settings"` with `widgets[]` rich sections (per `manifest-settings-rich-sections`).
- [x] 7.5 Delete the 18 obsolete per-page Vue files listed in `design.md` "Cleanup follow-up". Also deleted: `src/views/SettingsView.vue` (replaced by manifest), `src/router/index.js` (folded into main.js), `src/navigation/MainMenu.vue` (replaced by lib's `CnAppNav` mounted by `CnAppRoot`).
- [ ] 7.6 Run the full Playwright regression suite (per the existing `regression-tests` change) and confirm every route still renders. **Blocked**: `@conduction/nextcloud-vue` is not yet released to npm; runtime smoke / regression awaits the v1.x publish.
- [ ] 7.7 If `Dashboard`'s widget types fail validation against the published widget registry, downgrade `Dashboard` to `type: "custom"` until a `stats-block` widget ships. **Blocked**: same — no runtime validation possible until the lib publishes.

## 7b. Sidebar tab implementations (cross-schema relations)

Replaces the 9 `CnNoteCard` placeholder stubs in `src/components/tabs/`
with full implementations against the lib's `useObjectStore` +
`CnDataTable` + `CnFormDialog` + `CnDeleteDialog` patterns.

- [x] 7b.1 `GovernanceBodyMembersTab` — list participants where
  `governanceBody === parent.id`; add-existing (link participant) /
  remove (clear `governanceBody` pointer) posture.
- [x] 7b.2 `MeetingAgendaTab` — list agenda items where
  `meeting === parent.id`, sorted by `orderNumber`; full CRUD via
  `CnFormDialog` driven by the `agenda-item` schema.
- [x] 7b.3 `MeetingParticipantsTab` — list participants whose
  `meetings[]` array contains the meeting id; add-existing /
  remove-from-array posture.
- [x] 7b.4 `AgendaMotionsTab` — list motions where
  `agendaItem === parent.id`; full CRUD with lifecycle status
  badges (lifecycle transitions stay on /motions/:id).
- [x] 7b.5 `MotionAmendmentsTab` — list amendments where
  `parentMotion === parent.id`; full CRUD with lifecycle status
  badges (transitions stay on /amendments/:id).
- [x] 7b.6 `MotionVotesTab` — read-only audit list. Walks
  motion → voting-round → vote chain and renders each round's
  tally + cast votes. Vote authoring stays on LiveMeeting.
- [x] 7b.7 `AmendmentParentMotionTab` — read-only summary card +
  click-through to /motions/:parentMotionId. Single parent display.
- [x] 7b.8 `MinutesSignersTab` — render `minutes.signers[]` array
  hydrated against participant store; add/remove + "Sign now"
  CTA that calls the existing `/api/minutes/:id/transition`
  lifecycle endpoint.
- [x] 7b.9 `DecisionActionItemsTab` — list action items where
  `decision === parent.id`; full CRUD with task-status pills
  matching the standalone /action-items index.
- [x] 7b.10 Add `src/components/tabs/useRelationStore.js` — small
  helper that lazily registers child object types with the lib's
  `useObjectStore` using schema slugs from the settings store.
- [x] 7b.11 Run `node tests/validate-manifest.js` (still passes —
  manifest unchanged) and `npx eslint src/components/tabs/`
  (clean) before committing.

## 8. Sign-off (per ADR-024 §9)

- [x] 8.1 `src/manifest.json` validates against the canonical schema.
- [x] 8.2 `manifest.dependencies` is `["openregister"]` (unchanged).
- [x] 8.3 Tier choice is explicit (Tier 4, prepared for `CnAppRoot` adoption in the follow-up).
- [x] 8.4 `manifest.version` is `"1.0.0"`.
- [x] 8.5 Custom-fallback inventory is documented and categorised (genuine exception / lib gap / migration cost).
- [ ] 8.6 Browser regression suite confirms all 20 routes resolve and render — **blocked on lib release**, runs in the adoption follow-up commit.
