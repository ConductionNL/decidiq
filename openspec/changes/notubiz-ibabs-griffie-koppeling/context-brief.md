---
status: draft
---
# NOTUBIZ en iBabs Griffie-Koppeling (Bidirectional Sync)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer > Integraties / Beheer

**Rationale:** Bidirectional sync configured by griffie  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Vrijwel alle 342 Nederlandse gemeenten (en de 12 provincies en 21 waterschappen) gebruiken één van twee griffie-systemen voor het beheer van politieke besluitvorming: NOTUBIZ (geschat marktaandeel circa 60% gemeenten) of iBabs (Gemeente Oplossingen, circa 35%). De overige paar procent gebruikt Companion (oudere LIAS-leverancier) of een zelfgebouwd systeem. Beide marktleiders zijn proprietary-SaaS, met gesloten datamodellen, en de gemeente betaalt jaarlijks zes-cijferige bedragen voor licenties, hosting en aanvullende modules. Bovendien zijn de exit-kosten enorm: alle agenda's, besluiten, moties en stemmingen zitten vast in vendor-formaten.

Decidesk wil het democratisch besluitvormingsproces openen via een open datamodel (gebaseerd op het Popolo-standaard, het OASIS Akoma Ntoso-standaard en de Nederlandse Officiële Bekendmakingen-standaard STOP/TPOD). Maar gemeenten kunnen niet van de ene op de andere dag overstappen — het is realistischer om decidesk eerst naast NOTUBIZ of iBabs te draaien als open laag, met bidirectional sync, zodat: (1) de griffie haar bestaande tool blijft gebruiken voor de officiële workflow, (2) raadsleden via decidesk een betere UX krijgen voor consultatie en participatie, (3) alle data tegelijkertijd in een open formaat beschikbaar komt voor burgers en hergebruik door andere apps (launchpad dashboards, opencatalogi inzichten, docudesk PDF-publicatie), en (4) de gemeente over 2-5 jaar de optie heeft om NOTUBIZ/iBabs uit te faseren zonder data-verlies.

Deze spec definieert de bidirectional synchronisatie: lezen uit NOTUBIZ/iBabs van alle vergadering-gerelateerde objecten (agenda, vergaderstukken, aanwezigheid, stemming, besluiten, moties, amendementen, schriftelijke vragen, fracties), schrijven terug van decidesk-gegenereerde inzichten (samenvattingen, transcript-links, participatie-signalen), en het oplossen van conflicten bij gelijktijdige bewerkingen via een hybrid CRDT/last-writer-wins-with-audit-trail-model.

## Data Model

Deze spec hergebruikt grotendeels de bestaande decidesk-schema's en voegt een synchronisatie-laag toe in openconnector.

**Bestaande decidesk-schema's** die door deze sync worden gevuld: `Vergadering`, `Agendapunt`, `Vergaderstuk`, `Aanwezigheid`, `Stemming`, `Besluit`, `Motie`, `Amendement`, `SchriftelijkeVraag`, `Persoon`, `Fractie`, `Rol`.

**Nieuwe schema's in deze spec:**

**Schema: `ExternalIdentifier`** — koppelt een decidesk-object aan zijn equivalent in NOTUBIZ of iBabs. Velden: `id` (uuid), `localObject` (ref, polymorphic), `localObjectType` (string, schema-naam), `provider` (enum: notubiz, ibabs, companion), `externalId` (string, vendor-id), `externalUrl` (uri), `externalEtag` (string, voor conditional GETs), `lastSyncedAt` (datetime), `syncDirection` (enum: pull-only, push-only, bidirectional), `lastModifiedLocal` (datetime) en `lastModifiedExternal` (datetime).

**Schema: `SyncJob`** — uitvoeringsregister voor een sync-operatie. Velden: `id` (uuid), `provider`, `direction` (enum: pull, push, full), `scope` (enum: incremental, full, single-object), `targetObjectType` (string, optioneel), `targetObjectId` (string, optioneel), `startedAt` (datetime), `finishedAt` (datetime), `status` (enum: pending, running, success, partial, failed), `objectsProcessed` (int), `objectsCreated` (int), `objectsUpdated` (int), `objectsSkipped` (int), `objectsConflicted` (int), `errorLog` (text), `triggeredBy` (enum: webhook, cron, manual, event).

**Schema: `SyncConflict`** — onopgeloste conflicten bij bidirectional sync. Velden: `id` (uuid), `externalIdentifier` (ref), `field` (string, JSONPath), `localValue` (json), `externalValue` (json), `localChangedAt` (datetime), `externalChangedAt` (datetime), `localChangedBy` (ref naar User of system), `detectedAt` (datetime), `status` (enum: open, resolved-local, resolved-external, resolved-merged, resolved-ignored), `resolvedBy` (ref naar User), `resolvedAt` (datetime), `resolution` (text — vrije notitie).

**Schema: `FractieRol`** — per fractie de rolverdeling die bij beide providers wordt gesynchroniseerd. Velden: `id` (uuid), `fractie` (ref), `persoon` (ref), `rol` (enum: fractievoorzitter, plaatsvervangend-fractievoorzitter, secretaris, woordvoerder, gewoon-lid), `portefeuille` (array string, bv. ["zorg", "onderwijs"]), `actiefVan` (date), `actiefTot` (date, optioneel).

## Requirements

**REQ-NIK-001: Volledige initiële import per provider**
Bij installatie of bij toevoegen van een nieuw kalenderjaar MOET de connector een volledige history-import kunnen uitvoeren.
- GIVEN een gemeente met een NOTUBIZ-account en bestaande historie van 2 jaar, WHEN een beheerder `full-sync notubiz --from 2024-01-01` triggert, THEN MOET het systeem alle vergaderingen, agendapunten, vergaderstukken, stemmingen en besluiten van die periode importeren binnen 24 uur, gepaginated en met rate-limit respect (NOTUBIZ default: 60 requests/min).
- GIVEN dezelfde gemeente schakelt later over op iBabs voor sommige commissies, WHEN ook iBabs wordt geconfigureerd, THEN MOET de connector duplicate-detectie uitvoeren op `Vergadering.datum + Vergadering.naam` en bestaande Vergaderingen koppelen via een tweede `ExternalIdentifier` in plaats van duplicaten te creëren.
- GIVEN een vergaderstuk in NOTUBIZ is 200MB groot, WHEN het wordt geïmporteerd, THEN MOET de connector het bestand streamen naar object-storage in plaats van in-memory te laden.

**REQ-NIK-002: Incrementele pull elke 15 minuten via cron of webhook**
Standaard MOET de connector elke 15 minuten een delta-pull uitvoeren; bij beschikbaarheid van webhooks MOET die de primaire trigger zijn.
- GIVEN NOTUBIZ ondersteunt geen webhooks (per 2026), WHEN de cron loopt, THEN MOET de connector een `?modified-since={lastSyncedAt}` query uitvoeren op alle relevante endpoints.
- GIVEN iBabs publiceert wel webhooks op `meeting.updated`, WHEN een webhook binnenkomt, THEN MOET de connector binnen 60 seconden de wijziging in decidesk reflecteren.
- GIVEN een delta-pull bevat 0 wijzigingen, WHEN deze klaar is, THEN MOET de SyncJob `status: success` met `objectsProcessed: 0` registreren en geen events uitsturen.

**REQ-NIK-003: Push van decidesk-wijzigingen terug naar provider**
Wijzigingen die in decidesk worden gedaan op gesynchroniseerde objecten MOETEN terug naar de provider worden geduwd voor de velden die de provider als writable markeert.
- GIVEN een griffier wijzigt in decidesk de titel van een agendapunt, WHEN de wijziging wordt opgeslagen, THEN MOET een push-job binnen 30 seconden de wijziging naar de provider sturen via `PATCH /agenda-items/{id}` (NOTUBIZ) of `agendaItems.update` (iBabs SOAP).
- GIVEN de provider rejected de push (bijvoorbeeld omdat de vergadering is afgesloten), WHEN de rejection binnenkomt, THEN MOET de connector een SyncConflict creëren met `resolution: provider-rejected` en de wijziging in decidesk markeren als "niet-gesynchroniseerd".
- GIVEN een veld is niet writable in de provider (bv. `Stemming.uitslag` in NOTUBIZ is read-only), WHEN een decidesk-wijziging daarvoor wordt aangeboden, THEN MOET de connector de push skippen, een waarschuwing loggen en de wijziging in decidesk als local-only behouden.

**REQ-NIK-004: Conflict-detectie bij gelijktijdige bewerking**
Wanneer hetzelfde veld lokaal én extern is gewijzigd sinds de laatste sync MOET de connector een SyncConflict aanmaken in plaats van automatisch te overschrijven.
- GIVEN een Vergadering met `lastSyncedAt: 10:00`, GIVEN een griffier wijzigt om 10:30 in NOTUBIZ de locatie naar "Burgerzaal", GIVEN tegelijk wijzigt een decidesk-gebruiker om 10:35 de locatie naar "Raadszaal", WHEN om 10:45 de sync loopt, THEN MOET een SyncConflict worden aangemaakt met beide waarden en `status: open`.
- GIVEN een SyncConflict met `status: open`, WHEN een griffier de conflict-resolution UI opent, THEN MOET hij beide waarden zien, kunnen kiezen voor lokaal, extern of een nieuwe merged value, en moet de keuze worden gelogd in `resolvedBy`/`resolution`.
- GIVEN een SyncConflict blijft 7 dagen `open`, WHEN de cron loopt, THEN MOET een notificatie naar de griffier en de decidesk-beheerder worden gestuurd, en MOET de relevante objecten in decidesk een visuele "in conflict"-badge tonen.

**REQ-NIK-005: Per-fractie rollen, portefeuilles en woordvoerderschappen**
FractieRol-objecten MOETEN worden gesynchroniseerd zodat de juiste persoon namens de fractie spreekt over een onderwerp.
- GIVEN NOTUBIZ levert per fractie een lijst leden met rol-velden, WHEN deze worden geïmporteerd, THEN MOET een FractieRol per persoon-fractie-combinatie worden aangemaakt en gekoppeld aan het lopende kalenderjaar.
- GIVEN een fractievoorzitter wisselt, WHEN dat bij NOTUBIZ wordt geregistreerd, THEN MOET de oude FractieRol `actiefTot` krijgen en een nieuwe FractieRol worden aangemaakt; bestaande verwijzingen in decidesk (bv. in Stemmingen) MOGEN niet worden gemuteerd.
- GIVEN iBabs werkt zonder expliciete portefeuilles, WHEN een import uit iBabs loopt, THEN MOET het `portefeuille`-veld leeg blijven en MOET de griffie het in de UI handmatig kunnen vullen zonder dat de sync het overschrijft.

**REQ-NIK-006: Stemming-detectie inclusief hoofdelijke stemming**
Stemmingen MOETEN met volledige uitsplitsing per persoon worden gesynchroniseerd, niet alleen totalen.
- GIVEN een hoofdelijke stemming over een motie, WHEN deze uit NOTUBIZ wordt geïmporteerd, THEN MOET er per aanwezig raadslid één `StemUitgebracht`-object zijn met `stem: voor|tegen|onthouden|afwezig|niet-deelgenomen`.
- GIVEN een fractie-stemming (zonder hoofdelijk), WHEN deze wordt geïmporteerd, THEN MOET één `StemUitgebracht` per fractie worden aangemaakt met `aantal`-veld en `stem`-veld; individuele leden MOETEN automatisch worden gemarkeerd als "verondersteld conform fractielijn" tenzij anders aangegeven.
- GIVEN een stemming wordt later geannuleerd (door een procedurefout), WHEN dat bij de provider wordt geregistreerd, THEN MOET de Stemming `status: geannuleerd` krijgen met audit-link naar de annulering, MAG het record NIET worden verwijderd.

**REQ-NIK-007: Moties, amendementen en schriftelijke vragen met versiebeheer**
Moties en amendementen MOETEN met volledige versiehistorie worden gesynchroniseerd inclusief de relatie tot agendapunt en uiteindelijke besluit.
- GIVEN een motie M2024-15 wordt in NOTUBIZ aangemaakt en later geamendeerd (versie 2), WHEN beide versies worden geïmporteerd, THEN MOET decidesk twee `MotieVersie`-records hebben gekoppeld aan dezelfde Motie en MOET de UI standaard de meest recente versie tonen met "vorige versies"-link.
- GIVEN een schriftelijke vraag wordt door de wethouder beantwoord na 6 weken, WHEN het antwoord wordt gepubliceerd, THEN MOET de SchriftelijkeVraag een `antwoord`-veld krijgen en de `status` op `beantwoord` worden gezet, met `aantalDagen` berekend.
- GIVEN een amendement wordt ingetrokken vóór stemming, WHEN dat in NOTUBIZ gebeurt, THEN MOET het in decidesk `status: ingetrokken` krijgen en MOET de relatie met het agendapunt blijven bestaan voor traceerbaarheid.

**REQ-NIK-008: Aanwezigheid bij vergaderingen**
Per vergadering MOET een aanwezigheidslijst worden bijgehouden synchroon met de griffie-administratie.
- GIVEN NOTUBIZ levert presentielijsten via `/meetings/{id}/attendance`, WHEN deze wordt geïmporteerd, THEN MOET een Aanwezigheid-record per raadslid worden aangemaakt met `status: aanwezig|afwezig-gemeld|afwezig-zonder-bericht|deels-aanwezig` en eventuele `vervangenDoor`-ref.
- GIVEN een raadslid komt 30 minuten te laat, WHEN dat in iBabs wordt geregistreerd via tap-in op de microfoonkoppeling, THEN MOET de Aanwezigheid `aanwezigVanaf`-tijdstip krijgen.
- GIVEN een Aanwezigheid-update na publicatie van de besluitenlijst, WHEN deze wordt ontvangen, THEN MOET een notificatie naar de griffier worden gestuurd voor verificatie, omdat publicatie-na-correctie reputatieschade kan veroorzaken.

**REQ-NIK-009: Vergaderstukken met inhoudelijke metadata**
PDF-vergaderstukken en bijlagen MOETEN met provider-metadata worden gesynchroniseerd.
- GIVEN een vergaderstuk van 50MB bij agendapunt 7, WHEN het wordt geïmporteerd, THEN MOET het bestand worden gestreamd naar object-storage en MOET een Vergaderstuk-record worden gemaakt met `titel`, `bestandsnaam`, `mimeType`, `grootte`, `sha256`, `vertrouwelijkheidsniveau` en link naar het Agendapunt.
- GIVEN een stuk wordt aangemerkt als `vertrouwelijk` of `geheim` door de griffie, WHEN dat wordt geïmporteerd, THEN MOET het bestand NIET publiek toegankelijk zijn en MOET een toegangs-ACL gelden van enkel raadsleden en griffie.
- GIVEN een vergaderstuk wordt na de vergadering vervangen door een gecorrigeerde versie, WHEN deze wordt geïmporteerd, THEN MOET de oude versie als VergaderstukVersie behouden blijven en MOET de actieve versie de meest recente zijn.

**REQ-NIK-010: Observability — sync-dashboard en alerting**
De connector MOET een operationeel dashboard met statistieken en alert-thresholds bieden.
- GIVEN een beheerder opent `/admin/sync-status`, WHEN de pagina laadt, THEN MOET deze per provider de laatste 24 uur SyncJobs tonen, het aantal open conflicten, gemiddelde sync-latency en quota-gebruik bij de provider.
- GIVEN drie opeenvolgende sync-jobs hebben `status: failed`, WHEN de derde faalt, THEN MOET een PagerDuty/Slack/e-mail alert naar de geconfigureerde on-call gaan.
- GIVEN het aantal openstaande SyncConflicts groter dan 25 is, WHEN de health-check loopt, THEN MOET de overall connector-status `degraded` worden, zichtbaar in launchpad en in het admin-paneel.

## Standards & Sources

- **OASIS Akoma Ntoso 1.0** — XML-schema voor parlementaire en juridische documenten; gebruikt voor canonieke serialisatie van moties en besluiten.
- **Popolo specification** — JSON-LD ontology voor "popolarized" politieke data (Personen, Organisaties (fracties), Memberships, Posts, Events).
- **STOP/TPOD** (Standaard Officiële Publicaties / Toepassingsprofiel) — KOOP/Logius standaard voor officiële bekendmakingen door gemeenten (besluitenlijsten, raadsbesluiten).
- **DCAT-AP-NL** — voor publicatie van open vergaderdata als dataset op data.overheid.nl.
- **NOTUBIZ API** — REST + JSON, OAuth2. Endpoints: `/meetings`, `/agenda-items`, `/documents`, `/voting`, `/decisions`, `/motions`, `/amendments`, `/questions`, `/people`, `/parties`, `/attendance`. Rate-limit 60 req/min per tenant.
- **iBabs API** — SOAP + JSON-REST (modern). Endpoints documented in iBabs API portal; modules: `Public Data`, `Meeting Items`, `Voting`, `Documents`. Webhooks via "Notifications" module.
- **AVG artikel 6 lid 1 sub e + artikel 6 lid 3** — wettelijke grondslag voor verwerking persoonsgegevens van raadsleden (publieke functie). Voor insprekers: artikel 6 lid 1 sub a (toestemming).
- **Wet open overheid (WOO) artikel 3.3** — actieve openbaarmakingsplicht voor besluitenlijsten, stemmingen, agenda's, vergaderstukken.
- **Gemeentewet artikel 19-25** — procedures rond raadsvergaderingen.
- **Reglement van Orde van de gemeenteraad** (gemeente-specifiek) — bepaalt hoe moties, amendementen en schriftelijke vragen worden behandeld.

## Cross-app integration

- **decidesk** (base) — eigenaar van alle politiek-domein-schema's; deze spec vult ze via openconnector.
- **openconnector** — host voor de NOTUBIZ-adapter, iBabs-adapter en de generieke `SyncJob`/`SyncConflict`-engine. Adapters draaien als losse jobs in de openconnector-runner.
- **openregister** — host voor de schema-definities en de `ExternalIdentifier`-koppeltabel.
- **raadsvergadering-livestream-transcript** (zustertspec) — consumeert de microfoon-events die deze adapter publiceert om Spreker-koppelingen op te bouwen.
- **opentalk** (optioneel) — kan stemmingen real-time pushen naar een Talk-kanaal van de fractie.
- **docudesk** — gebruikt gesynchroniseerde Besluiten voor automatische PDF-besluitenlijst-generatie met STOP/TPOD-conform XML.
- **opencatalogi** — publiceert de gesynchroniseerde data als open dataset op data.overheid.nl conform DCAT-AP-NL.
- **launchpad** — KPI's: aantal vergaderingen per maand, aantal aangenomen/verworpen moties, presentiepercentage per fractie, sync-success-rate per provider.
- **softwarecatalog** — registreert decidesk + de NOTUBIZ/iBabs-adapter als toegepaste software voor Pas-toe-of-leg-uit-rapportage.

## Target users

- **Griffie** (primair) — blijft in haar vertrouwde tool werken, maar krijgt automatisch een open kopie en betere dashboards. Krijgt notificaties bij conflicten en kan deze in één UI oplossen.
- **Raadsleden** — krijgen een betere mobiele/zoek-ervaring via decidesk, terwijl hun standaard-workflow (stukken voorbereiden in iBabs/NOTUBIZ-app) onveranderd blijft.
- **Fracties** — kunnen hun eigen moties, schriftelijke vragen en stemgedrag analyseren over jaren heen.
- **Burgers en journalisten** — krijgen consistente, doorzoekbare toegang tot besluitvorming over alle gemeenten heen.
- **Onderzoekers en Rekenkamers** — kunnen longitudinaal en cross-municipaal analyseren (bv. hoe stemden GroenLinks-fracties over windenergie?).
- **CIO/CISO** — krijgt een exit-strategie van vendor lock-in zonder big-bang migratie.
- **VNG en BZK** — krijgen sectorale dataset over besluitvormingsprocessen; input voor beleid rond toegankelijke democratie.
- **Toezichthouders WOO** — kunnen verifiëren of actieve openbaarmakingsplicht wordt nageleefd.
- **Civic tech-ontwikkelaars** (Open State Foundation, Code for NL) — krijgen via één consistente API toegang tot besluitvormingsdata over honderden gemeenten, in plaats van per gemeente een aparte koppeling te moeten bouwen of te scrapen.
- **Lokale rekenkamers** — krijgen een audit-trail van wijzigingen op besluiten en moties die uniek is voor decidesk (de huidige proprietary systemen loggen mutatie-historie soms onvolledig).

## Implementatie-overwegingen

**Keuze 1: Polymorphic ExternalIdentifier boven separate join-tabellen per type.** Met circa 12 verschillende decidesk-objecttypen zou per-type een join-tabel beheermatig zwaar zijn. Eén polymorphic tabel met `localObjectType + localObjectId + provider + externalId` als unique-key biedt voldoende performance (indexes op `(localObjectType, localObjectId)` en `(provider, externalId)` zijn voor de verwachte volumes — tienduizenden records per gemeente per jaar — ruim genoeg).

**Keuze 2: Last-writer-wins met conflict-flag, geen automatic merge.** Echte CRDT-merge over rijke domeinmodellen (motie-tekst, besluit-formulering) is gevaarlijk: een automatische tekstmerge kan een juridisch onjuiste tekst produceren. Beter is om expliciet conflicten te detecteren en aan een mens (griffier) voor te leggen. Voor velden waar conflicten zelden voorkomen (`Vergadering.locatie`, `Persoon.email`) is dat geen pijn; voor zware velden (motie-tekst) hoort het ook zo.

**Keuze 3: Pull-based default, push-back opt-in per organisatie.** Niet elke gemeente wil decidesk-wijzigingen terug naar NOTUBIZ/iBabs duwen — sommige zien decidesk in eerste instantie als read-only publieke laag. Push moet per `(organisatie, provider, objecttype)` configureerbaar zijn, met sane defaults (notities altijd local-only, agenda-titel push-able).

**Keuze 4: Schemas blijven in decidesk-register, niet in een aparte sync-register.** Argument: een sync-laag mag het canonieke datamodel niet vervuilen. ExternalIdentifier en SyncJob horen wel in een aparte register `decidesk-sync` om de scheiding van zorgen helder te houden, maar mogen geen circulaire dependencies veroorzaken.

## Out-of-scope (toekomstige iteraties)

Niet in v1: synchronisatie met Companion (LIAS) — kleine markt, separate spec waard wanneer een klant het concreet vraagt; bulk-export naar STOP/TPOD-XML voor officiële bekendmakingen (dat is een aparte publicatie-spec via docudesk); historische backfill verder dan 2 jaar (eerst valideren op 2-jaar performance); webhook-receiver voor providers die nog geen webhooks publiceren (afhankelijk van Forum Standaardisatie-richting); meer geavanceerde conflict-merge (bv. semi-automatic merge bij niet-overlappende edits in lange teksten — interessant maar niet kritisch voor v1); auto-detectie van privacy-gevoelige content in vergaderstukken (zou een NLP-stap toevoegen — apart traject); integratie met Statenvergaderingen (provincies) en Algemeen Bestuur (waterschappen) — provincies en waterschappen gebruiken ook NOTUBIZ/iBabs maar hebben specifieke schema-uitbreidingen die separate validatie vereisen.

## Risico's en mitigaties

**Risico: Vendor-API-veranderingen breken sync.** NOTUBIZ en iBabs kunnen breaking changes doorvoeren met korte aankondigingstermijn. Mitigatie: connector-versies geversioneerd; contract-tests die dagelijks tegen sandbox-omgevingen lopen; alert wanneer een sandbox-test faalt zodat de bug-fix vóór productie-rollout van de API klaar is.

**Risico: Performance-degradatie bij grote gemeenten.** Amsterdam met 45 raadsleden en 18 commissies per maand genereert mogelijk meer dan 10.000 sync-events per dag. Mitigatie: per-organisatie sharding; rate-limit-awareness met token-bucket per provider; bulk-endpoints prefereren boven per-object-GETs.

**Risico: Conflict-explosie bij gelijktijdige gewone bewerkingen.** Als griffier én decidesk-redacteur tegelijk werken zonder coördinatie kunnen conflicten zich opstapelen tot een onwerkbare queue. Mitigatie: per-veld locking-hints in de UI ("griffie bewerkt dit veld in NOTUBIZ — wijzig niet"); reactieve UI-updates via SSE bij externe wijzigingen; conflict-quota-alert bij overschrijden van 10 conflicten per week.

**Risico: Push-back schaadt griffie-workflow.** Als decidesk per ongeluk verkeerde data terugduwt naar NOTUBIZ kan dat de officiële agenda verstoren. Mitigatie: push-back default off; per `(organisatie, objecttype, veld)` granulair aan-/uit-zetten; dry-run-mode waarin een week mock-pushes worden gelogd zonder daadwerkelijk te schrijven.
