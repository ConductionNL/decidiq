# Tasks — Commissievergaderingen

## 1. Register Definition

- [ ] 1.1 Create `lib/Settings/commissievergaderingen_register.json` as OpenAPI 3.0.0 document with `x-openregister` extensions
- [ ] 1.2 Define `Commissie` schema with all properties: naam, type, portefeuille-scope, voorzitter, griffier, vergader-frequentie, vaste-vergaderdag, vergader-tijdstip, vergader-locatie, instellings-datum, instellings-besluit, opheffings-datum, openbaarheids-default
- [ ] 1.3 Define `CommissieLidmaatschap` schema with properties: commissie-ref, raadslid-ref, fractie (snapshot), rol, begin-datum, eind-datum, beëindigings-reden
- [ ] 1.4 Define `CommissieVergadering` schema with relation to Commissie, plus velden: vergader-datum, vergader-tijd-start, vergader-tijd-eind, locatie, openbaar (ja/besloten/gedeeltelijk), status, audio-opname-url, video-opname-url, conceptverslag, definitief-verslag
- [ ] 1.5 Define `CommissieAgendapunt` schema with relation to CommissieVergadering, plus: volgnummer, titel, type (mededeling, bespreekstuk, hamerstuk, advies-aan-raad, inspraak, besloten-onderdeel), beoogd-raadsvoorstel-ref, portefeuillehouder, behandel-duur-geschat, bijlagen
- [ ] 1.6 Define `CommissieAdvies` schema with relation to CommissieAgendapunt + plenaire AgendaItem, plus: advies-strekking (positief, negatief, verdeeld, geen-advies, verzoek-tot-aanpassing), advies-tekst (rich text), stemverhouding-samenvatting, fractie-standpunten (array), inspraak-samenvatting
- [ ] 1.7 Define `BelangenverstrengelingDeclaratie` schema with relation to CommissieLid + CommissieAgendapunt, plus: soort (financieel-belang, aandeelhouderschap, bestuursfunctie, familierelatie, eerder-betrokken, geen), beschrijving, gevolg (onthoudt-zich-van-stemming, verlaat-vergadering, meldt-maar-blijft), declaratie-datum
- [ ] 1.8 Define `InspraakAanmelding` schema with relation to CommissieVergadering + CommissieAgendapunt, plus: twee veld-secties (contactgegevens: naam, email, telefoon, adres; publiek-deel: spreker-naam, organisatie, spreektijd-aanvraag), onderwerp (publiek), status (aangemeld, goedgekeurd, afgewezen, gesproken, niet-verschenen), bijdrage-tekst, woon-of-werk-plaats
- [ ] 1.9 Define `Presentielijst` schema with relation to CommissieVergadering + CommissieLid, plus: aanwezig (aanwezig, afwezig-met-bericht, afwezig-zonder-bericht, plaatsvervangen-door), aankomst-tijd, vertrek-tijd, presentievergoeding-uitbetaald
- [ ] 1.10 Add Commissie seed data: 3 Nederlandse commissies (Commissie Ruimte, Commissie Sociaal, Commissie Audit) met TOOI-portefeuille-scope
- [ ] 1.11 Add CommissieLidmaatschap seed data: 5+ leden per fractie-mix over commissies
- [ ] 1.12 Add CommissieVergadering seed data: 3+ vergaderingen met verschillende statussen (gepland, agenda-vastgesteld, gesloten)
- [ ] 1.13 Add CommissieAgendapunt seed data: 8+ agendapunten per vergadering-mix (mededeling, bespreekstuk, advies-aan-raad, inspraak, besloten)
- [ ] 1.14 Verify schema.org type annotations present (commissie → custom:CommissionBody, CommissieLid → foaf:Person, etc.)

## 2. Backend Services

- [ ] 2.1 Create `lib/Service/CommissionService.php` with methods:
  - `getCommission(uuid): Commission`
  - `listCommissions(filters, pagination): array`
  - `createCommission(array $data): Commission`
  - `updateCommission(uuid, array $data): Commission`
  - `deleteCommission(uuid): void`
- [ ] 2.2 Add `CommissionService::updateComposition(uuid, array $newMembership)` to handle fractie-evenredigheid berekening
- [ ] 2.3 Add validation in `updateComposition` against Reglement-van-Orde rules (bijv max commissieleden per fractie)
- [ ] 2.4 Create `lib/Service/CommissionMeetingService.php` with methods:
  - `getMeeting(uuid): CommissieVergadering`
  - `listMeetings(commissie-uuid, filters): array`
  - `createMeeting(commissie-uuid, array $data): CommissieVergadering`
  - `transitionMeeting(uuid, new-status): CommissieVergadering`
  - `publishMinutes(uuid, minutes-text): CommissieVergadering`
- [ ] 2.5 Add `CommissionMeetingService::finalizeAdvice(agenda-item-uuid, fractie-standpunten)` to create CommissieAdvies with gestructureerde fractie-data
- [ ] 2.6 Create `lib/Service/CommissionAdviceService.php` with:
  - `getAdvice(uuid): CommissieAdvies`
  - `linkAdviceToPlenaryItem(advice-uuid, plenaire-agenda-item-uuid): void` (automatische koppeling)
  - `getAdviceForPlenaryItem(agenda-item-uuid): CommissieAdvies`
- [ ] 2.7 Create `lib/Service/ConflictDeclarationService.php` with:
  - `createDeclaration(lid-uuid, agendapunt-uuid): BelangenverstrengelingDeclaratie`
  - `updateDeclaration(declaratie-uuid, soort, beschrijving, gevolg): void`
  - `getDeclarationsForMeeting(meeting-uuid): array`
  - `alertChairAndSecretary(declaratie-uuid)` (notification-service integratie)
- [ ] 2.8 Create `lib/Service/InspraakService.php` with:
  - `submitInspraakRequest(commissie-uuid, agendapunt-uuid, contactgegevens, onderwerp): InspraakAanmelding`
  - `approveInspraak(aanmelding-uuid): void`
  - `rejectInspraak(aanmelding-uuid, reden): void`
  - `getPublicInspraakForMeeting(meeting-uuid): array` (contactgegevens gefilterd)
  - `checkInspraakDeadline(meeting-uuid): bool`
- [ ] 2.9 Create `lib/Service/PresenceListService.php` with:
  - `initializePresenceList(meeting-uuid, commissie-uuid): Presentielijst`
  - `recordPresence(presentielijst-uuid, lid-uuid, status, aankomst-tijd): void`
  - `recordSubstitution(presentielijst-uuid, afwezig-lid-uuid, plaatsvervanger-uuid): void`
  - `finalizPresenceList(presentielijst-uuid): void`
- [ ] 2.10 All services inject `ObjectService` (OpenRegister CRUD) + `Audit Logservice`
- [ ] 2.11 All services implement error-handling: invalid UUIDs → HTTP 400, not-found → 404, forbidden → 403

## 3. REST Controllers

- [ ] 3.1 Create `lib/Controller/CommissionController.php` with routes:
  - `GET /apps/decidesk/api/commissions` → list all (with filters: type, governance-body)
  - `GET /apps/decidesk/api/commissions/{uuid}` → get single
  - `POST /apps/decidesk/api/commissions` → create (griffier+ required)
  - `PUT /apps/decidesk/api/commissions/{uuid}` → update (griffier+ required)
  - `PUT /apps/decidesk/api/commissions/{uuid}/composition` → update samenstelling (griffier+ required)
  - `DELETE /apps/decidesk/api/commissions/{uuid}` → delete (griffier+ required)
- [ ] 3.2 Create `lib/Controller/CommissionMembershipController.php` with routes:
  - `GET /apps/decidesk/api/commissions/{uuid}/members` → list leden per commissie
  - `POST /apps/decidesk/api/commissions/{uuid}/members` → add lid (fractie-voorzitter or griffier+)
  - `DELETE /apps/decidesk/api/commissions/{uuid}/members/{lid-uuid}` → remove lid
- [ ] 3.3 Create `lib/Controller/CommissionMeetingController.php` with routes:
  - `GET /apps/decidesk/api/commissions/{uuid}/meetings` → list vergaderingen
  - `GET /apps/decidesk/api/commissions/{uuid}/meetings/{meeting-uuid}` → get vergadering
  - `POST /apps/decidesk/api/commissions/{uuid}/meetings` → schedule vergadering (griffier+)
  - `PUT /apps/decidesk/api/commissions/{uuid}/meetings/{meeting-uuid}` → update vergadering
  - `PUT /apps/decidesk/api/commissions/{uuid}/meetings/{meeting-uuid}/transition` → change status (griffier+)
  - `PUT /apps/decidesk/api/commissions/{uuid}/meetings/{meeting-uuid}/minutes` → approve minutes (griffier+)
- [ ] 3.4 Create `lib/Controller/CommissionAgendaController.php` with routes:
  - `GET /apps/decidesk/api/meetings/{meeting-uuid}/agenda` → list agendapunten
  - `POST /apps/decidesk/api/meetings/{meeting-uuid}/agenda` → add agendapunt (griffier+)
  - `PUT /apps/decidesk/api/meetings/{meeting-uuid}/agenda/{item-uuid}` → update agendapunt
  - `POST /apps/decidesk/api/meetings/{meeting-uuid}/agenda/{item-uuid}/finalize-advice` → sluit adviesvorming af (voorzitter+)
- [ ] 3.5 Create `lib/Controller/CommissionAdviceController.php` with routes:
  - `GET /apps/decidesk/api/advice/{uuid}` → get CommissieAdvies
  - `GET /apps/decidesk/api/agenda-items/{item-uuid}/commission-advice` → get advies voor plenair (REQ-CVG-007)
  - `POST /apps/decidesk/api/advice/{uuid}/link-to-plenary` → link naar plenaire item (griffier+)
- [ ] 3.6 Create `lib/Controller/ConflictDeclarationController.php` with routes:
  - `GET /apps/decidesk/api/declarations` → list voor huidi ge lid
  - `GET /apps/decidesk/api/declarations/{meeting-uuid}` → list per vergadering (griffier+)
  - `POST /apps/decidesk/api/declarations/{uuid}` → submit/update declaratie (lid)
  - `PUT /apps/decidesk/api/declarations/{uuid}/consequence` → set gevolg (voorzitter+)
  - `GET /apps/decidesk/api/conflict-reports` → generate rapportage (griffier+, rekenkamer+)
- [ ] 3.7 Create `lib/Controller/PresenceListController.php` with routes:
  - `GET /apps/decidesk/api/meetings/{meeting-uuid}/presence` → get presentielijst
  - `POST /apps/decidesk/api/meetings/{meeting-uuid}/presence/{lid-uuid}` → record presence (griffier+)
  - `POST /apps/decidesk/api/meetings/{meeting-uuid}/absences/{lid-uuid}/substitute` → record plaatsvervanging (lid + griffier+)
- [ ] 3.8 Create `lib/Controller/InspraakPublicController.php` with routes (NO auth required for submission):
  - `POST /apps/decidesk/api/public/commissions/{uuid}/inspraak` → submit request (public)
  - `GET /apps/decidesk/api/public/commissions/{uuid}/meetings/{meeting-uuid}/inspraak` → list publieke inspraak (public)
- [ ] 3.9 Create `lib/Controller/InspraakController.php` with routes (griffier+):
  - `GET /apps/decidesk/api/commissions/{uuid}/inspraak` → list alle aanmeldingen
  - `PUT /apps/decidesk/api/inspraak/{uuid}/approve` → goedkeuren
  - `PUT /apps/decidesk/api/inspraak/{uuid}/reject` → afwijzen
- [ ] 3.10 Add authorization middleware to all controllers: `requireCommissionMember()`, `requireChairOrSecretary()`, `requireGriffier()` checks
- [ ] 3.11 Add input validation to all POST/PUT routes (JSON Schema validation per OpenAPI spec)

## 4. Data Migrations

- [ ] 4.1 Create `lib/Migration/CommissionRegistration.php` implementing `IRepairStep` that:
  - Loads `commissievergaderingen_register.json`
  - Calls `ConfigurationService::importFromApp('commissievergaderingen')`
  - Logs success/failure + object counts
  - Implements `getDescription()` with fix description
- [ ] 4.2 Register repair step in `appinfo/info.xml` under `<repair-steps><post-migration>`
- [ ] 4.3 Test idempotency: run repair step twice, verify no duplicate seed-data created
- [ ] 4.4 Add rollback documentation: "Delete register via OpenRegister admin UI"

## 5. Tests

- [ ] 5.1 Create `tests/Unit/Service/CommissionServiceTest.php`:
  - Test create, update, delete commission
  - Test updateComposition with fractie-calculation
  - Test validation against Reglement rules
- [ ] 5.2 Create `tests/Unit/Service/CommissionMeetingServiceTest.php`:
  - Test meeting lifecycle transitions
  - Test finalizeAdvice with fractie-standpunten
- [ ] 5.3 Create `tests/Unit/Service/ConflictDeclarationServiceTest.php`:
  - Test lazy-creation per agendapunt
  - Test alert on soort !== 'geen'
  - Test consequences: onthoudt-zich, verlaat, meldt-maar-blijft
- [ ] 5.4 Create `tests/Unit/Service/InspraakServiceTest.php`:
  - Test contactgegevens/onderwerp separation
  - Test deadline checking
  - Test public endpoint (no auth)
- [ ] 5.5 Create `tests/Integration/CommissionApiTest.php`:
  - Test full workflow: create commission → schedule meeting → add agenda → finalize advice
  - Test authorization: griffier can edit, lid cannot
  - Test advice-linking to plenary item
- [ ] 5.6 Create `tests/Integration/PresenceAndSubstitutionTest.php`:
  - Test presentielijst-creation
  - Test substitution workflow: lid absence → substitute gets access
  - Test voting-rights: substitute counts, not original
- [ ] 5.7 Create `tests/Integration/ConflictDeclarationTest.php`:
  - Test alert generation on conflict declaration
  - Test consequences affect voting
- [ ] 5.8 Create `tests/Integration/InspraakPublicTest.php`:
  - Test public submission (no auth)
  - Test griffier approval/rejection
  - Test public view filters contactgegevens
- [ ] 5.9 Ensure all tests use real OpenRegister ObjectService (not mocks)
- [ ] 5.10 Run `composer check:strict` — ensure all PHP is typed

## 6. Documentation

- [ ] 6.1 Create `docs/features/commissievergaderingen.md` with:
  - Feature overview (3-5 paragraphs)
  - Architecture diagram (relations between schemas)
  - User journeys: griffier planning, lid declaring conflict, burger inspraak
  - API reference (routes, required auth, example payloads)
  - Configuration options (inspraak-deadline-uren, Reglement-van-Orde file location)
  - Troubleshooting: common errors, logs to check
- [ ] 6.2 Add ADR note in docs explaining commission-type enum vs separate schemas decision
- [ ] 6.3 Document privacy-model for inspraak (contactgegevens vs onderwerp)
- [ ] 6.4 Document audit-trail strategy for besloten zittingen (Woo-compliance)
- [ ] 6.5 Create migration guide for legacy systems (iBabs, Notubiz) → commissievergaderingen format

## 7. Integration with Other Apps

- [ ] 7.1 Define API contract with decidesk-base (Meeting, AgendaItem, Participant relations)
- [ ] 7.2 Define hooks for docudesk: "commission-minutes-ready" → trigger PDF rendering
- [ ] 7.3 Define hooks for opentalk: "meeting-status-changed" → livestream pause/resume for besloten zitting
- [ ] 7.4 Define hooks for opencatalogi: "minutes-published" → TOOI-export with OWMS-metadata
- [ ] 7.5 Document MCP tool extension points (future p3: AI chat companion querying commission advice)

## 8. Deployment & Rollout

- [ ] 8.1 Create migration documentation for griffies (how to set up first commissies)
- [ ] 8.2 Create data-validation checklist (all commissies linked to raadsbesluiten?)
- [ ] 8.3 Create backup strategy: register-export before first production use
- [ ] 8.4 Set up monitoring: track failed inspraak-submissions, conflict-declaration alert latency
- [ ] 8.5 Create runbook for common issues (duplicate members, deadline-enforcement, audit-trail gaps)
