# Tasks: Decidesk IA Alignment

**Change:** refactor-decidesk-ia-alignment
**App:** Decidesk

Atomic, file-by-file tasks. Do not bundle. Each task is a
self-contained edit a reviewer can verify in isolation.

---

## 1. Manifest: add three new MeetingDetail sidebar tabs

- [ ] **1.1** Open `src/manifest.json`. Locate the `MeetingDetail`
  page object (id `MeetingDetail`, route `/meetings/:id`).
- [ ] **1.2** In `config.sidebarTabs`, between the existing
  `participants` tab (order 30) and `audit` tab (order 90), insert
  three new tab entries (so order goes 10 / 20 / 30 / 40 / 50 / 60 /
  90):
  - `{ "id": "minutes",   "label": "Minutes",   "icon": "icon-file",      "component": "MeetingMinutesTab",   "order": 40 }`
  - `{ "id": "decisions", "label": "Decisions", "icon": "icon-checkmark", "component": "MeetingDecisionsTab", "order": 50 }`
  - `{ "id": "votes",     "label": "Votes",     "icon": "icon-checkmark", "component": "MeetingVotesTab",     "order": 60 }`
- [ ] **1.3** Re-validate the manifest against
  `app-manifest.schema.json` (the `$schema` already referenced in
  the file). Run the project's manifest validator if one is wired
  into `npm run lint`.

## 2. New tab component: MeetingMinutesTab.vue

- [ ] **2.1** Create
  `src/components/tabs/MeetingMinutesTab.vue`.
- [ ] **2.2** Add SPDX docblock (EUPL-1.2, Copyright Conduction
  B.V.) — SPDX inside the docblock per project convention.
- [ ] **2.3** Mirror the structure of
  `src/components/tabs/MeetingAgendaTab.vue`: receive
  `objectId` + `objectData` props, resolve `minutes` objects via the
  shared object store (`createObjectStore` pattern; no bespoke
  store), filter by the schema's meeting link field.
- [ ] **2.4** Render with `CnDataTable` from
  `@conduction/nextcloud-vue`. Columns: `title`, `lifecycle`,
  `version`, `approvedAt`. Each row links to `MinutesDetail` via
  vue-router `name: 'MinutesDetail'`.
- [ ] **2.5** Add "Notulen aanmaken" primary action. On click,
  create a new `minutes` object with `lifecycle: draft` and the
  meeting reference pre-filled, then `router.push` to
  `MinutesDetail` for the new id.
- [ ] **2.6** Empty state via `CnNoteCard` + create button.

## 3. New tab component: MeetingDecisionsTab.vue

- [ ] **3.1** Create
  `src/components/tabs/MeetingDecisionsTab.vue` (same SPDX +
  structure as task 2).
- [ ] **3.2** Resolve `decision` objects linked to the current
  meeting. Inspect the `decision` schema to determine whether the
  link is direct (decision.meeting) or via agenda-item; implement
  the appropriate traversal.
- [ ] **3.3** Columns: `title`, `outcome`, `decisionDate`,
  `isPublished`. Rows deep-link to `DecisionDetail`.
- [ ] **3.4** "Besluit aanmaken" primary action — create + navigate.
- [ ] **3.5** Empty state.

## 4. New tab component: MeetingVotesTab.vue

- [ ] **4.1** Create
  `src/components/tabs/MeetingVotesTab.vue` (same SPDX + structure).
- [ ] **4.2** Read-only — no create / edit / cast actions. Add a
  comment block citing `MotionVotesTab.vue`'s read-only posture
  (votes authored exclusively in LiveMeeting).
- [ ] **4.3** Resolve `voting-round` objects scoped to the meeting:
  walk meeting → agenda-item → motion → voting-round (or use the
  voting-round's direct meeting link if the schema declares one —
  verify against the schema before coding).
- [ ] **4.4** Group display by motion. Show: motion title, motion
  type, votes for / against / abstain (use existing `votesFor` etc.
  fields per `MotionVotesTab.vue`), round result badge, round
  timestamp.
- [ ] **4.5** Each motion-row deep-links to `MotionDetail` with the
  `votes` tab active (route param + tab query / hash, matching
  existing tab-deep-link convention in the app).
- [ ] **4.6** Empty state: "Geen stemmingen vastgelegd voor deze
  vergadering" — no create action.

## 5. Register custom components

- [ ] **5.1** Open `src/customComponents.js`.
- [ ] **5.2** Add three `import` lines (alphabetised with existing
  tab imports):
  - `import MeetingDecisionsTab from './components/tabs/MeetingDecisionsTab.vue'`
  - `import MeetingMinutesTab from './components/tabs/MeetingMinutesTab.vue'`
  - `import MeetingVotesTab from './components/tabs/MeetingVotesTab.vue'`
- [ ] **5.3** Add three entries to the default export object
  (alongside the existing tab registrations):
  `MeetingMinutesTab`, `MeetingDecisionsTab`, `MeetingVotesTab`.

## 6. i18n

- [ ] **6.1** Run `npm run l10n:extract` (or the project's
  equivalent) so the new `t('decidesk', ...)` calls in the three
  components surface in the translation catalogue.
- [ ] **6.2** Provide Dutch translations for the new strings:
  - "Minutes" → "Notulen"
  - "Decisions" → "Besluiten"
  - "Votes" → "Stemmingen"
  - "Notulen aanmaken" / "Besluit aanmaken" stay Dutch in source.
  - "Geen stemmingen vastgelegd voor deze vergadering" — Dutch
    source; English fallback "No voting recorded for this meeting".

## 7. Verification

- [ ] **7.1** `npm run lint` — must pass.
- [ ] **7.2** `npm run build` — bundles without warnings.
- [ ] **7.3** Manual verify in dev container at
  `http://localhost:8080/index.php/apps/decidesk/meetings/:id`:
  - The three new tabs render.
  - Each tab loads its filtered list (or empty state) without
    console errors.
  - The Minutes / Decisions create actions navigate to the
    respective detail pages with the meeting reference pre-set.
  - The Votes tab deep-links to MotionDetail > Votes for any
    listed motion.
  - Top-level Minutes / Decisions / Motions pages are unchanged.
- [ ] **7.4** No PHP/backend changes — `composer check:strict`
  unnecessary.

## 8. Out of scope (DO NOT do in this change)

- Renaming any English menu label to Dutch.
- Moving Dashboard under Settings/Beheer.
- Adding a Fracties & Organen surface (p3-citizen-participation
  stays archived).
- Removing the top-level `AgendaItems` register page.
- Any schema, lifecycle, or permission changes.
