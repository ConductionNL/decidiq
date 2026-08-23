# Design — Commissievergaderingen

## Context

Decidiq base levert basismodel Meeting (plenaire raadsvergaderingen), AgendaItem, Participant, Membership. Commissievergaderingen zijn sub-type van Meeting met aanvullende velden: commissie-koppeling, commissie-specifieke agendapunt-types, adviesvorming per agendapunt, belangenverstrengeling-registratie, inspraak-aanmelding, en besloten-zittings-afhandeling.

**Huidige decidiq-staat:**
- Meeting, AgendaItem, Participant, Membership, GovernanceBody zijn gedefinieerd in p1-schemas-en-data-model en p3-governance-bodies
- Geen commissie-specifieke logica bestaat nog
- Griffie-workflows (plaatsvervanging, adviesvorming, belangenverstrengeling) zijn handmatig en error-prone

**Stakeholders:**
- Griffiepoort: Commissie-voorzitter, griffier, griffie-medewerkers (planning, verslaglegging)
- Commissie-leden: Inzien agenda, geven advies, declareren belangenconflicten
- Fractie-voorzitters: Commissieleden aanwijzen, plaatsvervangers regelen
- Burgers: Inspreken via publiek portaal, openbare vergaderingen volgen
- Raadsleden niet-in-commissie: Commissieadvies ter voorbereiding plenaire raad inzien
- Collegeleden: Presenteren voorstellen in commissie, beantwoorden vragen

## Goals / Non-Goals

**Goals:**
- Definieer 8 OpenRegister schemas voor commissie-entiteiten met correcte field-types, enum-waarden, en schema.org-annotaties
- Implementeer business logic voor commissie-samenstelling (fractie-evenredigheid), plaatsvervanger-management, adviesvorming met fractie-standpunten
- Zorg voor belangenverstrengeling-registratie (artikel 28 Gemeentewet) en gevolg-afhandeling (onthoudt-zich, verlaat, meldt-maar-blijft)
- Publiceer commissieadvies automatisch op plenaire agendapunt als gestructureerde data (fractie-standpunten)
- Ondersteun inspraak-aanmelding via publiek portaal met privacy-scheiding (contactgegevens ↔ publieke inspraak)
- Zorg voor audit-trail van toegang tot besloten-zittings-stukken voor Woo-toets
- Documenteer commissions API-patronen zodat andere gemeenten kunnen overnemen

**Non-Goals:**
- Frontend UI (REST API only; UI in p3 sprint)
- Audio/video-opname en -livestream (opentalk-integratie volgt)
- PDF-rendering van verslagen (docudesk-integratie volgt)
- Automatische kalender-sync (openconnector volgt)
- Dashboard-rendering (mydash volgt)
- Multi-tenant commissie-sharing (common pattern later)

## Decisions

### D1: Acht schemas in één commissievergaderingen-register

Alle commissie-entiteiten (Commissie, CommissieLidmaatschap, CommissieVergadering, CommissieAgendapunt, CommissieAdvies, BelangenverstrengelingDeclaratie, InspraakAanmelding, Presentielijst) leven in één register.

**Alternatieven:** één register per commissie-type (audit, ruimte, sociaal, bestuur) — afgewezen omdat gemeente-specifieke commissie-samenstelling het logischer maakt om commissie-type als enum-attribute te modeleren, niet als schema-scheiding.

**Waarom dit:** Eenvoudige cross-entity-relaties; één register = één import-stap; schema-type enum biedt latere flexibiliteit (bv burger-jury's als tijdelijk type).

### D2: CommissieVergadering erft van Meeting, voegt commissie-velden toe

CommissieVergadering is sub-type van Meeting (relation, niet inheritance). Het heeft alle Meeting-velden (title, scheduledDate, meetingType, lifecycle) plus commissie-speci fieke velden (commissie-ref, openbaar ja/nee, status van presentielijst).

**Alternatieven:** volledig aparte Meeting-subklasse in OpenRegister; shared-type-pattern via polymorphism. **Waarom dit:** Meeting-lifecycle (scheduled, opened, paused, adjourned, closed) past commissie-vergaderingen; commissie-velden zijn additioneel, niet conflicterend.

### D3: CommissieAdvies als linked annotation op plenaire AgendaItem

CommissieAdvies heeft relatie naar CommissieAgendapunt (waar het ontstond) EN relation naar het beoogd-raadsvoorstel (plenaire AgendaItem). Fractie-standpunten zijn array-veld op CommissieAdvies (niet separate entities).

**Alternatieven:** CommissieAdvies als complete duplicate van plenaire AgendaItem-advies; losse PDF-bijlage. **Waarom dit:** Gestructureerde data voor LLM-verwerking (MCP-tools kunnen fractie-standpunten analyseren); traceerbare link plenair ↔ commissie; single source of truth.

### D4: BelangenverstrengelingDeclaratie verplicht per agendapunt, lazily-triggered

Wanneer CommissieAgendapunt wordt geopend voor beraadslaging, wordt per CommissielIdmaatschap een declaratie-record gemaakt met default-waarde `soort: 'geen'` (of `vraag-uit-formulier` als konfigurabel). Lid kan deze wijzigen. Declaratie met `soort !== 'geen'` triggert automatische alert naar voorzitter + griffier.

**Alternatieven:** pre-session-declaration (vroegtijdig invullen); optioneel (lid kan overslaan). **Waarom dit:** Naleving artikel 28 Gemeentewet; laziness voorkomt papierwerk als geen conflicten; alert leidt tot live-afhandeling (onthoudt-zich, verlaat, meldt-maar-blijft).

### D5: InspraakAanmelding scheidt contactgegevens (intern) van inspraak-onderwerp (publiek)

InspraakAanmelding heeft twee veldsets: `contactgegevens` (naam, email, telefoon, adres — intern) en `onderwerp` (spreker-naam, organisatie, spreektijd-aanvraag — publiek). Griffie ziet beide; publieke verslaglegging toont alleen onderwerp.

**Alternatieven:** één 'inspraak-bijdrager'-veld met alles gemengd. **Waarom dit:** Privacy (burgers willen niet dat hun adres publiek is); AVG-naleving (intern contact gescheiden van publieke registratie); technische scheiding enforces privacy.

### D6: Presentielijst is aparte schema, niet geëmbedded in CommissieVergadering

Presentielijst is één record per CommissieVergadering met array van Presentielijst-items (one-to-many). Elk item koppelt aan CommissieLid (via Membership) met status, aankomst/vertrek-tijd.

**Alternatieven:** presentiestatus direct op CommissieLidmaatschap; array-veld op CommissieVergadering. **Waarom dit:** Multiple vergaderingen per lid → historisch per-vergadering spoor nodig; OpenRegister relaties maken one-to-many clear.

### D7: Fracties-snapshot in CommissieLidmaatschap

CommissieLidmaatschap heeft `fractie`-veld dat de huidige fractie van het CommissieLid (via Raadslid/Membership) op dat moment vastlegt. Bij fractie-wisseling van een raadslid blijft bestaande CommissieLidmaatschap ongewijzigd; volgende commissie-aanwijzing maakt nieuw lid maatschap met nieuwe fractie.

**Alternatieven:** fractie via live-relation naar Membership. **Waarom dit:** Commissie-samenstelling moet reproduceerbaar zijn (wat waren fractie-verhoudingen op datum X); snapshot voorkomt later-wijzigen van historische commissie-samenstelling.

### D8: Commissie-type via enum, geen aparte schemas

Commissie heeft `type`-enum (vast, tijdelijk, ad-hoc) en `portefeuille-scope` (array van TOOI-categorieën). Geen aparte AuditCommissie-, RuimteCommissie-schemas.

**Alternatieven:** per-type-schema (AuditCommissie, RuimteCommissie, etc.). **Waarom dit:** Gemeente-specifieke configuratie; enum is uitbreidbaar (bv burger-jury's toevoegen zonder schema-change); TOOI-scope hanteert gemeente-specifieke prioriteiten.

## Reuse Analysis

| Code-pad | Bron | Hergebruik-strategie |
|---|---|---|
| Meeting-lifecycle (scheduled, opened, closed, etc.) | decidiq p2-meeting-management | CommissieVergadering uses relation naar Meeting; lifecycle-transitions via bestaande StateEngine |
| Participant/Membership model | decidiq p3-governance-bodies | CommissieLidmaatschap erft relatie-patronen van Membership; voegt commissie-specifieke velden toe |
| AgendaItem | decidiq p2-agenda-management | CommissieAgendapunt is separate schema; koppelt via relation naar CommissieVergadering |
| Speech/Vote/VotingRound | decidiq p2-motion-voting | CommissieAdvies is lighter-weight (geen formal voting, alleen stemmingsverhouding-samenvatting + fractie-standpunten) |
| OpenRegister ConfigurationService | openregister core | `importFromApp('commissievergaderingen')` voor register-import |
| Relation management | openregister core | Alle cross-entity-relaties via x-openregister-relaties in schema JSON (geen foreign keys) |
| ObjectService CRUD | openregister core | Reused voor alle schema-operations (list, get, create, update) |
| REST API | openregister core + decidiq controllers | Decidiq-controllers voor griffie-workflows (samenstelling, plaatsvervanging, adviesvorming); inheritance uit decidiq patterns |

**Geen nieuwe business-logic in provider** — services leveren alleen orchestration van existing CRUD + validatie.

## Seed Data

### Commissie (3 objecten — Nederlandse gemeenten)

```json
[
  {
    "@self": { "register": "commissievergaderingen", "schema": "Commissie", "slug": "commissie-ruimte-westerkwartier" },
    "naam": "Commissie Ruimte",
    "type": "vast",
    "portefeuille-scope": ["ruimtelijke-ordening", "wonen", "mobiliteit"],
    "voorzitter": { "register": "decidesk", "schema": "Raadslid", "id": "rd-001" },
    "griffier": { "register": "decidesk", "schema": "Medewerker", "id": "mw-001" },
    "vergader-frequentie": "maandelijks",
    "vaste-vergaderdag": "donderdag",
    "vergader-tijdstip": "19:30",
    "vergader-locatie": "Raadzaal Westerkwartier",
    "instellings-datum": "2022-06-15",
    "instellings-besluit": { "register": "decidesk", "schema": "RaadsBesluit", "id": "rb-001" },
    "openbaarheids-default": "openbaar"
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "Commissie", "slug": "commissie-sociaal-westerkwartier" },
    "naam": "Commissie Sociaal",
    "type": "vast",
    "portefeuille-scope": ["zorg", "jeugd", "participatie"],
    "voorzitter": { "register": "decidesk", "schema": "Raadslid", "id": "rd-002" },
    "griffier": { "register": "decidesk", "schema": "Medewerker", "id": "mw-001" },
    "vergader-frequentie": "maandelijks",
    "vaste-vergaderdag": "dinsdag",
    "vergader-tijdstip": "19:00",
    "vergader-locatie": "Raadzaal Westerkwartier",
    "instellings-datum": "2022-06-15",
    "instellings-besluit": { "register": "decidesk", "schema": "RaadsBesluit", "id": "rb-001" },
    "openbaarheids-default": "openbaar"
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "Commissie", "slug": "commissie-audit-westerkwartier" },
    "naam": "Commissie Audit",
    "type": "vast",
    "portefeuille-scope": ["financiën", "rekenkamer"],
    "voorzitter": { "register": "decidesk", "schema": "Raadslid", "id": "rd-003" },
    "griffier": { "register": "decidesk", "schema": "Medewerker", "id": "mw-002" },
    "vergader-frequentie": "tweewekelijks",
    "vaste-vergaderdag": "vrijdag",
    "vergader-tijdstip": "13:30",
    "vergader-locatie": "Kantoor Griffie",
    "instellings-datum": "2022-06-15",
    "instellings-besluit": { "register": "decidesk", "schema": "RaadsBesluit", "id": "rb-001" },
    "openbaarheids-default": "openbaar"
  }
]
```

### CommissieLidmaatschap (5 objecten)

```json
[
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieLidmaatschap", "slug": "lid-commissie-ruimte-001" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-ruimte-westerkwartier" },
    "raadslid": { "register": "decidesk", "schema": "Raadslid", "id": "rd-004" },
    "fractie": "Lokaal Belang",
    "rol": "lid",
    "begin-datum": "2022-06-15",
    "eind-datum": null
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieLidmaatschap", "slug": "lid-commissie-ruimte-002" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-ruimte-westerkwartier" },
    "raadslid": { "register": "decidesk", "schema": "Raadslid", "id": "rd-005" },
    "fractie": "GroenLinks",
    "rol": "lid",
    "begin-datum": "2022-06-15",
    "eind-datum": null
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieLidmaatschap", "slug": "lid-commissie-ruimte-plaatsvervanger" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-ruimte-westerkwartier" },
    "raadslid": { "register": "decidesk", "schema": "Raadslid", "id": "rd-006" },
    "fractie": "GroenLinks",
    "rol": "plaatsvervanger",
    "begin-datum": "2022-06-15",
    "eind-datum": null
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieLidmaatschap", "slug": "lid-commissie-sociaal-001" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-sociaal-westerkwartier" },
    "raadslid": { "register": "decidesk", "schema": "Raadslid", "id": "rd-007" },
    "fractie": "VVD",
    "rol": "lid",
    "begin-datum": "2022-06-15",
    "eind-datum": null
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieLidmaatschap", "slug": "lid-commissie-audit-001" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-audit-westerkwartier" },
    "raadslid": { "register": "decidesk", "schema": "Raadslid", "id": "rd-008" },
    "fractie": "CDA",
    "rol": "lid",
    "begin-datum": "2022-06-15",
    "eind-datum": null
  }
]
```

### CommissieVergadering (3 objecten)

```json
[
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieVergadering", "slug": "vergadering-ruimte-2026-05-22" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-ruimte-westerkwartier" },
    "vergader-datum": "2026-05-22",
    "vergader-tijd-start": "19:30",
    "vergader-tijd-eind": "21:00",
    "locatie": "Raadzaal Westerkwartier",
    "openbaar": "ja",
    "status": "gepland",
    "conceptverslag": null,
    "definitief-verslag": null
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieVergadering", "slug": "vergadering-sociaal-2026-05-20" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-sociaal-westerkwartier" },
    "vergader-datum": "2026-05-20",
    "vergader-tijd-start": "19:00",
    "vergader-tijd-eind": "20:30",
    "locatie": "Raadzaal Westerkwartier",
    "openbaar": "ja",
    "status": "agenda-vastgesteld",
    "conceptverslag": null,
    "definitief-verslag": null
  },
  {
    "@self": { "register": "commissievergaderingen", "schema": "CommissieVergadering", "slug": "vergadering-audit-2026-05-15" },
    "commissie": { "register": "commissievergaderingen", "schema": "Commissie", "id": "commissie-audit-westerkwartier" },
    "vergader-datum": "2026-05-15",
    "vergader-tijd-start": "13:30",
    "vergader-tijd-eind": "14:45",
    "locatie": "Kantoor Griffie",
    "openbaar": "ja",
    "status": "gesloten",
    "conceptverslag": "Vergadering ging over audit van begrotingsvoorstel 2025.",
    "definitief-verslag": "Vergadering ging over audit van begrotingsvoorstel 2025. Commissie adviseerde tot aanname."
  }
]
```

## Risks / Trade-offs

| Risico | Mitigatie |
|---|---|
| **R1 — Commissie-samenstelling wijzigt door fractie-wisseling; historische audit breekt.** CommissieLidmaatschap-records kunnen niet muteren; wijziging = eindigen bestaand + starten nieuw. | Fractie-snapshot enforces immutability; history audit-trail toont timings. |
| **R2 — Belangenverstrengeling-declaratie kan worden genegeerd of verkeerd ingevuld.** Lid kan per ongeluk `geen` zeggen terwijl belang bestaat. | Griffier en voorzitter krijgen alert bij `soort !== 'geen'`; live-checklist op vergadering. |
| **R3 — Inspraak-contactgegevens-leak via publieke verslaglegging.** Griffie publiceert per ongeluk contactgegevens in commissie-verslag. | Technische scheiding (twee veldsets) + schema-validatie + testen. |
| **R4 — CommissieAdvies niet gekoppeld aan plenaire raad door technische fout.** Griffie vergeet advies-link in plenaire voorbereiding. | Automatische link via API-trigger; plenaire agendapunt-veld `commissieAdviesRef` is verplicht als commissie-agendapunt bestaat. |
| **R5 — Presentielijst-management is handmatig en error-prone.** Griffier typt plaatsvervanging verkeerd. | API voor plaatsvervanging triggert presentielijst-update automatisch. |
| **R6 — Besloten-zittings-audit-trail leakt via Woo-request.** Systeem-logs tonen toegang tot vertrouwelijke stukken. | Audit-trail gescheiden van app-logs; dual-control voor openbaarmaking; Woo-handler valideert beschermingsgrond. |

## Migration Plan

1. **Add register template:** `lib/Settings/commissievergaderingen_register.json` met 8 schemas en seed data
2. **Add migration:** `lib/Migration/CommissionRegistration.php` implementeert `IRepairStep` die `ConfigurationService::importFromApp('commissievergaderingen')` aanroept
3. **Update Application.php:** Registreer CommissionRegistration in repair-steps
4. **Add services:** `CommissionService` + `CommissionMeetingService` met CRUD + business logic
5. **Add controllers:** `CommissionController`, `CommissionMeetingController`, `CommissionAdviceController` met REST API
6. **Add tests:** Unit tests per service, integration tests end-to-end
7. **Add docs:** `docs/features/commissievergaderingen.md` voor operators

**No data migration.** No modifications to existing Meeting, Participant, Membership schemas.

**Rollback:** Verwijder register via OpenRegister admin UI; geen SQL rollback nodig.

**Compatibility:** Opt-in via commissie-beheer in griffie-portaal.
