---
status: draft
app: decidesk
spec: motie-amendement-administratie
target_users: gemeenteraad, raadsleden, griffie, fractie-ondersteuners, college van B&W
depends_on:
  - decidesk-base
  - decidesk-besluitvorming-workflow
references:
  - https://www.raadsleden.nl/
  - https://decentrale.regelgeving.overheid.nl/
  - https://lokaleregelgeving.overheid.nl/
  - https://standaarden.overheid.nl/owms
  - Gemeentewet artikel 147a (recht van amendement), 169 (verantwoording college)
---

# Motie en Amendement Administratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Moties > Moties + amendementen / Moties

**Rationale:** Dutch parliamentary amendment flow  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Nederlandse gemeenteraden gebruiken moties en amendementen als de twee meest gebruikte politieke instrumenten om het college te sturen, raadsvoorstellen te wijzigen en politieke standpunten formeel vast te leggen. Een gemeente van gemiddelde omvang verwerkt 80 tot 200 moties per jaar en 30 tot 80 amendementen, vrijwel allemaal handmatig bijgehouden in Word-documenten, losse spreadsheets of het concept-verslag van de griffie. Hierdoor verdwijnt de uitvoeringsstatus van aangenomen moties uit beeld, kan een raadslid niet snel terugvinden hoe een fractie eerder gestemd heeft, en moet de griffier elke vier jaar bij installatie van een nieuwe raad een handmatige inventarisatie maken van openstaande moties. Bovendien is het zogenaamde "motie-bingo" — een fenomeen waarbij collegeleden tijdens een vergadering moties bij voorbaat overnemen zonder dat er een degelijk uitvoerings-spoor wordt aangelegd — een hardnekkig probleem dat alleen oplost als de toezegging als verplichte uitvoerings-update wordt vastgelegd met een terugkoppelings-deadline.

Deze spec definieert een geïntegreerde administratie waarin moties en amendementen vanaf indiening tot afhandeling worden gevolgd, met volledige hoofdelijke stemming-uitslag per raadslid, koppeling aan agendapunten en raadsvoorstellen, uitvoeringsstatus die door het college wordt bijgehouden, automatische publicatie naar het griffieportaal voor inwoners, en een doorzoekbaar historisch archief dat ook actief blijft bij raadswisselingen. Het sluit aan op de besluitvormings-workflow uit decidesk-base, maakt de scheiding tussen motie (politieke opdracht aan college) en amendement (wijziging van raadsvoorstel) expliciet, en levert een transparante stemverklaring per raadslid conform openbaarheidsverplichtingen van de Gemeentewet.

De spec is ontworpen met expliciete aandacht voor twee terugkerende failure modes die in markt-research zijn geconstateerd. Ten eerste het verlies van fractie-context bij historisch stemgedrag: een raadslid dat in periode A stemde namens partij X en in periode B namens partij Y krijgt in primitieve systemen alle stemmen toegerekend aan de huidige fractie, wat onderzoek naar consistent stemgedrag onmogelijk maakt. Het Stemresultaat-model maakt dit onmogelijk door de fractie als onveranderlijke snapshot vast te leggen op het stemmoment. Ten tweede de drempel voor portefeuillehouders om uitvoerings-updates te schrijven: door updates te koppelen aan de motie-tijdlijn en automatische rappels te genereren bij 90 dagen stilte wordt het bijhouden van uitvoering een dagelijks-aanwezig signaal in plaats van een vergeten verplichting. Daarnaast ondersteunt de spec drie publicatie-routes (intern-griffie, raadsleden-portaal, publiek inwoners-portaal) zodat conceptmoties bij indiening niet meteen openbaar zijn maar wel toegankelijk voor coalitie-overleg en fractie-coördinatie.

De spec is bewust beperkt tot moties en amendementen — de twee meest gebruikte instrumenten — en raakt expliciet niet aan instrumenten zoals interpellatie, motie van wantrouwen of het indienen van een raadsvoorstel door een raadslid. Die instrumenten zijn juridisch en procedureel anders (interpellatie vereist agenda-aanvraag vooraf, motie van wantrouwen heeft constitutionele gevolgen, raadsvoorstel-indiening door raadslid vraagt een eigenstandige raadsbesluitvormings-cyclus) en horen in eigen specs thuis. Door de scope strak te houden blijft de spec implementeerbaar binnen één release-cyclus en kan eenvoudig getest worden tegen het reglement van orde van een referentie-gemeente (in dit geval als pilot gepland met de gemeente Zeist conform de bestaande softwarecatalogus-relatie). De spec rekent erop dat een vergadering en stemmoment al door decidesk-base wordt aangeleverd en dat het fractie-register reeds via de aparte fractievoorzitter-fractie-koppeling-spec is opgezet; zonder die twee dependencies is een betekenisvolle implementatie van moties en amendementen niet mogelijk.

## Data Model

**Motie** is een schema met velden: titel, indiener (fractie + primaire raadslid), mede-indieners (raadsleden), constaterende-overwegingen (rich text), draagt-college-op (rich text dispositief), agendapunt-koppeling, vergadering-koppeling, indienings-datum, status (`ingediend`, `behandeling`, `aangenomen`, `verworpen`, `aangehouden`, `ingetrokken`, `overgenomen-door-college`), stemming-type (`hoofdelijk`, `bij-zitten-en-opstaan`, `unaniem`), uitvoering-status (`niet-van-toepassing`, `in-behandeling`, `uitgevoerd`, `afgewezen`, `gedeeltelijk-uitgevoerd`), uitvoerings-deadline, portefeuillehouder, publicatie-datum.

**Amendement** is een schema met velden: titel, indiener (fractie + primaire raadslid), mede-indieners, raadsvoorstel-koppeling (verplicht — een amendement bestaat niet zonder raadsvoorstel), originele-tekst (welk artikel/lid wordt gewijzigd), gewijzigde-tekst (de voorgestelde nieuwe formulering), toelichting, status (`ingediend`, `aangenomen`, `verworpen`, `aangehouden`, `ingetrokken`, `overgenomen-door-college`), stemming-type, indienings-datum.

**Stemresultaat** is een schema met velden: motie-of-amendement-koppeling, raadslid-koppeling, fractie-koppeling (gesnapshot voor historische correctheid bij fractie-wisseling), stem (`voor`, `tegen`, `onthouden`, `afwezig`, `niet-deelgenomen`), stemverklaring (optionele toelichting), stemming-datum.

**UitvoeringsUpdate** is een schema met velden: motie-koppeling, datum, status-wijziging, toelichting (rich text), bijlagen (collegebrief, voortgangsrapportage), update-door-gebruiker.

Een MotieGroep optionele koppeling maakt het mogelijk samenhangende moties (bv allemaal over woningbouw in één vergadering) als cluster te tonen.

## Requirements

### REQ-001: Motie indienen door fractie

**GIVEN** een raadslid is ingelogd als lid van een actieve fractie
**AND** er is een actieve raadsvergadering met status `voorbereiding` of `actief`
**WHEN** het raadslid een nieuwe motie indient met titel, dispositief, gekoppeld agendapunt en mede-indieners
**THEN** de motie krijgt status `ingediend`, een uniek nummer van het formaat `M-{jaar}-{volgnummer}`, en wordt zichtbaar voor de griffie
**AND** de mede-indieners ontvangen een notificatie en moeten mede-indiening bevestigen voor publicatie

### REQ-002: Amendement koppelen aan raadsvoorstel

**GIVEN** een raadsvoorstel is geagendeerd voor een vergadering
**WHEN** een raadslid een amendement indient en de originele tekst plus gewijzigde tekst opgeeft
**THEN** het systeem genereert automatisch een diff-weergave die het verschil tussen origineel en gewijzigd toont
**AND** weigert opslaan als de originele tekst niet letterlijk in het gekoppelde raadsvoorstel voorkomt
**AND** kent nummer `A-{jaar}-{volgnummer}` toe

### REQ-003: Hoofdelijke stemming registreren per raadslid

**GIVEN** een motie of amendement staat op de agenda en wordt in stemming gebracht
**WHEN** de griffier hoofdelijke stemming start
**THEN** het systeem toont een matrix van alle aanwezige raadsleden met stemknoppen voor `voor`, `tegen`, `onthouden`
**AND** afwezige raadsleden worden automatisch op `afwezig` gezet op basis van presentielijst
**AND** een raadslid kan via eigen device stemmen wanneer raadszaal-stemtablet beschikbaar is
**AND** de uitslag wordt pas vastgelegd als de griffier expliciet bevestigt

### REQ-004: Stemresultaat als snapshot van fractie

**GIVEN** een raadslid wisselt van fractie (afsplitsing of partijwisseling)
**WHEN** historische moties uit een vorige periode worden opgevraagd
**THEN** het stemresultaat toont de fractie waarin het raadslid lid was op de stemdatum, niet de huidige fractie
**AND** dit gegeven is onveranderlijk vastgelegd op het Stemresultaat-record

### REQ-005: Uitvoeringsstatus bijhouden door college

**GIVEN** een motie heeft status `aangenomen`
**WHEN** een collegelid of ambtenaar een UitvoeringsUpdate toevoegt
**THEN** de uitvoering-status van de motie wordt bijgewerkt
**AND** een tijdlijn van alle updates blijft zichtbaar
**AND** moties zonder update gedurende meer dan 90 dagen verschijnen op een rappel-lijst voor de portefeuillehouder

### REQ-006: Doorzoekbare historie inclusief vorige raadsperiode

**GIVEN** een gebruiker zoekt op trefwoord in moties-archief
**WHEN** de zoekopdracht wordt uitgevoerd
**THEN** worden alle moties getoond ongeacht raadsperiode, gesorteerd op relevantie en datum
**AND** kan filters worden toegepast op fractie, portefeuillehouder, onderwerp, status, jaar
**AND** een raadslid kan filteren op alle moties waarop zijn fractie heeft gestemd

### REQ-007: Publicatie naar griffieportaal

**GIVEN** een motie of amendement heeft status `aangenomen` of `verworpen`
**WHEN** de griffier publicatie activeert
**THEN** wordt een publiek-toegankelijke pagina gegenereerd onder `/griffie/moties/{nummer}` met titel, indieners, stemresultaat, status, uitvoering
**AND** wordt voldaan aan WCAG AA toegankelijkheidseisen
**AND** verschijnt het document in OWMS metadata zodat openbare zoekmachines het indexeren

### REQ-008: Overgenomen-door-college afhandeling

**GIVEN** een motie ligt voor en het college doet tijdens behandeling een toezegging
**WHEN** de indiener de motie als `overgenomen-door-college` markeert
**THEN** vervalt de stemming
**AND** wordt de toezegging als verplichte uitvoeringsupdate geregistreerd
**AND** krijgt de motie automatisch een tracking-deadline van 180 dagen voor terugkoppeling aan de raad

### REQ-009: Raadsperiode-rapportage

**GIVEN** een raadsperiode eindigt (verkiezingen aanstaande)
**WHEN** de griffier het rapport `eindrapport-raadsperiode` opvraagt
**THEN** wordt een PDF gegenereerd met alle moties van de periode, uitvoeringsstatus, openstaande moties (over te dragen aan nieuwe raad), en stemgedrag-analyse per fractie

### REQ-010: Stemverklaring bij afwijkend stemgedrag

**GIVEN** een raadslid stemt anders dan zijn fractievoorzitter heeft geadviseerd (mits fractie-stemadvies bekend)
**WHEN** de stem wordt geregistreerd
**THEN** wordt het raadslid uitgenodigd een korte stemverklaring toe te voegen
**AND** wordt in de publicatie dit afwijkend stemgedrag zichtbaar gemarkeerd

### REQ-011: Aanhouden en hervatten van motie

**GIVEN** een motie wordt op verzoek van de indiener aangehouden voor een latere vergadering
**WHEN** de griffier de motie als `aangehouden` markeert met beoogde-volgende-vergadering
**THEN** wordt de motie automatisch op de agenda van die vergadering geplaatst als bespreekstuk
**AND** behoudt de motie haar oorspronkelijke nummer en indieners
**AND** verschijnt de motie in het overzicht "aangehouden moties" zodat de fractie het niet vergeet

### REQ-012: Bulk-import bij raadswissel

**GIVEN** een nieuwe raadsperiode begint en moties uit de vorige periode met openstaande uitvoering moeten worden overgedragen
**WHEN** de griffier de overgangs-import uitvoert
**THEN** worden alle moties met uitvoering-status `in-behandeling` of `gedeeltelijk-uitgevoerd` zichtbaar gemarkeerd als "overgedragen vorige raadsperiode"
**AND** krijgen ze een nieuwe portefeuillehouder-koppeling op basis van de nieuwe collegesamenstelling
**AND** ontvangt elke fractie-voorzitter van de nieuwe raad een overzicht van overgedragen moties van haar voorgangers

### REQ-013: Motie-bingo detectie

**GIVEN** een collegelid neemt een motie over zonder concrete toezegging
**WHEN** de griffier de overname registreert maar geen concrete uitvoerings-actie kan koppelen
**THEN** geeft het systeem een waarschuwing en vereist een verplichte toezeggings-tekst
**AND** verschijnt de motie op een aparte "vage-overname" lijst voor de raad tot een concrete actie geregistreerd is

## Standards

- **Gemeentewet** artikelen 147a (amendement), 169 (collegeverantwoording), 16 (huishoudelijk reglement)
- **OWMS** (Overheid.nl Web Metadata Standaard) versie 4 voor publicatie metadata van alle vergaderstukken
- **DUTO** (Duurzaam Toegankelijk Overheidsinformatie) voor archivering en duurzame ontsluiting
- **Archiefwet 1995** — bewaartermijn raadsbesluiten is permanent, raadsstukken volgens selectielijst gemeenten 2020
- **TOOI** (Thesaurus en Ontologie voor Officiële Informatie) voor onderwerps-classificatie van moties
- **TOOI/Bestuursorgaan** voor uniforme aanduiding van fracties en organen
- **Reglement van Orde** van de individuele gemeenteraad (per gemeente specifiek)
- **WCAG 2.1 AA** voor publieke portaal-pagina's, conform Tijdelijk besluit digitale toegankelijkheid overheid
- **Woo** (Wet open overheid) — proactieve openbaarmaking categorie 6 raadsstukken
- **Wpg** (Wet politiegegevens) bij motie-onderwerpen rond openbare orde
- **AVG** voor naam-genoemde stemverklaringen (vooraf opgenomen verwerkingsgrondslag)
- **NORA** (Nederlandse Overheid Referentie Architectuur) principes voor interoperabiliteit
- **OPRL** (Open Portaal voor Raadsleden, vanuit BZK) als aansluit-referentie
- **STUF-LVO** voor uitwisseling met landelijke kennisbanken (waar relevant)

## Cross-app

- **decidesk besluitvorming-workflow**: levert vergaderingen, agendapunten en raadsvoorstellen waar moties en amendementen aan koppelen; ontvangt aangenomen-amendement-doorvoer voor automatische integratie van wijzigingen in raadsvoorstel-tekst
- **decidesk fractievoorzitter-fractie-koppeling**: levert het fractie-register en raadslid-fractie-historie nodig voor de Stemresultaat-snapshot; levert ook de schriftelijke-vragen-flow waar opvolgvragen op aangenomen moties via lopen
- **decidesk commissievergaderingen**: amendementen kunnen via commissieadvies pre-geformuleerd worden; commissie-besluitvormings-advies wordt zichtbaar bij plenaire stemming
- **docudesk**: PDF-rendering van moties en amendementen voor archivering, eIDAS-niveau SES of AdES voor authentieke besluit-pdf
- **openconnector**: synchronisatie naar iBabs of GemeenteOplossingen voor gemeenten in transitiefase, met bi-directionele sync zodat raadsleden zowel oude als nieuwe tool kunnen gebruiken
- **opencatalogi**: publicatie van openbare moties als publieke catalogus met TOOI-trefwoorden en OWMS-metadata
- **openregister**: alle data leeft op openregister schemas; gebruikt audit-trail van openregister voor immutable stem-historie
- **mydash**: dashboard widgets voor openstaande moties per portefeuillehouder, fractie-stemgedrag-grafieken, raadsperiode-statistieken
- **opentalk**: livestream-deeplinks naar stemmoment per motie in vergader-opname
- **openklant**: koppeling tussen schriftelijke vraag van inwoner en eventuele motie die daaruit voortvloeit

## Target Users

- **Raadsleden** dienen moties en amendementen in, stemmen, en raadplegen historie van eigen fractie; bereiken via vragen-tijd en moties hun politieke programma
- **Griffier en griffie-medewerkers** beheren de vergaderingen, registreren hoofdelijke stemmingen, publiceren naar portaal, bewaken termijnen, leveren rapporten aan de raad
- **Collegeleden en portefeuillehouders** zien hun openstaande moties, voegen uitvoeringsupdates toe, bewaken rappels, bereiden mondelinge overname-voorstellen voor
- **Ambtenaren ondersteunend aan college** stellen voortgangsrapportages op namens portefeuillehouder en houden de uitvoerings-status feitelijk bij
- **Fractie-ondersteuners** zoeken stemgedrag-historie en bereiden nieuwe moties voor; coördineren coalitie-overleg over indiening
- **Inwoners en journalisten** raadplegen openbaar portaal voor democratische verantwoording, deelbare URL per motie
- **Rekenkamer** gebruikt historie voor onderzoek naar uitvoering van raadsbesluiten, signaleert structurele non-uitvoering
- **Provincie en BZK** in toezichthoudende rol bij signalen van bestuurlijke problemen, opvragen overzicht via Woo-verzoek
- **Politicologen en onderzoekers** gebruiken open data export voor analyses van besluitvormings-patronen
- **Lokale media** abonneren op RSS/Atom feeds per portefeuille of fractie voor nieuws-monitoring
- **Bestuursrechter** raadpleegt de motie-historie bij beroep tegen besluiten waarop een motie van toepassing was
