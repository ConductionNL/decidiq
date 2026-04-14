## 1. Backend — Register Configuration

- [x] 1.1 Create or update `lib/Settings/decidesk_register.json` with all 17 schema definitions (ActionItem, AgendaItem, Amendment, Decision, DigitalDocument, GovernanceBody, Meeting, Minutes, MonetaryAmount, Motion, Offer, Order, Participant, Product, Report, Vote, VotingRound) in OpenAPI 3.0.0 + x-openregister format
- [x] 1.2 Add seed data objects (8 objects: 2 GovernanceBody, 2 Meeting, 2 Participant, 1 Motion, 1 Decision) with Dutch values and `@self` envelope in the register JSON
- [x] 1.3 Verify `SettingsService::importFromApp()` is called in the repair step and imports all schemas and seed objects without errors

## 2. Backend — Settings Controller

- [x] 2.1 Ensure `SettingsController` exposes `GET /apps/decidesk/api/settings` returning `{ openRegisters: bool, isAdmin: bool }` and register slugs for all 17 entity types
- [x] 2.2 Ensure `POST /apps/decidesk/api/settings/load` triggers `ConfigurationService::importFromApp()` and returns success/error response

## 3. Frontend — Project Scaffold

- [x] 3.1 Create `src/main.js` bootstrapping Vue 2 app with Pinia and Vue Router, mounted on `#content`
- [x] 3.2 Create `src/store/store.js` exporting `initializeStores()` that fetches settings then registers all 17 entity types via `objectStore.registerObjectType(name, schemaSlug, registerSlug)`
- [x] 3.3 Create settings Pinia store (`src/store/modules/settings.js`) with `fetchSettings()` and `saveSettings()` actions
- [x] 3.4 Create `src/router/index.js` with Vue Router in history mode, base `/index.php/apps/decidesk/`, all 12 named flat routes (Dashboard, MeetingList, MeetingDetail, MotionList, MotionDetail, DecisionList, DecisionDetail, ParticipantList, ParticipantDetail, GovernanceBodyList, GovernanceBodyDetail, Settings), and catch-all `*` redirecting to `/`

## 4. Frontend — App.vue and MainMenu

- [x] 4.1 Create `src/App.vue` using `NcContent` with three rendering states: loading (`NcLoadingIcon`), no-OpenRegister (`NcEmptyContent`), ready (`MainMenu` + `NcAppContent` + `router-view`); call `initializeStores()` in `created()`
- [x] 4.2 Create `src/components/MainMenu.vue` using `NcAppNavigation` with `NcAppNavigationItem` for: Dashboard, Vergaderingen, Moties, Besluiten, Deelnemers, Bestuursorganen; footer settings link via `NcAppNavigationSettings`; all strings via `t(appName, '...')`
- [x] 4.3 Inject `sidebarState` in App.vue and provide it to child components for `CnIndexSidebar` support

## 5. Frontend — Dashboard Page

- [x] 5.1 Create `src/views/DashboardView.vue` using `CnDashboardPage` as the layout component
- [x] 5.2 Add four `CnStatsBlock` KPI cards: upcoming meetings (lifecycle=scheduled), pending motions (lifecycle=submitted|debating), open action items (taskStatus=open|in-progress), recent decisions (outcome=adopted, last 30 days)
- [x] 5.3 Fetch all four KPI data sources in parallel using `Promise.all` in the `created()` hook; show `NcLoadingIcon` skeleton while loading
- [x] 5.4 Add `CnChartWidget` (donut) showing Meeting lifecycle distribution; show empty-state message when no Meeting objects exist
- [x] 5.5 Add `CnTileWidget` tiles for Meetings, Motions, Decisions, Participants, Governance Bodies; each tile routes to its list view
- [x] 5.6 Implement 60-character title truncation with `…` for meeting titles on dashboard cards; expose full title via tooltip (`title` attribute)

## 6. Frontend — Global Search

- [x] 6.1 Add a search input component in the navigation header (or `NcAppNavigationSearch`) that triggers a search after 3+ characters with 400 ms debounce
- [x] 6.2 Implement `useSearch` composable (or inline) that calls OpenRegister `_search` API across Meeting, Motion, Decision, AgendaItem, Participant schemas
- [x] 6.3 Render up to 10 results in a floating dropdown with entity type icon, title, and lifecycle/status badge; group or label by type
- [x] 6.4 Implement keyboard navigation in dropdown: down/up arrow to move between results, Enter to navigate, Escape to close and return focus to input
- [x] 6.5 Show "Geen resultaten gevonden" message when the search returns zero matches

## 7. Frontend — Settings Page

- [x] 7.1 Create `src/views/SettingsView.vue` with `CnVersionInfoCard` as first element, then `CnRegisterMapping`, then `CnSettingsSection` blocks per feature area
- [x] 7.2 Add "Register opnieuw importeren" button that calls `POST /apps/decidesk/api/settings/load` and shows an `NcToast` success or error notification

## 8. Frontend — NL Design System Theming

- [x] 8.1 Create `src/assets/nl-design.css` mapping NL Design System token names (e.g. `--nldesign-color-brand-1`) to Nextcloud CSS variable equivalents (e.g. `var(--color-primary)`)
- [x] 8.2 Import `nl-design.css` in `src/main.js`; verify no `--nldesign-*` references remain in component `<style>` blocks
- [x] 8.3 Verify dark mode renders correctly by toggling Nextcloud dark mode and visually checking all pages

## 9. Accessibility

- [x] 9.1 Verify each page (Dashboard, list views, detail views, settings) has exactly one `<h1>` element with a meaningful translated heading
- [x] 9.2 Add a visually-hidden skip-navigation link (`<a href="#main-content">Sla navigatie over</a>`) as the first focusable element in `App.vue`; ensure it becomes visible on focus
- [x] 9.3 Verify ARIA landmarks (banner, navigation, main) are present on all pages; add explicit `role` attributes if Nextcloud wrappers do not provide them
- [x] 9.4 Keyboard-test all interactive elements (nav items, tiles, search input, search results, buttons) — each must receive visible focus ring and be operable with Enter/Space

## 10. Testing and Verification

- [x] 10.1 Smoke-test store initialisation: all 17 entity object stores are registered and `findAll()` returns without error (even if result is empty)
- [x] 10.2 Test router catch-all: navigating to `/nonexistent` redirects to `/`
- [x] 10.3 Test search: enter "vergadering" → results appear; enter 2 characters → no request sent
- [x] 10.4 Test dashboard KPI cards with seed data: counts match the filter criteria
- [x] 10.5 Run a WCAG 2.1 AA automated check (e.g. axe-core) against the Dashboard page; resolve all critical violations
- [x] 10.6 Validate `decidesk_register.json` against OpenAPI 3.0.0 schema using an OpenAPI validator
