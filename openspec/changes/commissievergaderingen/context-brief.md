---
status: draft
app: decidiq
spec: commissievergaderingen
target_users: raadsleden, commissieleden, voorzitters, griffie, ambtelijke ondersteuning, college
depends_on:
  - decidesk-base
references:
  - Gemeentewet artikel 82-84 (raadscommissies)
  - https://www.raadsleden.nl/onderwerpen/raadscommissies
  - https://lokaleregelgeving.overheid.nl/ (verordening op raadscommissies)
  - https://standaarden.overheid.nl/owms
  - Reglement van Orde gemeenteraad (per gemeente)
---

# Commissievergaderingen

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Vergaderingen filter "Commissie" + Fracties & Organen > Commissies / split

**Rationale:** A meeting type + an organ definition  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Vrijwel elke Nederlandse gemeenteraad werkt met raadscommissies als voorbereidende organen op de plenaire raadsvergadering. Veelvoorkomende commissies zijn de audit-commissie (financiën en rekenkamer), commissie ruimte (ruimtelijke ordening, wonen, mobiliteit), commissie sociaal (zorg, jeugd, participatie), en commissie bestuur (algemeen bestuur, veiligheid, dienstverlening). De commissies hebben geen besluitvormende bevoegdheid maar geven advies aan de raad over voorliggende voorstellen, voeren technische beraadslagingen, en horen burgers en organisaties via inspraak. Een gemeente kent typisch 3 tot 6 commissies die elk maandelijks vergaderen — voor de griffie een aanzienlijke planningslast, en voor commissieleden een complex puzzelstuk van agenda's, dossiers en belangenverstrengelings-meldingen. Daarnaast worden in toenemende mate raadsbrede thema-bijeenkomsten en informele beeldvormende sessies onder de commissie-vlag georganiseerd, wat aparte planning-, presentie- en publicatie-regels vraagt.

Deze spec breidt decidiq uit met een eersteklas commissie-model dat sub-vergaderingen voor commissies beheert, vaste samenstelling per commissie (leden, plaatsvervangers, voorzitter, griffier) bijhoudt, het adviestraject naar de raadsvergadering automatiseert, en belangenverstrengelings-declaraties per agendapunt verplicht stelt conform artikel 28 Gemeentewet. Daarnaast ondersteunt de spec besloten zittingen met aparte toegangsregels, openbare verslaglegging met audio/videoregistratie waar gemeenten dat al doen, en inspraak-aanmelding voor burgers via een publiek portaal. Het sluit aan op de bredere besluitvormings-workflow en garandeert dat commissieadvies traceerbaar terechtkomt bij het agendapunt in de plenaire raadsvergadering.

De ontwerpkeuzes adresseren expliciet drie pijnpunten die uit interviews met griffies naar voren komen. Ten eerste de hoge handmatige last bij wisseling van plaatsvervangers: een fractie-voorzitter die op het laatste moment iemand vervangt voor commissie-deelname moet nu vaak per e-mail aan de griffier doorgeven wie er komt, waarna de griffier handmatig de stemrecht-status omklapt. In deze spec verloopt dit via een fractie-portaal-actie die direct presentie, agenda-toegang en stemrecht synchroniseert. Ten tweede de zwakke link tussen commissieadvies en plenaire besluitvorming: in legacy-systemen verdwijnt het commissieadvies vaak in een PDF-bijlage waar de raadsleden niet doorklikken. De spec maakt het advies een eersteklas annotatie op het plenaire agendapunt met fractie-standpunten als gestructureerde data, niet als losse tekst. Ten derde de privacy-paradox bij inspraak: burgers willen inspreken maar niet altijd hun adres en telefoonnummer publiek hebben — de InspraakAanmelding scheidt contactgegevens (intern) van inspraak-onderwerp en spreker-naam (publiek) automatisch. Voor besloten zittingen zoals personeels-aangelegenheden en grond-aankopen wordt een aparte audit-track gevoerd die alleen via dual-control geopend kan worden, zodat de Woo-toets achteraf altijd reproduceerbaar is.

Een bewuste keuze in deze spec is om verschillende commissie-typen via één Commissie-schema te modelleren in plaats van aparte schemas voor elk type (audit, ruimte, sociaal, etc.). Dit volgt het principe dat elke gemeente eigen commissie-samenstelling kiest binnen de wettelijke kaders en dat de portefeuille-scope een attribute is, geen schema-eigenschap. Daarmee is de spec direct geschikt voor gemeenten met klassieke vakcommissies, gemeenten met thema-commissies, en gemeenten die werken met een enkele oordeelsvormende commissie waar alle voorstellen langs gaan. Tegelijk faciliteert de spec experimenten met nieuwere vormen zoals burger-jury's (tijdelijke commissies waar gelote burgers naast raadsleden zitting hebben) door het type-veld uit te breiden zonder het schema te wijzigen. De koppeling met de Verordening op de raadscommissies (REQ-013) garandeert dat elke wijziging in de configuratie van een commissie traceerbaar is naar een formeel raadsbesluit, wat juridische bezwaren tegen commissie-besluiten ondervangt en bestuursrechters in beroep-procedures de nodige feitelijke onderbouwing biedt.

## Data Model

**Commissie** is een schema met velden: naam, type (`vast`, `tijdelijk`, `ad-hoc`), portefeuille-scope (TOOI-categorieën), voorzitter (raadslid-koppeling), plaatsvervangend-voorzitter, griffier (medewerker-koppeling), vergader-frequentie (`maandelijks`, `tweewekelijks`, `op-afroep`), vaste-vergaderdag, vergader-tijdstip, vergader-locatie, instellings-datum, instellings-besluit (raadsbesluit-koppeling), opheffings-datum, openbaarheids-default (`openbaar`, `besloten`).

**CommissieLidmaatschap** is een schema met velden: commissie-koppeling, raadslid-koppeling, fractie (gesnapshot), rol (`lid`, `plaatsvervanger`, `voorzitter`, `griffier`, `adviseur`), begin-datum, eind-datum, beëindigings-reden.

**CommissieVergadering** is een schema met velden: commissie-koppeling, vergader-datum, vergader-tijd-start, vergader-tijd-eind, locatie, openbaar (`ja`, `besloten`, `gedeeltelijk`), status (`gepland`, `agenda-vastgesteld`, `actief`, `geschorst`, `gesloten`, `geannuleerd`), audio-opname-url, video-opname-url, conceptverslag (rich text), definitief-verslag, presentielijst.

**CommissieAgendapunt** is een schema met velden: commissie-vergadering-koppeling, volgnummer, titel, type (`mededeling`, `bespreekstuk`, `hamerstuk`, `advies-aan-raad`, `inspraak`, `besloten-onderdeel`), beoogd-raadsvoorstel (koppeling naar raadsvoorstel dat naar plenaire raad gaat), portefeuillehouder, behandel-duur-geschat, bijlagen.

**CommissieAdvies** is een schema met velden: agendapunt-koppeling, advies-strekking (`positief`, `negatief`, `verdeeld`, `geen-advies`, `verzoek-tot-aanpassing`), advies-tekst (rich text), stemverhouding-samenvatting, fractie-standpunten (lijst van fractie + standpunt), inspraak-samenvatting.

**BelangenverstrengelingDeclaratie** is een schema met velden: commissielid-koppeling, agendapunt-koppeling, soort (`financieel-belang`, `aandeelhouderschap`, `bestuursfunctie`, `familierelatie`, `eerder-betrokken`, `geen`), beschrijving, gevolg (`onthoudt-zich-van-stemming`, `verlaat-vergadering`, `meldt-maar-blijft`), declaratie-datum.

**InspraakAanmelding** is een schema met velden: commissie-vergadering-koppeling, agendapunt-koppeling, naam-spreker, organisatie, contactgegevens (intern), onderwerp (publiek), spreektijd-aanvraag, status (`aangemeld`, `goedgekeurd`, `afgewezen`, `gesproken`, `niet-verschenen`), bijdrage-tekst (optionele schriftelijke variant), woon-of-werk-plaats (verplicht bij representativiteit-toets).

**Presentielijst** is een schema met velden: commissie-vergadering-koppeling, lid-koppeling, aanwezig (`aanwezig`, `afwezig-met-bericht`, `afwezig-zonder-bericht`, `plaatsvervangen-door`), aankomst-tijd, vertrek-tijd, presentievergoeding-uitbetaald (boolean).

## Requirements

### REQ-001: Commissie instellen op basis van raadsbesluit

**GIVEN** de raad heeft een commissie ingesteld via een formeel raadsbesluit
**WHEN** de griffier de commissie aanmaakt
**THEN** wordt de commissie gekoppeld aan het instellings-besluit en is niet wijzigbaar zonder verwijzing naar een wijzigingsbesluit
**AND** verschijnt de commissie in het publieke register van bestuurlijke organen

### REQ-002: Samenstelling beheren met fractie-evenredigheid

**GIVEN** een commissie heeft vaste samenstelling en een fractie wijzigt qua omvang
**WHEN** de griffier het aantal commissiezetels herziet
**THEN** wordt de evenredige verdeling per fractie berekend (D'Hondt-systeem of restzetels-methode per Reglement van Orde)
**AND** kan elke fractie haar eigen commissieleden aanwijzen
**AND** wordt een gewijzigde-samenstelling-melding vastgelegd met ingangsdatum

### REQ-003: Plaatsvervanging bij afwezigheid

**GIVEN** een commissielid kan niet aanwezig zijn bij een vergadering
**WHEN** het lid zich afmeldt en een plaatsvervanger aanwijst
**THEN** ontvangt de plaatsvervanger automatisch agenda en dossier
**AND** wordt op de presentielijst de plaatsvervanger zichtbaar
**AND** kan de plaatsvervanger meestemmen in adviesvorming

### REQ-004: Belangenverstrengelings-declaratie per agendapunt

**GIVEN** een commissielid is geagendeerd om over een onderwerp te beraadslagen
**WHEN** het lid niet eerder een declaratie heeft afgegeven en het agendapunt wordt geopend
**THEN** wordt het lid verplicht een BelangenverstrengelingDeclaratie in te vullen (ook als die `geen` is)
**AND** wordt bij `aandeelhouderschap` of `familierelatie` automatisch de griffier en voorzitter geattendeerd
**AND** kan het lid zich onthouden van beraadslaging zonder verlies van presentievergoeding

### REQ-005: Besloten zitting met aparte toegangscontrole

**GIVEN** een agendapunt is gemarkeerd als besloten (bv personeels-aangelegenheid, grond-aankoop met onderhandelingspositie)
**WHEN** de vergadering bij dit punt aanvangt
**THEN** wordt het publieke portaal en de video-livestream onderbroken
**AND** krijgen alleen commissieleden, voorzitter, griffier en expliciet uitgenodigde adviseurs toegang tot stukken
**AND** wordt een aparte besloten-zittings-notulen-track aangemaakt die niet via reguliere publicatie-flow gaat

### REQ-006: Adviesvorming met fractie-standpunten

**GIVEN** een commissie heeft een bespreekstuk behandeld en gaat advies geven
**WHEN** de voorzitter de adviesvorming afsluit
**THEN** worden per fractie de standpunten genoteerd (`voor`, `tegen`, `verdeeld`, `geen-mening`)
**AND** wordt een CommissieAdvies opgesteld dat automatisch wordt gekoppeld aan het beoogd-raadsvoorstel
**AND** verschijnt het advies in de plenaire raadsagenda bij het agendapunt

### REQ-007: Inspraak-aanmelding via publiek portaal

**GIVEN** een commissievergadering staat gepland en een burger wil inspreken
**WHEN** de burger zich via het publieke portaal aanmeldt voor inspraak op een specifiek agendapunt
**THEN** ontvangt de griffier een aanvraag met contactgegevens en onderwerp
**AND** kan de griffier de aanvraag goedkeuren, afwijzen (met reden), of doorverwijzen
**AND** ontvangt de burger bevestiging met spreektijd-toewijzing
**AND** sluit aanmelding 24 uur voor vergadering automatisch (configurabel per commissie)

### REQ-008: Verslag publicatie naar griffieportaal

**GIVEN** een commissievergadering is afgesloten en het conceptverslag is goedgekeurd in de volgende vergadering
**WHEN** de griffier het verslag als definitief markeert
**THEN** wordt het verslag gepubliceerd op het griffieportaal met agenda, presentielijst, adviezen, inspraak-overzicht
**AND** wordt audio/video-opname gekoppeld met tijdcodes per agendapunt (deeplink)
**AND** wordt OWMS-metadata gegenereerd voor zoekmachine-indexering

### REQ-009: Conflict-of-interest rapportage per periode

**GIVEN** de rekenkamer of een journalist wil zicht op gemelde belangen
**WHEN** de gebruiker de rapportage `belangen-overzicht` opvraagt voor een periode
**THEN** wordt een overzicht gegenereerd van alle declaraties per commissielid, gesorteerd op type belang
**AND** worden alleen openbare delen getoond (besloten-zittings-declaraties blijven afgeschermd)

### REQ-010: Commissie-naar-raad doorgeleiding

**GIVEN** een agendapunt is in commissie voorbespreken en het commissieadvies is opgesteld
**WHEN** het agendapunt op de plenaire raadsagenda wordt geplaatst
**THEN** verschijnt automatisch een verwijzing naar het commissieadvies, de stemverhouding-samenvatting, en eventuele inspraak-bijdragen
**AND** kunnen raadsleden uit de commissie hun voorbereidings-notities meenemen naar de plenaire vergadering

### REQ-011: Thema-bijeenkomst zonder advies-vorming

**GIVEN** de griffie organiseert een raadsbrede beeldvormende sessie over een toekomst-thema
**WHEN** de bijeenkomst als CommissieVergadering met type `thema-bijeenkomst` wordt gemarkeerd
**THEN** vervalt de verplichte adviesvorming-stap
**AND** kunnen alle raadsleden (niet alleen commissieleden) zich aanmelden
**AND** wordt een lichtgewicht verslag opgesteld zonder formele stemverhoudingen

### REQ-012: Audit-trail voor besloten zittings-stukken

**GIVEN** een besloten-zittings-document wordt openbaar gemaakt na verloop van geheimhoudings-termijn (Woo-toets)
**WHEN** een ambtenaar of journalist het document opvraagt
**THEN** wordt de complete toegang-historie tijdens de geheimhoudings-periode getoond (wie heeft wanneer ingezien)
**AND** wordt de dual-control-handeling voor opheffing van geheimhouding aantoonbaar vastgelegd

### REQ-013: Commissie-verordening synchronisatie

**GIVEN** de raad wijzigt de Verordening op de raadscommissies (bv aantal commissies, samenstelling, spreektijd)
**WHEN** de griffier de gewijzigde verordening importeert
**THEN** wordt het Commissie-record gekoppeld aan de nieuwe verordening-versie via een onveranderlijke verwijzing
**AND** worden eerdere vergaderingen onder oude verordening getoond met de op-dat-moment-geldende regels
**AND** wordt elke gemeente-specifieke afwijking (configurabele parameters) gevalideerd tegen de verordening-tekst

## Standards

- **Gemeentewet** artikelen 82, 83, 84 (instelling en samenstelling raadscommissies), artikel 28 (verbod schijn van belangenverstrengeling), artikel 86 (geheimhouding)
- **Reglement van Orde** van de individuele gemeenteraad
- **Verordening op raadscommissies** (gemeentelijke verordening, verplicht door Gemeentewet artikel 82 lid 1)
- **Wet openbaarheid van bestuur** opvolger **Wet open overheid** (Woo) categorie 6 (vergaderstukken bestuursorganen)
- **Wet hergebruik overheidsinformatie (Who)** voor open data publicatie
- **OWMS** versie 4 voor publicatie van vergaderstukken en verslagen
- **TOOI** voor onderwerp-classificatie en bestuursorgaan-aanduiding
- **WCAG 2.1 AA** voor publiek portaal, conform Tijdelijk besluit digitale toegankelijkheid
- **AVG** voor verwerking persoonsgegevens van insprekers en commissieleden
- **Archiefwet 1995** — bewaartermijn besluitvormingsdossiers conform selectielijst gemeenten 2020
- **Wet bescherming persoonsgegevens** (vervangen door AVG, maar relevant voor historische dossiers)
- **NEN-ISO 16175** voor digitaal records management
- **NORA** principes voor inter-bestuurlijke samenwerking
- **OPRL** referentie-architectuur Raadsinformatie van VNG

## Cross-app

- **decidiq base**: levert basismodel vergadering, agendapunt, raadslid, fractie, presentielijst
- **decidiq motie-amendement-administratie**: amendementen kunnen voortkomen uit commissieadvies; commissieadvies bepaalt vaak indieningsstrategie van moties
- **decidiq fractievoorzitter-fractie-koppeling**: levert evenredigheidsberekening bij commissie-samenstelling en plaatsvervangers-management via fractie-portaal
- **decidiq besluitvorming-workflow**: commissieadvies wordt onderdeel van besluitvormings-flow en zichtbaar bij plenaire agendapunten
- **docudesk**: vergaderstukken en verslagen worden PDF-gerendeerd, eIDAS-handtekening op definitieve verslagen, geheimhouding-watermark op besloten stukken
- **opentalk**: livestream van openbare commissievergaderingen, met tijdcode-koppeling per agendapunt voor deeplinks; automatische onderbreking bij besloten zitting
- **opencatalogi**: publicatie van publieke commissie-stukken als openbare catalogus met TOOI-metadata
- **openconnector**: synchronisatie naar iBabs of Notubiz voor gemeenten in transitie; ook synchronisatie met agenda-systemen (Outlook, Google Calendar)
- **openregister**: alle entiteiten leven op openregister schemas; audit-trail voor toegang besloten stukken
- **mydash**: dashboards voor griffie met commissie-activiteit per periode, attendance-trends, advies-naar-raad ratio
- **openklant**: insprekers kunnen via openklant zich aanmelden en hun contactgegevens beheren met privacy-controle
- **docudesk eIDAS**: handtekeningen op vastgestelde commissie-verslagen voor formele juridische status

## Target Users

- **Commissieleden** raadplegen agenda, dossier, geven advies, registreren belangenverstrengeling, bereiden plenaire raadsvergadering voor
- **Commissievoorzitter** leidt vergadering, sluit beraadslaging, formuleert advies, bewaakt spreektijd en orde
- **Griffier en griffie-medewerkers** plannen vergaderingen, stellen agenda op, registreren presentie, publiceren verslag, bewaken termijnen
- **Raadsleden niet in commissie** raadplegen commissieadvies ter voorbereiding plenaire raadsvergadering en kunnen agendapunten als toehoorder volgen
- **Burgers en organisaties** melden inspraak aan en volgen openbare vergaderingen via livestream en archief
- **Ambtelijke ondersteuning portefeuillehouder** bereidt technische antwoorden voor commissievragen voor en stelt bestuursrapportages op
- **Collegeleden** beantwoorden vragen tijdens commissievergaderingen, presenteren collegevoorstellen
- **Externe adviseurs** (juridisch, financieel, technisch) kunnen geagendeerd worden voor toelichting onder NDA
- **Rekenkamer** raadpleegt commissie-archief voor onderzoek naar kwaliteit van besluitvorming
- **Journalisten en publiek** volgen openbare zittingen via livestream en gepubliceerde verslagen
- **Plaatsvervangers** ontvangen tijdig agenda en dossier wanneer zij worden ingezet
- **Bestuursrechter** raadpleegt commissie-advies bij beroep tegen besluiten die door de commissie zijn voorbesproken
- **Provincie** in toezichthoudende rol bij signalen over kwaliteit raadsbesluitvorming
