---
status: draft
app: decidesk
spec: fractievoorzitter-fractie-koppeling
target_users: griffie, fractievoorzitters, raadsleden, fractie-ondersteuners, college, kiezers, politicologen
depends_on:
  - decidesk-base
references:
  - Gemeentewet artikel 7 (samenstelling raad), 33 (rechten raadsleden)
  - Kieswet (zetelverdeling)
  - Wet financiering politieke partijen (Wfpp)
  - https://www.kiesraad.nl/
  - https://www.raadsleden.nl/
  - Reglement van Orde (per gemeente)
---

# Fractievoorzitter en Fractie Koppeling

## Purpose

De fractie is in de Nederlandse politieke praktijk de centrale eenheid van politieke samenwerking — niet de individuele raadslid en niet de politieke partij, maar de groep raadsleden die namens dezelfde lijst is gekozen en als groep coördineert. Een fractie heeft een voorzitter die spreekrecht claimt namens de groep, vaste vragen-tijd in de raadsvergadering, ondersteuning vanuit de griffie, een fractie-vergoeding voor scholing en onderzoek, en een eigen vertegenwoordiging in het seniorenconvent waar agendaplanning wordt afgestemd. Een fractie is niet statisch — raadsleden splitsen af (denk aan landelijke voorbeelden waarbij raadsleden hun fractie verlaten en als eenmansfractie verdergaan), keren terug, fuseren met een andere fractie, of treden uit de raad waarna een opvolger op de partijlijst de zetel inneemt. Per raadsperiode zijn er gemiddeld 2 tot 4 zulke fractie-wijzigingen, in politiek roerige perioden oplopend tot 6 of meer — voldoende om elke griffie met handmatige administratie tot wanhoop te drijven en voldoende om elke onderzoeker die historisch stemgedrag wil analyseren tot grove fouten te brengen.

Deze spec definieert een eersteklas fractie-register dat al deze dynamiek vastlegt zonder historie te verliezen: per fractie wie de voorzitter is, wie de leden zijn, welke politieke partij en welke kandidatenlijst de fractie vertegenwoordigt, hoeveel vragen-tijd de fractie heeft, en welke ondersteuning ze ontvangen. Per raadslid wordt de complete fractie-lidmaatschapshistorie vastgelegd inclusief afsplitsings-momenten en reden, zodat stemgedrag-historie altijd correct getoond wordt met de fractie waarin het raadslid op dat moment lid was. Daarnaast ondersteunt de spec schriftelijke vragen aan het college via de fractie, een fractie-portaal waarmee fractievoorzitters intern hun standpunten coördineren, en publicatie van openbare fractie-gegevens conform Wfpp en open-data afspraken.

De spec scheidt nadrukkelijk vier concepten die in legacy-systemen vaak verward worden. Een PolitiekePartij is de juridische entiteit (vereniging conform Wfpp) die landelijk of lokaal bestaat. Een Kandidatenlijst is het tijdelijke samenwerkingsverband per verkiezing — bijvoorbeeld een lokale lijst die bij gemeenteverkiezingen samenwerkt met een landelijke partij. Een Fractie is de groep gekozen raadsleden die per zetel-toekenning ontstaat en gedurende een raadsperiode kan splitsen of fuseren. Een Raadslid is een persoon die via een Kandidatenlijst is verkozen en gedurende zijn termijn van Fractie kan wisselen. Deze scheiding maakt het mogelijk om vragen te beantwoorden als "hoeveel raadsleden waren in de afgelopen vier jaar van fractie gewisseld?" of "welke fracties zijn ontstaan uit afsplitsingen tijdens deze raadsperiode?" — vragen waar journalisten en politicologen routinematig naar vragen en waar gemeenten nu zelden direct antwoord op kunnen geven. De spec biedt verder een fractie-portaal waarmee een fractievoorzitter eigen leden kan bekijken, vragen-tijd kan plannen, schriftelijke vragen kan coördineren en de fractie-vergoeding-besteding kan administreren conform de gemeentelijke verordening.

## Data Model

**PolitiekePartij** is een schema met velden: naam, afkorting, type (`landelijke-partij`, `lokale-partij`, `lijstverbinding`), oprichtings-datum, opheffings-datum, kvk-nummer (verplicht conform Wfpp voor partijen boven drempel), website, fractie-overstijgend (`ja` voor landelijke partijen, `nee` voor zuiver lokale).

**Kandidatenlijst** is een schema met velden: verkiezings-datum, politieke-partij-koppeling, lijstnummer, lijsttrekker, kandidaten (geordende lijst), behaalde-zetels, restzetels.

**Fractie** is een schema met velden: naam, afkorting, gemeente-koppeling, raadsperiode-koppeling (begin en eind), politieke-partij-koppeling (kan null zijn voor lijstcombinaties of afsplitsingen), kandidatenlijst-koppeling (oorsprong-lijst), oprichtings-datum, oprichtings-reden (`verkiezingsuitslag`, `afsplitsing`, `fusie`, `partijwissel`), opheffings-datum, opheffings-reden (`einde-raadsperiode`, `fusie`, `geen-leden-meer`), aantal-zetels (initieel + huidig), voorzitter (raadslid-koppeling), plaatsvervangend-voorzitter, secretaris, fractie-vergoeding-jaar (€), vaste-vragen-tijd-minuten.

**Raadslid** is een schema met velden: persoon-koppeling, gemeente-koppeling, geboorte-datum, woonplaats, beroep, contactgegevens-publiek (e-mail, telefoon), portfolio-onderwerpen (TOOI), nevenfuncties (verplicht openbaar conform Gemeentewet artikel 12), beëdigings-datum, einde-raadslidmaatschap-datum, einde-reden (`einde-periode`, `vertrek-tussentijds`, `overlijden`, `incompatibiliteit`).

**FractieLidmaatschap** is een schema met velden: raadslid-koppeling, fractie-koppeling, begin-datum, eind-datum, rol (`lid`, `voorzitter`, `plaatsvervangend-voorzitter`, `secretaris`), reden-begin (`installatie`, `toetreding`, `afsplitsing-eigen`, `afsplitsing-naar`, `terugkeer`, `fusie`), reden-eind (`einde-fractie`, `verlaten-fractie`, `afsplitsing`, `terugkeer-vorige-fractie`, `overstap-andere-fractie`, `einde-raadslidmaatschap`).

**Stemgedrag** is een schema met velden: raadslid-koppeling, motie-of-amendement-of-besluit-koppeling, fractie-snapshot (fractie waarin lid was op stemmoment), stem (`voor`, `tegen`, `onthouden`, `afwezig`), datum, afwijkend-van-fractie (boolean, true als raadslid anders stemde dan meerderheid van eigen fractie).

**SchriftelijkeVraag** is een schema met velden: vraag-nummer (`SV-{jaar}-{volgnummer}`), indienende-fractie-koppeling, indienend-raadslid, datum-ingediend, onderwerp, vraag-tekst (rich text), portefeuillehouder, status (`ingediend`, `in-behandeling`, `beantwoord`, `vervallen-door-mondelinge-beantwoording`), antwoord-deadline (standaard 30 dagen), antwoord-tekst, antwoord-datum, vervolgvragen (lijst).

**FractieOndersteuning** is een schema met velden: fractie-koppeling, jaar, vergoeding-toegekend (€), vergoeding-besteed, verantwoordings-document, accountantsverklaring-vereist (boolean op basis van drempel).

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

## Standards

- **Gemeentewet** artikelen 7 (samenstelling raad), 12 (openbaar nevenfuncties-register), 33 (rechten raadsleden), 36b (incompatibiliteiten)
- **Kieswet** (zetelverdeling D'Hondt, restzetels, opvolging bij tussentijds vertrek)
- **Wet financiering politieke partijen (Wfpp)** voor partij-transparantie en giften-melding
- **Wet bevordering integriteitsbeoordelingen door het openbaar bestuur (Bibob)** voor integriteits-checks bij installatie
- **Verordening rechtspositie raads- en commissieleden** (gemeentelijk)
- **Verordening fractieondersteuning** (gemeentelijk, vereist door Gemeentewet artikel 33)
- **OWMS** versie 4 voor publicatie metadata
- **TOOI/Bestuursorgaan** voor uniforme bestuursorgaan-aanduiding
- **WCAG 2.1 AA** voor publiek portaal
- **AVG** voor verwerking persoonsgegevens raadsleden (gemengd publiek/privé regime)
- **TOOI** voor portfolio-onderwerps-classificatie
- **NEN-ISO 27001** voor informatie-beveiliging fractie-portaal
- **NORA** voor inter-bestuurlijke koppeling
- **OPRL** referentie-architectuur Raadsinformatie
- **Kiesraad-protocollen** voor verkiezingsuitslag-import (sZNL2-formaat)
- **Algemene wet bestuursrecht (Awb)** voor termijnen schriftelijke vragen (analoog aan bestuurlijke verzoeken)

## Cross-app

- **decidesk base**: levert basismodel voor raadsperiode, gemeente, persoon-register
- **decidesk motie-amendement-administratie**: gebruikt FractieLidmaatschap-snapshot voor stemresultaat-correctheid; SchriftelijkeVraag kan opvolging zijn van aangenomen motie
- **decidesk commissievergaderingen**: gebruikt evenredigheidsberekening voor commissie-samenstelling; commissielid-rol koppelt aan fractie-rol
- **decidesk besluitvorming-workflow**: schriftelijke vragen kunnen leiden tot raadsvoorstellen; raadsvoorstellen worden door fracties beoordeeld
- **docudesk**: antwoorden van het college worden PDF-gerendeerd en gearchiveerd; concept-vragen worden samenwerkings-documenten binnen fractie
- **openconnector**: synchronisatie met Kiesraad voor verkiezingsuitslagen (sZNL2-format), met landelijke partij-registers, met KvK voor partij-rechtspersoon-controle
- **opencatalogi**: publicatie van openbaar fractie-register, nevenfuncties-register, en schriftelijke-vragen-archief als publieke catalogus
- **openregister**: alle entiteiten leven op openregister schemas; nevenfuncties-historie als immutable log
- **mydash**: dashboards voor griffie met antwoord-termijn-bewaking, fractie-activiteit, fractie-vergoeding-besteding, afsplitsings-statistiek
- **opentalk**: communicatie binnen fractie via beveiligde chat-kanalen, mogelijk gekoppeld aan fractie-portaal
- **openklant**: koppeling tussen vragen van inwoners en schriftelijke vragen die daaruit voortvloeien
- **openbuilt**: fractie-portaal kan als low-code applicatie worden uitgebreid met fractie-specifieke workflows

## Target Users

- **Raadsleden** raadplegen eigen fractie-historie, dienen schriftelijke vragen in, registreren afsplitsing
- **Fractievoorzitters** coördineren standpunten binnen fractie, vertegenwoordigen fractie in seniorenconvent, bewaken fractie-vergoeding
- **Fractie-ondersteuners** (vaak parttime medewerkers betaald uit fractie-vergoeding) bereiden vragen voor en bewaken termijnen
- **Griffie en griffier** registreren wijzigingen, beheren register, publiceren naar portaal, bewaken antwoord-termijnen
- **Collegeleden en portefeuillehouders** beantwoorden schriftelijke vragen binnen termijn
- **Ambtelijke ondersteuning** stelt concept-antwoorden op namens portefeuillehouder
- **Burgers en kiezers** raadplegen publiek fractie-overzicht voor democratische transparantie
- **Journalisten en lokale media** volgen fractie-dynamiek en publiceren over afsplitsingen
- **Politicologen en onderzoekers** raadplegen historische fractie-samenstellingen voor analyses
- **Kiesraad** levert verkiezingsuitslagen die als basis dienen voor initiële fractie-vorming
- **Provincie en BZK** in toezichthoudende rol bij integriteits-kwesties of bestuurlijke problemen
