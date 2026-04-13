# Tasks: Schemas and Data Model

## Task 1 — Register template with all 17 schemas
- [ ] Replace `example` schema in `lib/Settings/decidesk_register.json` with all 17 entity schemas
- [ ] Each schema: slug, icon, version, title, description, type, required, properties with types
- [ ] Add `x-openregister` seed data (3-5 objects per schema) using `@self` envelope
- [ ] Deduplication check: no overlap with OpenRegister built-in fields (id, uuid, uri, etc.)

## Task 2 — Deep link registration
- [ ] Update `lib/Listener/DeepLinkRegistrationListener.php` to register all 17 entity types
- [ ] URL template: `/apps/decidesk/#/{entities}/{uuid}` (lowercase plural)

## Task 3 — Frontend store initialization
- [ ] Update `src/store/store.js` to register all 17 object types via `registerObjectType()`
- [ ] Schema slugs match register template; register slug = `decidesk`

## Task 4 — Router routes
- [ ] Add index route `/{entities}` and detail route `/{entities}/:id` for each entity type
- [ ] Flat routes, all named, props via arrow function

## Task 5 — Navigation menu
- [ ] Update `src/navigation/MainMenu.vue` with grouped entity navigation items
- [ ] Groups: Governance, Meetings, Deliberation, Outcomes, Documents, Commerce

## Task 6 — Index views
- [ ] Create `src/views/{Entity}/Index.vue` for each entity using CnIndexPage + useListView
- [ ] Row click navigates to detail view
- [ ] Add button creates new entity (id='new')

## Task 7 — Detail views
- [ ] Create `src/views/{Entity}/Detail.vue` for each entity using CnDetailPage + CnDetailCard
- [ ] Edit/Delete header actions
- [ ] Related entities displayed in CnDetailCard tables where applicable

## Task 8 — PHPUnit tests
- [ ] Create `tests/Unit/Service/SettingsServiceTest.php` with ≥3 test methods
- [ ] Create `tests/Unit/Listener/DeepLinkRegistrationListenerTest.php` with ≥3 test methods

## Task 9 — Quality checks
- [ ] Run `composer check:strict` — all checks pass
- [ ] Run `npm run lint` — no errors
