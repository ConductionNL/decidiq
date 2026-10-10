# Specs — Decidiq Fractievoorzitter en Fractie Koppeling

## Requirements

### REQ-001: Fractie aanmaken na verkiezingsuitslag

**GIVEN** een nieuwe raadsperiode begint en de Kiesraad heeft de zetelverdeling vastgesteld
**WHEN** de griffier de fracties aanmaakt op basis van behaalde zetels per kandidatenlijst
**THEN** wordt per kandidatenlijst-met-zetels een Fractie aangemaakt met oprichtings-reden `verkiezingsuitslag`
**AND** worden de gekozen kandidaten als raadsleden geïnstalleerd
**AND** worden FractieLidmaatschappen aangemaakt met begin-datum gelijk aan beëdigings-datum

### REQ-002: Afsplitsing van raadslid

**GIVEN** een raadslid besluit zijn fractie te verlaten en als eenmansfractie verder te gaan
**WHEN** de griffier de afsplitsing registreert
**THEN** wordt het bestaande FractieLidmaatschap beëindigd met reden `afsplitsing`
**AND** wordt een nieuwe Fractie aangemaakt met oprichtings-reden `afsplitsing` en de oorspronkelijke fractie als bron-fractie
**AND** wordt een nieuw FractieLidmaatschap met rol `voorzitter` aangemaakt (afgesplitste fractie heeft per definitie de afsplitser als voorzitter)
**AND** worden vragen-tijd-minuten en fractie-vergoeding herberekend voor zowel originele als nieuwe fractie

### REQ-003: Terugkeer van afgesplitst raadslid

**GIVEN** een afgesplitst raadslid wil terugkeren naar de oorspronkelijke fractie
**WHEN** de griffier de terugkeer registreert
**THEN** wordt het FractieLidmaatschap bij de eenmansfractie beëindigd met reden `terugkeer-vorige-fractie`
**AND** wordt de eenmansfractie opgeheven met reden `geen-leden-meer`
**AND** wordt een nieuw FractieLidmaatschap aangemaakt bij de oorspronkelijke fractie
**AND** blijft de afsplitsings-periode zichtbaar in de historie voor transparantie

### REQ-004: Fractievoorzitter wissel

**GIVEN** een fractie heeft een voorzitter en de fractie besluit de voorzitter te wisselen
**WHEN** de fractie de wissel doorgeeft aan de griffie
**THEN** wordt de rol-eigenschap van de oude voorzitter teruggezet naar `lid`
**AND** wordt de rol-eigenschap van de nieuwe voorzitter op `voorzitter` gezet
**AND** wordt het Fractie-record bijgewerkt met de nieuwe voorzitter-koppeling
**AND** blijft historie van oud-voorzitters zichtbaar via FractieLidmaatschap-records

### REQ-005: Stemgedrag-historie altijd met juiste fractie-snapshot

**GIVEN** een raadslid heeft in 2024 gestemd terwijl lid van fractie A en is in 2025 overgestapt naar fractie B
**WHEN** een gebruiker stemgedrag van dit raadslid uit 2024 raadpleegt
**THEN** wordt het stemgedrag getoond met de vermelding "destijds lid van fractie A"
**AND** wordt het stemgedrag van 2025 getoond met "lid van fractie B"
**AND** wordt nooit retroactief de huidige fractie geprojecteerd op historische stemmingen

### REQ-006: Schriftelijke vraag indienen via fractie

**GIVEN** een raadslid wil een schriftelijke vraag aan het college stellen
**WHEN** het raadslid de vraag indient namens zijn fractie
**THEN** wordt een SchriftelijkeVraag aangemaakt met nummer-formaat `SV-{jaar}-{volgnummer}`
**AND** ontvangt de portefeuillehouder een notificatie met deadline (30 dagen, configurabel)
**AND** wordt de vraag direct gepubliceerd op het griffieportaal (configurabel: direct of na beantwoording)

### REQ-007: Antwoordtermijn bewaking

**GIVEN** een schriftelijke vraag is ingediend en de antwoord-deadline nadert
**WHEN** er 7 dagen voor deadline geen antwoord is geregistreerd
**THEN** ontvangt de portefeuillehouder een rappel
**AND** wordt bij overschrijden van de deadline de indienende fractie automatisch geïnformeerd
**AND** verschijnt de overschrijding op een openbare lijst van te-late antwoorden

### REQ-008: Fractie-ondersteuning en verantwoording

**GIVEN** een fractie ontvangt jaarlijkse vergoeding voor scholing en onderzoek
**WHEN** het jaar afsluit
**THEN** wordt de fractie verplicht een verantwoordingsdocument in te dienen met bestedingsoverzicht
**AND** wordt bij overschrijding van wettelijke drempel een accountantsverklaring vereist
**AND** worden niet-bestede middelen ofwel teruggevorderd ofwel doorgeschoven volgens lokale verordening

### REQ-009: Evenredigheidsberekening voor commissies

**GIVEN** de fractie-samenstelling wijzigt (afsplitsing of fusie)
**WHEN** een aanpassing commissie-zetelverdeling nodig is
**THEN** levert het systeem een berekening op basis van D'Hondt of grootste-rest-methode (configurabel per gemeente)
**AND** signaleert welke fracties zetels winnen of verliezen
**AND** kan de griffier de uitkomst overnemen voor automatische voorstel-tekst richting raadsbesluit

### REQ-010: Publiek fractie-overzicht

**GIVEN** een burger of journalist wil weten wie er in welke fractie zit
**WHEN** het publiek portaal `/raad/fracties` wordt geraadpleegd
**THEN** verschijnt per fractie: naam, voorzitter, leden met portret en portefeuille, aantal zetels, contactgegevens
**AND** wordt de historie van afsplitsingen en wisselingen tijdens de huidige raadsperiode getoond
**AND** wordt OWMS-metadata gegenereerd voor zoekmachine-indexering

### REQ-011: Opvolging bij tussentijds vertrek

**GIVEN** een raadslid neemt tussentijds ontslag (verhuizing, gezondheid, ander ambt)
**WHEN** de griffier het vertrek registreert met einde-datum en reden
**THEN** wordt automatisch de eerstvolgende-kandidaat op de oorspronkelijke kandidatenlijst voorgedragen als opvolger
**AND** wordt een Kiesraad-vraag-flow gestart om de benoeming te formaliseren
**AND** wordt na beëdiging een nieuw Raadslid-record en FractieLidmaatschap aangemaakt
**AND** worden alle commissie-zetels van het vertrokken raadslid op een actie-lijst gezet voor herverdeling

### REQ-012: Nevenfuncties-register openbaar publiek

**GIVEN** een raadslid is verplicht nevenfuncties te melden conform Gemeentewet artikel 12
**WHEN** een raadslid een nevenfunctie toevoegt of wijzigt (zelf via fractie-portaal of via griffie)
**THEN** wordt de wijziging direct gepubliceerd op `/raad/nevenfuncties`
**AND** wordt bij toevoeging van een betaalde nevenfunctie een notificatie naar de burgemeester gestuurd (integriteits-toets)
**AND** wordt jaarlijks een actualisatie-rappel verstuurd aan alle raadsleden

### REQ-013: Fractie-fusie

**GIVEN** twee fracties besluiten te fuseren tot één nieuwe fractie
**WHEN** beide fractievoorzitters de fusie aanvragen en de griffier deze formaliseert
**THEN** worden beide bron-fracties opgeheven met reden `fusie`
**AND** wordt een nieuwe Fractie aangemaakt met oprichtings-reden `fusie` en verwijzing naar beide bron-fracties
**AND** worden FractieLidmaatschappen voor alle leden overgezet naar de nieuwe fractie
**AND** worden gecombineerde vragen-tijd en fractie-vergoeding berekend

### REQ-014: Fractie-portaal voor interne coördinatie

**GIVEN** een fractievoorzitter wil coalitie- of fractie-overleg ondersteunen met digitale workspace
**WHEN** de fractievoorzitter het fractie-portaal opent
**THEN** ziet zij eigen fractieleden, openstaande schriftelijke vragen, agenda-prioriteiten, fractie-vergoeding-besteding
**AND** kan zij concept-moties en concept-vragen delen met fractieleden voor interne review
**AND** wordt elke wijziging gelogd voor verantwoording maar blijft de interne deliberatie afgeschermd

### REQ-015: Open data export fractie-historie

**GIVEN** een onderzoeker wil historische fractie-data analyseren
**WHEN** de export `fractie-historie-{periode}` wordt opgevraagd
**THEN** wordt een CSV en JSON-export gegenereerd met alle Fracties, FractieLidmaatschappen, mutaties met datums en redenen
**AND** wordt persoonlijke-aanvullende-data (privé-contact) automatisch weggelaten
**AND** wordt de export onder open licentie (CC0 of CC-BY) aangeboden conform Wet hergebruik overheidsinformatie

---

## Entity Schemas

### PolitiekePartij

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| naam | string | Yes | Max 200 | Party name (e.g., "PvdA", "VVD", "Edam Lokaal") |
| afkorting | string | No | Max 10 | Short code (e.g., "PVDA", "VVD") |
| type | enum | Yes | `landelijke-partij`, `lokale-partij`, `lijstverbinding` | Registration type per Wfpp |
| oprichtingsDatum | date | No | | Founded date |
| ophefdingsDatum | date | No | | Dissolved date (null if active) |
| kvkNummer | string | No | | KvK registration required for Wfpp compliance (if applicable) |
| website | string | No | URL | Party website for transparency |
| fractieOverstijgend | boolean | No | Default: true | National party (true) or local-only (false) |

### Kandidatenlijst

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| verkiezingsDatum | date | Yes | | Election date (e.g., 2024-03-06) |
| politiekePartijKoppeling | uuid | Yes | → PolitiekePartij | Linked party |
| lijstNummer | integer | Yes | 1–50 | Position on ballot |
| lijstTrekker | string | Yes | | Lead candidate name |
| kandidaten | array | Yes | Ordered list of names | Candidate ordering (immutable once filed) |
| behaaldeZetels | integer | Yes | 0–15 | Seats won in this election |
| restZetels | integer | No | | Remainder seats (if applicable per gemeente method) |

### Fractie

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| naam | string | Yes | Max 200 | Faction name (e.g., "Fractie PvdA") |
| afkorting | string | No | Max 10 | Short form |
| gemeenteKoppeling | uuid | Yes | → Gemeente (via Raadsperiode) | Municipality |
| raadsperiodeKoppeling | uuid | Yes | → Raadsperiode | Term start/end |
| politiekePartijKoppeling | uuid | No | → PolitiekePartij | Represented party (null for coalitions or splits) |
| kandidatenlijstKoppeling | uuid | No | → Kandidatenlijst | Origin list (null for mergers of non-electoral fractions) |
| oprichtingsDatum | date | Yes | | Formation date |
| oprichtingsReden | enum | Yes | `verkiezingsuitslag`, `afsplitsing`, `fusie`, `partijwissel` | Why fractie formed |
| bronFractie | uuid | No | → Fractie | Origin fractie (if split/merger) |
| ophefdingsDatum | date | No | | Termination date (null if active) |
| ophefdingsReden | enum | No | `einde-raadsperiode`, `fusie`, `geen-leden-meer` | Why fractie ended |
| aantaalZetels | integer | Yes | 1–15 | Current seat count |
| voorzitterKoppeling | uuid | Yes | → Raadslid | Chair person |
| plaatsvervangendeVoorzitterKoppeling | uuid | No | → Raadslid | Deputy chair |
| secretarisKoppeling | uuid | No | → Raadslid | Secretary |
| fractieVergoedingPerJaar | integer | No | € amount | Annual funding (per gemeente, based on seat count) |
| vrageUrentijdMinuten | integer | No | Minutes per term | Reserved question time |

### FractieLidmaatschap

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| raadslidKoppeling | uuid | Yes | → Raadslid | Council member |
| fractieKoppeling | uuid | Yes | → Fractie | Faction |
| beginDatum | date | Yes | | Membership start |
| eindDatum | date | No | Null if active | Membership end |
| rol | enum | Yes | `lid`, `voorzitter`, `plaatsvervangend-voorzitter`, `secretaris` | Role in faction |
| redenBegin | enum | Yes | `installatie`, `toetreding`, `afsplitsing-eigen`, `afsplitsing-naar`, `terugkeer`, `fusie` | Why membership started |
| redenEind | enum | No | `einde-fractie`, `verlaten-fractie`, `afsplitsing`, `terugkeer-vorige-fractie`, `overstap-andere-fractie`, `einde-raadslidmaatschap` | Why membership ended |

### SchriftelijkeVraag

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| vraagNummer | string | Yes | Format: `SV-{jaar}-{volgnummer}` | Question ID (auto-generated) |
| indienendeFractieKoppeling | uuid | Yes | → Fractie | Submitting faction |
| indienendRaadslidKoppeling | uuid | Yes | → Raadslid | Submitting council member |
| datumIngediend | date | Yes | | Submission date |
| onderwerp | string | Yes | Max 200 | Subject line |
| vraagTekst | string | Yes | Rich text (HTML) | Question body |
| portefeuillehouderKoppeling | uuid | No | → Raadslid (college) | Responsible alderman |
| status | enum | Yes | `ingediend`, `in-behandeling`, `beantwoord`, `vervallen-door-mondelinge-beantwoording` | Current status |
| antwoordTermijn | date | No | Default: 30 days from submitted | Answer deadline (per Awb analogy) |
| antwoordTekst | string | No | Rich text | College response |
| antwoordDatum | date | No | | Response date |
| vervolgVragen | array | No | Linked question IDs | Subsequent questions |

### FractieOndersteuning

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| fractieKoppeling | uuid | Yes | → Fractie | Funding recipient |
| jaar | integer | Yes | YYYY | Calendar year |
| vergoedingToegestemd | integer | Yes | € amount | Allocated budget |
| vergoedingBesteed | integer | No | € amount | Spent amount |
| verantwoordingsDocument | string | No | File UUID | Spend accountability document (PDF or link) |
| accountantsVerklaringVereist | boolean | No | Default: false | Certified audit required (per gemeente threshold) |
| accountantsVerklaard | date | No | | Audit completion date |
| opmerkingen | string | No | | Administrative notes |

---

## Integrations

### Stemgedrag (Vote) — Fractie Snapshot

When a vote (Stemgedrag) is recorded, compute and store the raadslid's faction at that moment:

```
stemgedrag.fractieSnapshot = FractieLidmaatschap where:
  raadslidUuid = stemgedrag.raadslidUuid
  AND beginDatum <= voteDatum
  AND (eindDatum IS NULL OR eindDatum >= voteDatum)
  → returns fractieUuid
```

This snapshot is immutable and prevents retroactive changes to vote attribution.

### Schriftelijke Vraag Numbering

Auto-increment per year:
- 2024: SV-2024-001, SV-2024-002, ...
- 2025: SV-2025-001, ...

Sequence is per municipality, not global.

### Commission Seat Reallocation (REQ-009)

When a faction changes (split or merge), trigger a D'Hondt calculation:

```
Input: Updated fractie list (aantaalZetels) for current raadsperiode
Method: D'Hondt (seats-proportional) or largest-remainder (configurabel per gemeente)
Output: New commission-seat distribution
API: GET /api/dhondt?raadsperiodeUuid=X → returns allocation proposal
UI: Griffier reviews, approves, generates raadsbesluit text
```

This is a **separate change** (depends on this spec but not implemented in this PR).

### Question-Time Allocation

Typically: 2 minutes × seat count per faction, min 5 minutes.

```
fractie.vraagTijdMinuten = fractie.aantaalZetels × 2 (min 5)
```

Configurabel per gemeente via settings. When fractie.aantaalZetels changes (split/merge), recalculate.

---

## API Patterns

### List Fractions

```
GET /api/objects?register=fractie&schema=fractie
  ?filter[raadsperiodeUuid]=<uuid>
  &filter[status]=active
  &sort=-beginDatum
  &limit=50&offset=0
```

### Compute Faction at Date

```
GET /api/objects/raadslid/<uuid>/fractieAtDatum/<date>
  → returns { fractieUuid, fractieNaam, rol, ... }
```

### Create Written Question

```
POST /api/objects
{
  register: "schriftelijke-vragen",
  schema: "schriftelijke-vraag",
  indienendeFractieUuid: "...",
  indienendRaadslidUuid: "...",
  onderwerp: "...",
  vraagTekst: "...",
  portefeuillehouderUuid: "..." (optional)
}
→ API auto-generates vraagNummer and sets status=ingediend
```

### Export Faction History (CSV/JSON)

```
GET /api/objects?register=fractie,fractielidmaatschap
  ?format=csv
  &period=2024-2028
  &excludePrivate=true
→ CSV: id,naam,type,beginDatum,eindDatum,reden,...
```

---

## Standards & References

- **Gemeentewet** artikel 7 (composition), 12 (conflict of interest register), 33 (member rights), 36b (incompatibility)
- **Kieswet** (seat allocation D'Hondt, succession on interim departure)
- **Wet financiering politieke partijen (Wfpp)** (party transparency, KvK requirements)
- **Wet bevordering integriteitsbeoordelingen (Bibob)** (integrity screening at installation)
- **Verordening rechtspositie raads- en commissieleden** (gemeente-specific)
- **Verordening fractieondersteuning** (gemeente-specific, required by Gemeentewet artikel 33)
- **OWMS v4** (public portal metadata)
- **TOOI/Bestuursorgaan** (standardized governance body reference)
- **WCAG 2.1 AA** (public portal accessibility)
- **AVG** (personal data handling)
- **NEN-ISO 27001** (info security for faction portal, later phase)
- **NORA** (inter-government data sharing)
- **OPRL** (reference architecture for council information)
- **Kiesraad protocols** (sZNL2 election result format import, separate integration change)
- **Algemene wet bestuursrecht (Awb)** (administrative deadlines for written questions)
