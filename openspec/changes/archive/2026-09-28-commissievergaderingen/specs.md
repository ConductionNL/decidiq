# Specs — Commissievergaderingen

## REQ-CVG-001: Commissie instellen op basis van raadsbesluit

**GIVEN** de raad heeft een commissie ingesteld via een formeel raadsbesluit
**WHEN** de griffier via de API een Commissie-record aanmaakt met `instellings-besluit`-referentie
**THEN** wordt de Commissie gekoppeld aan het instellings-besluit via `instellings_besluit_id`
**AND** is de Commissie nadien onwijzigbaar zonder verwijzing naar een wijzigings-raadsbesluit
**AND** verschijnt de Commissie in het publieke register van bestuurlijke organen (TOOI-export)

## REQ-CVG-002: Samenstelling beheren met fractie-evenredigheid

**GIVEN** een Commissie heeft vaste samenstelling en fractie-verdeling wijzigt in de raad
**WHEN** de griffier de Commissie-samenstelling herziet via `PUT /commissions/{id}/composition`
**THEN** wordt per fractie het aantal commissiezetels berekend volgens het Reglement van Orde van de gemeente
**AND** kan elke fractie-voorzitter via fractie-portaal haar commissieleden aanwijzen
**AND** wordt de gewijzigde-samenstelling-melding vastgelegd met ingangsdatum en vorig-besluit-referentie

## REQ-CVG-003: Plaatsvervanging bij afwezigheid

**GIVEN** een CommissieLid kan niet aanwezig zijn bij een CommissieVergadering
**WHEN** het lid zich afmeldt via `/meetings/{meeting_id}/absences` en een plaatsvervanger uit zijn fractie-groep aanwijst
**THEN** ontvangt de plaatsvervanger automatisch agenda en dossier via e-mail-notificatie
**AND** wordt de Presentielijst bijgewerkt: `plaatsvervanger_door`-veld toont plaatsvervanger plaats van afgemeld lid
**AND** kan de plaatsvervanger meestemmen in adviesvorming en gaat zijn stemming naar fractie-count (niet dubbel)

## REQ-CVG-004: Belangenverstrengelingsdeclaratie per agendapunt

**GIVEN** een CommissieVergadering start en een CommissieAgendapunt wordt geopend
**WHEN** voor elk CommissieLid (rol = lid, plaatsvervanger, voorzitter) nog geen BelangenverstrengelingDeclaratie voor dit agendapunt bestaat
**THEN** wordt per lid een declaratie-record aangemaakt met `soort: 'nog-in-te-vullen'`
**AND** wordt lid via notificatie gevraagd declaratie in te vullen (JSON POST naar `/declarations/{id}`)
**AND** kan lid waarde wijzigen naar financieel-belang, aandeelhouderschap, bestuursfunctie, familierelatie, eerder-betrokken, of geen
**AND** bij `soort !== 'geen'` krijgen voorzitter + griffier automatisch alert met lid + soort + gevolg

## REQ-CVG-005: Gevolg van belangenverstrengeling (onthoudt-zich, verlaat, meldt-maar-blijft)

**GIVEN** een CommissieLid heeft BelangenverstrengelingDeclaratie met `soort !== 'geen'` ingediend
**WHEN** de voorzitter de gevolg-optie kiest (onthoudt-zich-van-stemming, verlaat-vergadering, meldt-maar-blijft)
**THEN** wordt gevolg opgeslagen op declaratie-record
**AND** bij `onthoudt-zich`: lid blijft op presentielijst maar krijgt geen stemrecht op dit agendapunt
**AND** bij `verlaat`: lid wordt van presentielijst afgehaald (vertrek-time geregistreerd)
**AND** bij `meldt-maar-blijft`: notatie wordt in verslag opgenomen, lid behoudt stemrecht

## REQ-CVG-006: Adviesvorming met fractie-standpunten

**GIVEN** een CommissieVergadering behandelt een CommissieAgendapunt van type `advies-aan-raad`
**WHEN** de voorzitter de beraadslaging afsluit via `POST /agenda-items/{id}/finalize-advice`
**THEN** wordt voor elke fractie met leden in commissie gevraagd hun standpunt (voor, tegen, verdeeld, geen-mening)
**AND** wordt CommissieAdvies aangemaakt met:
  - `advies-strekking`: samengestelde aanbeveling (positief, negatief, verdeeld, geen-advies, verzoek-tot-aanpassing)
  - `fractie-standpunten`: array van {fractie-naam, standpunt}
  - `advies-tekst`: rich-text samenvatting van beraadslaging
  - `stemverhouding-samenvatting`: bijv "3 voor, 1 tegen, 1 verdeeld"
**AND** wordt CommissieAdvies automatisch gekoppeld aan het beoogd-raadsvoorstel (plenaire AgendaItem) via relatie

## REQ-CVG-007: Commissieadvies op plenaire agendapunt

**GIVEN** een plenaire raadsvergadering bereidt een agendapunt voor dat eerder in commissie is voorbesproken
**WHEN** griffier of raadslid het CommissieAdvies opvraagt via `/agenda-items/{id}/commission-advice`
**THEN** verschijnt het CommissieAdvies in volledige vorm:
  - Titel vorige agendapunt (commissie)
  - Advies-strekking
  - Fractie-standpunten gegroepeerd per fractie
  - Advies-tekst
  - Link naar commissie-verslag
**AND** kan raadslid dit advies gebruiken ter voorbereiding plenaire beraadslaging

## REQ-CVG-008: Thema-bijeenkomst zonder advies-vorming

**GIVEN** de griffie organiseert een raadsbrede beeldvormende sessie (geen advies)
**WHEN** CommissieVergadering wordt gemarkeerd met `type: 'thema-bijeenkomst'`
**THEN** vervalt de verplichte adviesvorming-stap
**AND** kunnen alle raadsleden (niet alleen commissieleden) zich aanmelden via `/public-api/registration`
**AND** wordt presentielijst en lichtgewicht verslag opgesteld (geen stemverhoudingen)

## REQ-CVG-009: Inspraak-aanmelding via publiek portaal

**GIVEN** een CommissieVergadering staat gepland en burger wil inspreken op specifiek agendapunt
**WHEN** burger zich aanmeldt via publiek portaal `POST /public-api/commissions/{id}/inspraak`
**THEN** wordt InspraakAanmelding aangemaakt met twee veldgroepen:
  - `contactgegevens` (naam, email, telefoon, adres — alleen voor griffie zichtbaar)
  - `onderwerp` (spreker-naam (kan pseudoniem), organisatie, spreektijd-aanvraag — publiek)
**AND** griffier ziet aanvraag in griffie-portaal en kan goedkeuren, afwijzen (met reden), of doorverwijzen
**AND** burger ontvangt bevestiging met spreektijd-toewijzing
**AND** sluit aanmelding automatisch 24 uur voor vergadering (konfigurabel per commissie via `inspraak-deadline-uren`)

## REQ-CVG-010: Besloten zitting met aparte toegangscontrole

**GIVEN** een CommissieAgendapunt is gemarkeerd als type `besloten-onderdeel` (personeels-aangelegenheid, grond-aankoop)
**WHEN** CommissieVergadering bij dit punt aanvangt
**THEN** wordt het publieke portaal en eventuele livestream onderbroken
**AND** krijgen alleen Commissieleden, voorzitter, griffier en expliciet uitgenodigde adviseurs (via lijst) toegang tot agendapunt-stukken
**AND** wordt een aparte besloten-zittings-notulen-track aangemaakt in CommissieVergadering (veld `besloten_verslag`)
**AND** audittrace worden geregistreerd: wie heeft wanneer besloten-stukken ingezien

## REQ-CVG-011: Verslag publicatie naar griffieportaal

**GIVEN** een CommissieVergadering is afgesloten en conceptverslag is goedgekeurd in volgende vergadering
**WHEN** griffier het verslag via API als definitief markeert (`PUT /meetings/{id}/minutes` met `status: 'approved'`)
**THEN** wordt verslag gepubliceerd op griffieportaal met:
  - Volledige agendalist
  - Presentielijst (met afwezigen-reden waar getoond)
  - CommissieAdviezen (per agendapunt waar advies gegeven)
  - InspraakAanmeldingen (publieke delen: spreker-naam, organisatie, onderwerp)
  - Definitief verslag (rich text)
**AND** wordt OWMS-metadata gegenereerd voor zoekmachine-indexering en opendata-export (Via Who/Woo)

## REQ-CVG-012: Conflict-of-interest rapportage per periode

**GIVEN** de rekenkamer, journalist, of raadslid wil zicht op gemelde belangen
**WHEN** gebruiker via griffie-portaal rapportage `belangen-overzicht` opvraagt voor periode (bv "januari-maart 2026")
**THEN** wordt overzicht gegenereerd van alle BelangenverstrengelingDeclaraties per CommissieLid in die periode, gegroepeerd op soort (financieel-belang, aandeelhouderschap, etc.)
**AND** worden alleen openbare delen getoond (besloten-zittings-declaraties worden afgeschermd)
**AND** is rapport exporteerbaar als CSV/PDF voor archief/audit

## REQ-CVG-013: Commissie-verordening synchronisatie

**GIVEN** raad wijzigt Verordening op de Raadscommissies (bv aantal commissies, samenstelling, spreektijd-limits)
**WHEN** griffier de gewijzigde verordening importeert via import-wizard
**THEN** wordt Commissie-record gekoppeld aan verordening-versie via immutable-referentie
**AND** worden Commissie-wijzigingen (samenstelling, type, frequentie) gevalideerd tegen verordening-tekst (logische checks via rules-engine)
**AND** eerdere CommissieVergaderingen tonen met op-dat-moment-geldende verordening-regels (bv "spreektijd destijds 3min, nu 5min")
**AND** wordt elke gemeente-specifieke afwijking gemarkeerd in audit-trail

---

## Implementation Notes

- REQ-CVG-004 en REQ-CVG-005 implementeren artikel 28 Gemeentewet (verhindering belangenverstrengeling)
- REQ-CVG-009 implementeert privacy (contactgegevens ↔ publieke inspraak) conform AVG
- REQ-CVG-010 implementeert artikel 86 Gemeentewet (geheimhouding besloten zitting) + audit-trail voor Woo-toetsing
- REQ-CVG-011 en REQ-CVG-012 implementeren Woo-transparantie + Archiefwet-naleving (bewaartermijn)
- REQ-CVG-013 implementeert traceerbaarheid naar juridisch-bindend raadsbesluit (verdedigingsstrategie tegen bestuursrechter-bezwaar)

## Data Integrity Constraints

- `CommissieLidmaatschap.fractie` is immutable snapshot; wijziging vereist eindigen-date + nieuw record
- `BelangenverstrengelingDeclaratie` per lid-agendapunt combinatie is uniek; create-if-not-exists pattern
- `CommissieAdvies` kan alleen ontstaan na beraadslaging (CommissieAgendapunt.status: 'behandeld')
- `Presentielijst` één per CommissieVergadering; wijzigingen via transactie-pattern (versioning)
- `InspraakAanmelding` onveranderlijk na `status: 'goedgekeurd'`; geen edit-after-approval
